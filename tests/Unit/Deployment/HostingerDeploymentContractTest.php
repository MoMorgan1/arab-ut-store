<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function deploymentFile(string $path): string
{
    $contents = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$path);

    expect($contents)->not->toBeFalse();

    return $contents;
}

function workflowStepRun(string $workflowPath, string $stepName): string
{
    $workflow = Yaml::parseFile(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$workflowPath);

    foreach ($workflow['jobs']['mariadb-schema']['steps'] as $step) {
        if (($step['name'] ?? null) === $stepName) {
            return $step['run'];
        }
    }

    throw new RuntimeException("Workflow step [{$stepName}] was not found.");
}

it('publishes one tested sha-bound release artifact from the main CI workflow', function () {
    $workflow = deploymentFile('.github/workflows/tests.yml');

    expect($workflow)
        ->toContain('hostinger-release-${{ github.sha }}')
        ->toContain('public/build')
        ->toContain('release.tar.gz')
        ->toContain('if-no-files-found: error');
});

it('runs chat lifecycle and concurrency integration coverage in the MariaDB test command', function () {
    $run = workflowStepRun(
        '.github/workflows/tests.yml',
        'Run focused domain and cart tests on MariaDB',
    );
    $arguments = preg_split('/\s+/', trim($run));

    expect($arguments)
        ->toContain('php')
        ->toContain('vendor/bin/pest')
        ->toContain('--configuration')
        ->toContain('phpunit.mariadb.xml')
        ->toContain('tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php')
        ->toContain('tests/Integration/ChatConversationConcurrencyTest.php');
});

it('deploys production only after a successful main push test run', function () {
    $workflowPath = '.github/workflows/deploy-production.yml';

    expect(file_exists(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$workflowPath))->toBeTrue();

    $workflow = deploymentFile($workflowPath);

    expect($workflow)
        ->toContain('workflow_run:')
        ->toContain("workflows: ['tests']")
        ->toContain("github.event.workflow_run.conclusion == 'success'")
        ->toContain("github.event.workflow_run.head_branch == 'main'")
        ->toContain("github.event.workflow_run.event == 'push'")
        ->toContain('environment:')
        ->toContain('name: production')
        ->toContain('PRODUCTION_URL')
        ->toContain('HOSTINGER_KNOWN_HOSTS')
        ->not->toContain('STAGING_URL')
        ->not->toContain('StrictHostKeyChecking=no');
});

it('installs a release atomically and restores the prior release on a failed health check', function () {
    $script = deploymentFile('deploy/hostinger-release.sh');

    expect($script)
        ->toContain('shared/.env')
        ->toContain('composer install --no-dev')
        ->toContain('artisan migrate --force')
        ->toContain('artisan config:cache')
        ->toContain('public_html')
        ->toContain('previous_release')
        ->toContain('health_url')
        ->toContain('curl --fail');
});

it('avoids the PHP exec fallback disabled by Hostinger when linking public storage', function () {
    $script = deploymentFile('deploy/hostinger-release.sh');

    expect($script)
        ->toContain('ln -sfn "$shared/storage/app/public" "$release/public/storage"')
        ->not->toContain('artisan storage:link');
});

it('refreshes every configured display currency before activating a release', function () {
    $script = deploymentFile('deploy/hostinger-release.sh');
    $refresh = 'php artisan currency:refresh-display-rates';

    expect($script)
        ->toContain($refresh)
        ->and(strpos($script, $refresh))->toBeLessThan(strpos($script, 'mv -Tf "$next_link" "$current"'));
});

it('restores prior code without a schema rollback when the release had no pending migrations', function () {
    $result = runFailedHealthDeployment(pendingMigrations: false);

    expect($result['exitCode'])->not->toBe(0)
        ->and($result['log'])->toContain('php artisan migrate:status --pending=10 --no-ansi')
        ->and($result['log'])->toContain('php artisan migrate --force')
        ->and($result['log'])->not->toContain('php artisan migrate:rollback --force')
        ->and($result['log'])->toContain("ln -s {$result['previousRelease']} {$result['rollbackLink']}")
        ->and($result['stderr'])->toContain('prior release was restored');
});

it('rolls back the newly applied migration batch before restoring prior code after failed health', function () {
    $result = runFailedHealthDeployment(pendingMigrations: true);
    $migration = strpos($result['log'], 'php artisan migrate --force');
    $health = strpos($result['log'], 'curl ');
    $schemaRollback = strpos($result['log'], 'php artisan migrate:rollback --force');
    $codeRollback = strpos($result['log'], "ln -s {$result['previousRelease']} {$result['rollbackLink']}");

    expect($result['exitCode'])->not->toBe(0)
        ->and($migration)->not->toBeFalse()
        ->and($health)->not->toBeFalse()
        ->and($schemaRollback)->not->toBeFalse()
        ->and($codeRollback)->not->toBeFalse()
        ->and($migration)->toBeLessThan($health)
        ->and($health)->toBeLessThan($schemaRollback)
        ->and($schemaRollback)->toBeLessThan($codeRollback);
});

it('refuses to restore prior code when the release migration batch cannot roll back', function () {
    $result = runFailedHealthDeployment(pendingMigrations: true, rollbackFails: true);

    expect($result['exitCode'])->not->toBe(0)
        ->and($result['log'])->toContain('php artisan migrate:rollback --force')
        ->and($result['log'])->not->toContain("ln -s {$result['previousRelease']} {$result['rollbackLink']}")
        ->and($result['stderr'])->toContain('schema rollback failed')
        ->and($result['stderr'])->toContain('current release was left active');
});

/**
 * @return array{exitCode: int, log: string, stderr: string, previousRelease: string, rollbackLink: string}
 */
function runFailedHealthDeployment(bool $pendingMigrations, bool $rollbackFails = false): array
{
    $repository = dirname(__DIR__, 3);
    $fixture = $repository.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'
        .DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'hostinger-deploy-'.bin2hex(random_bytes(8));
    $fakeBin = $fixture.DIRECTORY_SEPARATOR.'bin';
    $deployRoot = $fixture.DIRECTORY_SEPARATOR.'deploy root';
    $shared = $deployRoot.DIRECTORY_SEPARATOR.'shared';
    $previousRelease = $deployRoot.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'previous release';
    $archive = $fixture.DIRECTORY_SEPARATOR.'release archive.tar.gz';
    $log = $fixture.DIRECTORY_SEPARATOR.'commands.log';

    mkdir($fakeBin, 0777, true);
    mkdir($shared, 0777, true);
    mkdir($previousRelease, 0777, true);
    file_put_contents($shared.DIRECTORY_SEPARATOR.'.env', 'APP_ENV=production');
    file_put_contents($archive, 'fixture archive');
    file_put_contents($log, '');
    installDeploymentFakeCommands($fakeBin);

    $process = new Process([
        deploymentBashExecutable(),
        '-c',
        'export PATH="$1:/usr/local/bin:/usr/bin:/bin"; shift; exec bash "$@"',
        'deployment-test',
        deploymentShellPath($fakeBin),
        deploymentShellPath($repository.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'hostinger-release.sh'),
        deploymentShellPath($deployRoot),
        deploymentShellPath($archive),
        'new release',
        'https://health.example.test',
    ], $repository, [
        'DEPLOY_TEST_LOG' => deploymentShellPath($log),
        'FAKE_PREVIOUS_RELEASE' => deploymentShellPath($previousRelease),
        'FAKE_PENDING_MIGRATIONS' => $pendingMigrations ? '1' : '0',
        'FAKE_ROLLBACK_FAILS' => $rollbackFails ? '1' : '0',
    ], timeout: 20);

    try {
        $process->run();

        return [
            'exitCode' => $process->getExitCode() ?? -1,
            'log' => (string) file_get_contents($log),
            'stderr' => $process->getErrorOutput(),
            'previousRelease' => deploymentShellPath($previousRelease),
            'rollbackLink' => deploymentShellPath($deployRoot.DIRECTORY_SEPARATOR.'.rollback-new release'),
        ];
    } finally {
        deleteDeploymentFixture($fixture);
    }
}

function installDeploymentFakeCommands(string $fakeBin): void
{
    $commands = [
        'composer' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "composer $*" >> "$DEPLOY_TEST_LOG"
BASH,
        'php' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "php $*" >> "$DEPLOY_TEST_LOG"
if [[ "$*" == 'artisan migrate:status --pending=10 --no-ansi' ]]; then
    [[ "$FAKE_PENDING_MIGRATIONS" == '1' ]] && exit 10
    exit 0
fi
if [[ "$*" == 'artisan migrate:rollback --force' && "$FAKE_ROLLBACK_FAILS" == '1' ]]; then
    exit 23
fi
exit 0
BASH,
        'tar' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "tar $*" >> "$DEPLOY_TEST_LOG"
mkdir -p "$4/public"
: > "$4/artisan"
BASH,
        'curl' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "curl $*" >> "$DEPLOY_TEST_LOG"
exit 22
BASH,
        'sleep' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "sleep $*" >> "$DEPLOY_TEST_LOG"
BASH,
        'readlink' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$FAKE_PREVIOUS_RELEASE"
BASH,
        'ln' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "ln $*" >> "$DEPLOY_TEST_LOG"
BASH,
        'mv' => <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "mv $*" >> "$DEPLOY_TEST_LOG"
BASH,
    ];

    foreach ($commands as $name => $contents) {
        $path = $fakeBin.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents."\n");
        chmod($path, 0755);
    }
}

function deploymentBashExecutable(): string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return '/bin/bash';
    }

    $path = 'C:\\Program Files\\Git\\bin\\bash.exe';

    if (! file_exists($path)) {
        throw new RuntimeException('Git Bash is required for deployment contract tests on Windows.');
    }

    return $path;
}

function deploymentShellPath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);

    if (preg_match('/\A([A-Za-z]):\/(.*)\z/', $normalized, $matches) === 1) {
        return '/'.strtolower($matches[1]).'/'.$matches[2];
    }

    return $normalized;
}

function deleteDeploymentFixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

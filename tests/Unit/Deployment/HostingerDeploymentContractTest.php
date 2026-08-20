<?php

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

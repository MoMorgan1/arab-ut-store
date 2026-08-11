<?php

function deploymentFile(string $path): string
{
    $contents = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$path);

    expect($contents)->not->toBeFalse();

    return $contents;
}

it('publishes one tested sha-bound release artifact from the main CI workflow', function () {
    $workflow = deploymentFile('.github/workflows/tests.yml');

    expect($workflow)
        ->toContain('hostinger-release-${{ github.sha }}')
        ->toContain('public/build')
        ->toContain('release.tar.gz')
        ->toContain('if-no-files-found: error');
});

it('deploys staging only after a successful main push test run', function () {
    $workflow = deploymentFile('.github/workflows/deploy-staging.yml');

    expect($workflow)
        ->toContain('workflow_run:')
        ->toContain("workflows: ['tests']")
        ->toContain("github.event.workflow_run.conclusion == 'success'")
        ->toContain("github.event.workflow_run.head_branch == 'main'")
        ->toContain("github.event.workflow_run.event == 'push'")
        ->toContain('environment:')
        ->toContain('name: staging')
        ->toContain('HOSTINGER_KNOWN_HOSTS')
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

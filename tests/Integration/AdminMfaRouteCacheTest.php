<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\PrivateNoStore;
use Symfony\Component\Process\Process;

test('production route cache boots with hardened Fortify MFA management routes', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $routeCachePattern = $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'routes-*.php';
    $removeRouteCache = static function () use ($routeCachePattern): void {
        foreach (glob($routeCachePattern) ?: [] as $routeCachePath) {
            unlink($routeCachePath);
        }
    };

    $removeRouteCache();

    try {
        $cache = new Process([PHP_BINARY, 'artisan', 'route:cache'], $projectRoot);
        $cache->setTimeout(60);
        $cache->mustRun();

        $routeList = new Process([
            PHP_BINARY,
            'artisan',
            'route:list',
            '--name=two-factor.enable',
            '--json',
        ], $projectRoot);
        $routeList->setTimeout(60);
        $routeList->run();

        expect($routeList->isSuccessful())
            ->toBeTrue($routeList->getErrorOutput());

        $routes = json_decode($routeList->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($routes)->toHaveCount(1)
            ->and($routes[0]['name'])->toBe('two-factor.enable')
            ->and($routes[0]['middleware'])->toContain(
                PrivateNoStore::class,
                EnsureActiveUser::class,
                'throttle:two-factor-management',
            );
    } finally {
        $removeRouteCache();
    }
});

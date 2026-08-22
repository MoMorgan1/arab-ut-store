<?php

use App\Console\Commands\InspectAgentStreamingHttp;
use Illuminate\Support\Facades\Artisan;

test('command outputs whitelisted format and succeeds when environment is ready', function () {
    InspectAgentStreamingHttp::$capabilityResolver = fn (): array => [
        'handler' => 'stream',
        'curl_version' => '8.5.0',
        'http_wrapper' => true,
        'https_wrapper' => true,
        'allow_url_fopen' => true,
        'connect_timeout' => 5,
        'read_timeout' => 2,
        'total_timeout' => 30,
        'passed' => true,
    ];

    try {
        $this->artisan('agent:inspect-streaming-http')
            ->assertExitCode(0)
            ->expectsOutput('handler: stream')
            ->expectsOutput('curl: 8.5.0')
            ->expectsOutput('http_wrapper: true')
            ->expectsOutput('https_wrapper: true')
            ->expectsOutput('allow_url_fopen: true')
            ->expectsOutput('connect_timeout: 5')
            ->expectsOutput('read_timeout: 2')
            ->expectsOutput('total_timeout: 30')
            ->expectsOutput('verdict: pass');
    } finally {
        InspectAgentStreamingHttp::$capabilityResolver = null;
    }
});

test('command fails with exit code 1 when streaming capabilities are absent', function () {
    InspectAgentStreamingHttp::$capabilityResolver = fn (): array => [
        'handler' => 'stream',
        'curl_version' => 'none',
        'http_wrapper' => false,
        'https_wrapper' => false,
        'allow_url_fopen' => false,
        'connect_timeout' => 5,
        'read_timeout' => 2,
        'total_timeout' => 30,
        'passed' => false,
    ];

    try {
        $this->artisan('agent:inspect-streaming-http')
            ->assertExitCode(1)
            ->expectsOutput('verdict: fail');
    } finally {
        InspectAgentStreamingHttp::$capabilityResolver = null;
    }
});

test('command output contains only whitelisted items and no sensitive keys or urls', function () {
    config()->set('services.openai.key', 'SUPER-SECRET-API-KEY-VALUE');
    config()->set('services.openai.base_url', 'https://api.openai.com/v1');

    InspectAgentStreamingHttp::$capabilityResolver = fn (): array => [
        'handler' => 'stream',
        'curl_version' => '8.5.0',
        'http_wrapper' => true,
        'https_wrapper' => true,
        'allow_url_fopen' => true,
        'connect_timeout' => 5,
        'read_timeout' => 2,
        'total_timeout' => 30,
        'passed' => true,
    ];

    try {
        $exitCode = Artisan::call('agent:inspect-streaming-http');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain('SUPER-SECRET-API-KEY-VALUE')
            ->and($output)->not->toContain('https://api.openai.com/v1')
            ->and($output)->not->toContain('Bearer')
            ->and($output)->not->toContain('OPENAI_API_KEY');

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $allowedPrefixes = [
            'handler:',
            'curl:',
            'http_wrapper:',
            'https_wrapper:',
            'allow_url_fopen:',
            'connect_timeout:',
            'read_timeout:',
            'total_timeout:',
            'verdict:',
        ];

        foreach ($lines as $line) {
            $hasAllowedPrefix = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    $hasAllowedPrefix = true;
                    break;
                }
            }
            expect($hasAllowedPrefix)->toBeTrue();
        }
    } finally {
        InspectAgentStreamingHttp::$capabilityResolver = null;
    }
});

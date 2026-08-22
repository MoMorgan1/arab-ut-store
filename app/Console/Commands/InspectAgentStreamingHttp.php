<?php

namespace App\Console\Commands;

use App\Support\AI\AgentRuntimeConfig;
use Closure;
use Illuminate\Console\Command;
use Throwable;

final class InspectAgentStreamingHttp extends Command
{
    protected $signature = 'agent:inspect-streaming-http';

    protected $description = 'Inspect streaming HTTP capability and runtime timeouts.';

    /**
     * @var (Closure(): array{
     *     handler: string,
     *     curl_version: string,
     *     http_wrapper: bool,
     *     https_wrapper: bool,
     *     allow_url_fopen: bool,
     *     connect_timeout: int,
     *     read_timeout: int,
     *     total_timeout: int,
     *     passed: bool,
     * })|null
     */
    public static ?Closure $capabilityResolver = null;

    public function handle(AgentRuntimeConfig $config): int
    {
        $data = self::$capabilityResolver !== null
            ? (self::$capabilityResolver)()
            : $this->inspect($config);

        $this->line('handler: '.$data['handler']);
        $this->line('curl: '.$data['curl_version']);
        $this->line('http_wrapper: '.($data['http_wrapper'] ? 'true' : 'false'));
        $this->line('https_wrapper: '.($data['https_wrapper'] ? 'true' : 'false'));
        $this->line('allow_url_fopen: '.($data['allow_url_fopen'] ? 'true' : 'false'));
        $this->line('connect_timeout: '.$data['connect_timeout']);
        $this->line('read_timeout: '.$data['read_timeout']);
        $this->line('total_timeout: '.$data['total_timeout']);
        $this->line('verdict: '.($data['passed'] ? 'pass' : 'fail'));

        return $data['passed'] ? 0 : 1;
    }

    /**
     * @return array{
     *     handler: string,
     *     curl_version: string,
     *     http_wrapper: bool,
     *     https_wrapper: bool,
     *     allow_url_fopen: bool,
     *     connect_timeout: int,
     *     read_timeout: int,
     *     total_timeout: int,
     *     passed: bool,
     * }
     */
    private function inspect(AgentRuntimeConfig $config): array
    {
        $wrappers = stream_get_wrappers();
        $httpWrapper = in_array('http', $wrappers, true);
        $httpsWrapper = in_array('https', $wrappers, true);
        $allowUrlFopen = (bool) ini_get('allow_url_fopen');

        $curlVersion = 'none';
        if (function_exists('curl_version')) {
            $curlInfo = curl_version();
            $curlVersion = is_array($curlInfo) ? ($curlInfo['version'] ?? 'none') : 'none';
        }

        $connectTimeout = 0;
        $readTimeout = 0;
        $totalTimeout = 0;
        $configValid = true;

        try {
            $connectTimeout = $config->connectTimeoutSeconds();
            $readTimeout = $config->streamReadTimeoutSeconds();
            $totalTimeout = $config->requestTimeoutSeconds();
        } catch (Throwable) {
            $configValid = false;
        }

        $passed = $httpWrapper && $httpsWrapper && $allowUrlFopen && $configValid;

        return [
            'handler' => 'stream',
            'curl_version' => $curlVersion,
            'http_wrapper' => $httpWrapper,
            'https_wrapper' => $httpsWrapper,
            'allow_url_fopen' => $allowUrlFopen,
            'connect_timeout' => $connectTimeout,
            'read_timeout' => $readTimeout,
            'total_timeout' => $totalTimeout,
            'passed' => $passed,
        ];
    }
}

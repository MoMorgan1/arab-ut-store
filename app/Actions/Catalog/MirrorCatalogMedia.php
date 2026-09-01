<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use finfo;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MirrorCatalogMedia
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, list<array{path:string,alt_ar:?string,alt_en:?string,sort_order:int}>|null>
     */
    public function prepare(array $products): array
    {
        /** @var array<string, bool> $uniqueUrls */
        $uniqueUrls = [];

        foreach ($products as $product) {
            foreach (($product['media'] ?? []) as $item) {
                if (isset($item['url']) && is_string($item['url'])) {
                    $uniqueUrls[$item['url']] = true;
                }
            }
        }

        $mirroredMap = $this->mirrorUrls(array_keys($uniqueUrls));

        $prepared = [];

        foreach ($products as $product) {
            $productMedia = [];
            $hasFailedMedia = false;

            foreach (($product['media'] ?? []) as $item) {
                $url = (string) ($item['url'] ?? '');
                $path = $mirroredMap[$url] ?? null;

                if ($path === null) {
                    $hasFailedMedia = true;
                    break;
                }

                $productMedia[] = [
                    'path' => $path,
                    'alt_ar' => $item['alt']['ar'] ?? null,
                    'alt_en' => $item['alt']['en'] ?? null,
                    'sort_order' => (int) ($item['sortOrder'] ?? 0),
                ];
            }

            $prepared[$product['externalId']] = $hasFailedMedia ? null : $productMedia;
        }

        return $prepared;
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, string|null>
     */
    private function mirrorUrls(array $urls): array
    {
        /** @var array<string, string|null> $results */
        $results = [];

        /** @var array<string, string> $pending */
        $pending = [];

        foreach ($urls as $url) {
            if (! $this->urlIsAllowed($url)) {
                $results[$url] = null;

                continue;
            }

            $pending[$url] = $url;
        }

        for ($hop = 0; $hop <= 3 && $pending !== []; $hop++) {
            /** @var array<string, string> $currentHop */
            $currentHop = $pending;
            $pending = [];

            /** @var array<string, Response|Throwable> $responses */
            $responses = Http::pool(function (Pool $pool) use ($currentHop): array {
                $requests = [];

                foreach ($currentHop as $originalUrl => $currentUrl) {
                    $cacheKey = $this->cacheKey($currentUrl);
                    /** @var array{path: string, etag: ?string, last_modified: ?string}|null $cached */
                    $cached = Cache::get($cacheKey);

                    $headers = [];
                    if ($cached !== null && Storage::disk('public')->exists($cached['path'])) {
                        if (! empty($cached['etag'])) {
                            $headers['If-None-Match'] = $cached['etag'];
                        }
                        if (! empty($cached['last_modified'])) {
                            $headers['If-Modified-Since'] = $cached['last_modified'];
                        }
                    }

                    $requests[$originalUrl] = $pool->as($originalUrl)
                        ->accept('image/*')
                        ->connectTimeout(3)
                        ->timeout(8)
                        ->retry(times: 2, sleepMilliseconds: 100, when: fn ($exception) => $exception instanceof ConnectionException, throw: false)
                        ->withHeaders($headers)
                        ->withOptions(['allow_redirects' => false])
                        ->get($currentUrl);
                }

                return $requests;
            });

            foreach ($currentHop as $originalUrl => $currentUrl) {
                $response = $responses[$originalUrl] ?? null;

                if (! ($response instanceof Response)) {
                    $results[$originalUrl] = null;

                    continue;
                }

                if ($response->redirect()) {
                    if ($hop === 3) {
                        $results[$originalUrl] = null;

                        continue;
                    }

                    $location = $response->header('Location');

                    if ($location === '') {
                        $results[$originalUrl] = null;

                        continue;
                    }

                    $nextUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                    if (! $this->urlIsAllowed($nextUrl)) {
                        $results[$originalUrl] = null;

                        continue;
                    }

                    $pending[$originalUrl] = $nextUrl;

                    continue;
                }

                if ($response->status() === 304) {
                    $cacheKey = $this->cacheKey($currentUrl);
                    /** @var array{path: string, etag: ?string, last_modified: ?string}|null $cached */
                    $cached = Cache::get($cacheKey);

                    if ($cached !== null && Storage::disk('public')->exists($cached['path'])) {
                        $results[$originalUrl] = $cached['path'];

                        continue;
                    }
                }

                $body = $response->body();

                if (! $response->successful() || strlen($body) > self::MAX_BYTES) {
                    $results[$originalUrl] = null;

                    continue;
                }

                $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
                $extension = is_string($mime) ? (self::EXTENSIONS[$mime] ?? null) : null;

                if ($extension === null || ! $this->declaredTypeMatches($response->header('Content-Type'), $mime)) {
                    $results[$originalUrl] = null;

                    continue;
                }

                $path = 'catalog/'.hash('sha256', $body).'.'.$extension;

                if (! Storage::disk('public')->put($path, $body)) {
                    $results[$originalUrl] = null;

                    continue;
                }

                $cacheData = [
                    'path' => $path,
                    'etag' => $response->header('ETag') ?: null,
                    'last_modified' => $response->header('Last-Modified') ?: null,
                ];
                Cache::put($this->cacheKey($currentUrl), $cacheData, now()->addDays(7));
                if ($currentUrl !== $originalUrl) {
                    Cache::put($this->cacheKey($originalUrl), $cacheData, now()->addDays(7));
                }

                $results[$originalUrl] = $path;
            }
        }

        foreach ($pending as $originalUrl => $unused) {
            $results[$originalUrl] = null;
        }

        return $results;
    }

    private function cacheKey(string $url): string
    {
        return 'catalog_media:'.hash('sha256', $url);
    }

    private function urlIsAllowed(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $allowedHosts = config('services.n8n.catalog_media_hosts', []);

        return $scheme === 'https'
            && is_string($host)
            && in_array(strtolower($host), $allowedHosts, true);
    }

    private function declaredTypeMatches(string $contentType, string $detectedType): bool
    {
        return strtolower(trim(explode(';', $contentType)[0])) === $detectedType;
    }

    /**
     * @param  list<array{path:string,alt_ar:?string,alt_en:?string,sort_order:int}>|null  $prepared
     */
    public function apply(Product $product, ?array $prepared): void
    {
        if ($prepared === null) {
            return;
        }

        $product->media()->delete();

        foreach ($prepared as $media) {
            $product->media()->create(['disk' => 'public', ...$media]);
        }
    }
}

<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use finfo;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
        $prepared = [];

        foreach ($products as $product) {
            $prepared[$product['externalId']] = $this->prepareProduct($product['media']);
        }

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array{path:string,alt_ar:?string,alt_en:?string,sort_order:int}>|null
     */
    private function prepareProduct(array $media): ?array
    {
        $prepared = [];

        foreach ($media as $item) {
            $mirrored = $this->mirror($item);

            if ($mirrored === null) {
                return null;
            }

            $prepared[] = $mirrored;
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{path:string,alt_ar:?string,alt_en:?string,sort_order:int}|null
     */
    private function mirror(array $item): ?array
    {
        $url = $item['url'];

        if (! $this->urlIsAllowed($url)) {
            return null;
        }

        $response = $this->fetch($url);

        if ($response === null) {
            return null;
        }

        $body = $response->body();

        if (! $response->successful()
            || strlen($body) > self::MAX_BYTES
        ) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $extension = is_string($mime) ? (self::EXTENSIONS[$mime] ?? null) : null;

        if ($extension === null || ! $this->declaredTypeMatches($response->header('Content-Type'), $mime)) {
            return null;
        }

        $path = 'catalog/'.hash('sha256', $body).'.'.$extension;

        if (! Storage::disk('public')->put($path, $body)) {
            return null;
        }

        return [
            'path' => $path,
            'alt_ar' => $item['alt']['ar'],
            'alt_en' => $item['alt']['en'],
            'sort_order' => $item['sortOrder'],
        ];
    }

    private function fetch(string $url): ?Response
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (! $this->urlIsAllowed($currentUrl)) {
                return null;
            }

            try {
                $response = Http::accept('image/*')
                    ->connectTimeout(3)
                    ->timeout(8)
                    ->retry([100, 250], throw: false)
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);
            } catch (ConnectionException) {
                return null;
            }

            if (! $response->redirect()) {
                return $response;
            }

            $location = $response->header('Location');

            if ($redirects === 3 || $location === '') {
                return null;
            }

            $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        }

        return null;
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

<?php

namespace App\Actions\AI;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A short shelf of real SBC challenges the customer can pick from.
 *
 * "How much are the challenges?" has no single answer: SBC is a catalogue,
 * every challenge is priced on its own, and quoting one number would be wrong
 * for eleven others. So the assistant shows a few actual challenges and lets
 * the customer choose, rather than refusing or inventing a range.
 *
 * Only identity travels in the message — slug, title, image, link. Prices are
 * live data resolved when the shelf is rendered: chat history is permanent, and
 * a price frozen into it would be a lie the moment the catalogue moves.
 */
final readonly class BuildSbcSuggestions
{
    /** Enough to feel like a choice, few enough to swipe through. */
    private const LIMIT = 5;

    public function __construct(private StoreCatalogReader $catalog) {}

    /**
     * @return list<array{id: string, title: string, url: string, image: string}>
     */
    public function execute(string $locale): array
    {
        /** @var list<array{id: string, title: string, url: string, image: string}> */
        return Cache::remember(
            "chat.sbc-suggestions.{$locale}",
            300,
            fn (): array => $this->read($locale),
        );
    }

    /**
     * @return list<array{id: string, title: string, url: string, image: string}>
     */
    private function read(string $locale): array
    {
        try {
            $catalog = $this->catalog->category(
                ServiceType::Sbc,
                $locale,
                (string) config('store.default_display_currency'),
                'all',
                'recommended',
                '',
                1,
            );
        } catch (Throwable) {
            return [];
        }

        $items = [];

        foreach ($catalog['products'] as $product) {
            $item = $this->present($product);

            if ($item !== null) {
                $items[] = $item;
            }

            if (count($items) === self::LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{id: string, title: string, url: string, image: string}|null
     */
    private function present(array $product): ?array
    {
        $slug = $product['slug'] ?? null;
        $title = $product['name'] ?? null;
        $url = $product['url'] ?? null;
        $image = self::relativePath($product['image']['url'] ?? null);

        if (! is_string($slug) || $slug === ''
            || ! is_string($title) || $title === ''
            || ! is_string($url) || ! str_starts_with($url, '/')
            || $image === null) {
            return null;
        }

        return ['id' => $slug, 'title' => $title, 'url' => $url, 'image' => $image];
    }

    /**
     * Catalogue media is stored with an absolute URL. The shelf renders it as an
     * image tag, so anything that is not this storefront's own asset is dropped
     * rather than fetched.
     */
    private static function relativePath(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '' && str_starts_with($url, $appUrl.'/')) {
            return substr($url, strlen($appUrl));
        }

        return null;
    }
}

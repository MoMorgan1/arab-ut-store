<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * Server-rendered page metadata for the storefront.
 *
 * Social scrapers (WhatsApp, X, Facebook) and many crawlers never execute
 * JavaScript, so Open Graph tags injected by React arrive too late to be seen.
 * Controllers hand this to Inertia as the `seo` prop and `app.blade.php` writes
 * it into the initial HTML response.
 */
final readonly class StorePageSeo
{
    /**
     * @param  array{name: string, amountMinor: int, currency: string}|null  $offer
     *                                                                               Present only for product pages that have a real price.
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $image,
        public bool $isProduct = false,
        public ?array $offer = null,
    ) {}

    /**
     * The storefront-wide defaults, used by any page that has nothing better.
     */
    public static function default(?string $title = null): self
    {
        return new self(
            title: $title ?? (string) trans('store.seo_title'),
            description: (string) trans('store.seo_description'),
            image: self::defaultShareImage(),
        );
    }

    /**
     * A product page carrying the price that search results should advertise.
     *
     * Falls back to a non-product page when no price is known, because a
     * schema.org `Product` without an `offers` block is rejected by Google.
     */
    public static function product(
        string $name,
        ?string $description,
        ?string $image,
        ?int $amountMinor,
        ?string $currency,
    ): self {
        $hasOffer = $amountMinor !== null && $currency !== null;

        return new self(
            title: $name,
            description: $description !== null && $description !== ''
                ? $description
                : (string) trans('store.seo_description'),
            image: $image !== null && $image !== ''
                ? self::absolute($image)
                : self::defaultShareImage(),
            isProduct: $hasOffer,
            offer: $hasOffer
                ? ['name' => $name, 'amountMinor' => $amountMinor, 'currency' => $currency]
                : null,
        );
    }

    /**
     * Build product metadata from a presented catalog product.
     *
     * A product without a headline price advertises its cheapest variant,
     * matching the "from" price the storefront itself shows.
     *
     * @param  array<string, mixed>  $product
     */
    public static function fromCatalogProduct(array $product): self
    {
        $price = self::headlinePrice($product);

        return self::product(
            name: (string) ($product['name'] ?? ''),
            description: isset($product['description']) ? (string) $product['description'] : null,
            image: isset($product['image']['url']) ? (string) $product['image']['url'] : null,
            amountMinor: $price['amountMinor'] ?? null,
            currency: $price['currency'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{amountMinor: int|null, currency: string|null}
     */
    private static function headlinePrice(array $product): array
    {
        $candidates = [];

        if (isset($product['price']['amountMinor'], $product['price']['currency'])) {
            $candidates[] = $product['price'];
        }

        foreach ($product['variants'] ?? [] as $variant) {
            if (isset($variant['price']['amountMinor'], $variant['price']['currency'])) {
                $candidates[] = $variant['price'];
            }
        }

        if ($candidates === []) {
            return ['amountMinor' => null, 'currency' => null];
        }

        $cheapest = array_reduce(
            $candidates,
            fn (?array $carry, array $price): array => $carry === null
                || $price['amountMinor'] < $carry['amountMinor'] ? $price : $carry,
        );

        return [
            'amountMinor' => (int) $cheapest['amountMinor'],
            'currency' => (string) $cheapest['currency'],
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     image: string,
     *     type: string,
     *     schema: array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'type' => $this->isProduct ? 'product' : 'website',
            'schema' => $this->schema(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $brand = (string) trans('store.seo_brand');

        if (! $this->isProduct || $this->offer === null) {
            return [
                '@context' => 'https://schema.org',
                '@type' => 'OnlineStore',
                'name' => $brand,
                'url' => self::siteUrl(),
                'logo' => self::absolute((string) config('store.seo.logo')),
                'description' => (string) trans('store.seo_description'),
                'email' => (string) config('store.support.email'),
                'telephone' => '+966537998099',
                'identifier' => 'FL-621205220',
                'sameAs' => array_values(config('store.socials')),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->offer['name'],
            'description' => $this->description,
            'image' => $this->image,
            'brand' => ['@type' => 'Brand', 'name' => $brand],
            'offers' => [
                '@type' => 'Offer',
                'price' => self::price($this->offer['amountMinor']),
                'priceCurrency' => $this->offer['currency'],
                'availability' => 'https://schema.org/InStock',
                'seller' => ['@type' => 'Organization', 'name' => $brand],
            ],
        ];
    }

    /**
     * Minor units as the plain decimal string schema.org requires: no currency
     * symbol, no digit grouping, and no locale-specific numerals.
     */
    private static function price(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    /**
     * The social preview image every page falls back to.
     */
    private static function defaultShareImage(): string
    {
        return self::absolute((string) config('store.seo.share_image'));
    }

    /**
     * The public origin, taken from APP_URL so a domain change needs no code
     * edit. Scrapers resolve `og:image` on their own servers, so it must be
     * absolute rather than a site-relative path.
     */
    private static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : self::siteUrl().$url;
    }
}

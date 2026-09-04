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
     * @param  array{average: float|null, count: int}|null  $rating
     *                                                               Store rating summary; emitted only when `count > 0` and `average !== null`.
     * @param  list<array{name: string, body: string, rating: int, datePublished: string|null}>  $reviews
     *                                                                                                     Up to five comment-only reviews, nested under the rated node.
     * @param  list<array{question: string, answer: string}>  $faq
     *                                                              Question/answer pairs for the home-page `FAQPage` node.
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     *                                                               Absolute canonical URLs for the `BreadcrumbList` node.
     * @param  array{name: string, description: string|null}|null  $service
     *                                                                       Manual-service identity; emits a `Service` node when the page is not a product.
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $image,
        public bool $isProduct = false,
        public ?array $offer = null,
        public bool $isHome = false,
        public string $locale = 'ar',
        public ?array $rating = null,
        public array $reviews = [],
        public array $faq = [],
        public array $breadcrumbs = [],
        public ?array $service = null,
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
     * Mark this page as the home page, so the schema carries a `WebSite` node.
     *
     * The store has no search page, so no `SearchAction` is ever emitted.
     */
    public function withHome(string $locale): self
    {
        return $this->copy(isHome: true, locale: $locale === 'en' ? 'en' : 'ar');
    }

    /**
     * Attach the store rating summary. Silently drops empty summaries, because
     * an `AggregateRating` with no reviews is rejected by Google.
     */
    public function withRating(?float $average, int $count): self
    {
        if ($count <= 0 || $average === null) {
            return $this;
        }

        return $this->copy(rating: ['average' => $average, 'count' => $count]);
    }

    /**
     * Attach up to five comment-only reviews, in the order given.
     *
     * @param  list<mixed>  $items  Projected reader rows (`reviewerName`, `body`, `rating`, `publishedAt`, `hasComment`).
     */
    public function withReviews(array $items): self
    {
        $reviews = [];

        foreach ($items as $item) {
            if (count($reviews) >= 5) {
                break;
            }

            if (! is_array($item) || ($item['hasComment'] ?? false) !== true) {
                continue;
            }

            $body = isset($item['body']) ? trim(strip_tags((string) $item['body'])) : '';
            $name = isset($item['reviewerName']) ? trim((string) $item['reviewerName']) : '';

            if ($body === '' || $name === '') {
                continue;
            }

            $reviews[] = [
                'name' => $name,
                'body' => $body,
                'rating' => (int) ($item['rating'] ?? 5),
                'datePublished' => isset($item['publishedAt']) ? (string) $item['publishedAt'] : null,
            ];
        }

        return $this->copy(reviews: $reviews);
    }

    /**
     * Attach the home-page questions, stripping any HTML from the answers.
     *
     * @param  list<mixed>  $entries  Reader rows (`question`, `answer`).
     */
    public function withFaq(array $entries): self
    {
        $faq = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $question = isset($entry['question']) ? trim(strip_tags((string) $entry['question'])) : '';
            $answer = isset($entry['answer']) ? trim(strip_tags((string) $entry['answer'])) : '';

            if ($question === '' || $answer === '') {
                continue;
            }

            $faq[] = ['question' => $question, 'answer' => $answer];
        }

        return $this->copy(faq: $faq);
    }

    /**
     * Attach the breadcrumb trail, from Home down to the current page.
     *
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     */
    public function withBreadcrumbs(array $breadcrumbs): self
    {
        $trail = [];

        foreach ($breadcrumbs as $crumb) {
            $name = trim($crumb['name']);
            $url = trim($crumb['url']);

            if ($name === '' || $url === '') {
                continue;
            }

            $trail[] = ['name' => $name, 'url' => $url];
        }

        return $this->copy(breadcrumbs: $trail);
    }

    /**
     * Describe a manual service (Rivals, FUT Champions) that has no `Product`
     * node, so the rating still has a node to hang on.
     */
    public function withService(string $name, ?string $description): self
    {
        $name = trim($name);

        if ($name === '') {
            return $this;
        }

        $description = $description !== null ? trim(strip_tags($description)) : null;

        return $this->copy(service: ['name' => $name, 'description' => $description]);
    }

    /**
     * @param  array{average: float|null, count: int}|null  $rating
     * @param  list<array{name: string, body: string, rating: int, datePublished: string|null}>|null  $reviews
     * @param  list<array{question: string, answer: string}>|null  $faq
     * @param  list<array{name: string, url: string}>|null  $breadcrumbs
     * @param  array{name: string, description: string|null}|null  $service
     */
    private function copy(
        ?bool $isHome = null,
        ?string $locale = null,
        ?array $rating = null,
        ?array $reviews = null,
        ?array $faq = null,
        ?array $breadcrumbs = null,
        ?array $service = null,
    ): self {
        return new self(
            title: $this->title,
            description: $this->description,
            image: $this->image,
            isProduct: $this->isProduct,
            offer: $this->offer,
            isHome: $isHome ?? $this->isHome,
            locale: $locale ?? $this->locale,
            rating: $rating ?? $this->rating,
            reviews: $reviews ?? $this->reviews,
            faq: $faq ?? $this->faq,
            breadcrumbs: $breadcrumbs ?? $this->breadcrumbs,
            service: $service ?? $this->service,
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
        $hasOffer = $this->isProduct && $this->offer !== null;
        $hasExtras = $this->isHome || $this->rating !== null || $this->reviews !== []
            || $this->faq !== [] || $this->breadcrumbs !== [] || $this->service !== null;

        // No rich data: the exact single-node shape crawlers already know.
        if (! $hasExtras) {
            if ($hasOffer) {
                return ['@context' => 'https://schema.org', ...$this->productNode($brand)];
            }

            return ['@context' => 'https://schema.org', ...$this->storeNode($brand)];
        }

        $store = $this->storeNode($brand);

        if ($this->service !== null) {
            // Reviews are collected per service, never per product, so the
            // Service node carries the rating: a Product must not advertise
            // reviews that are not about it, and the site-wide store node
            // must not change its rating from page to page.
            $service = $this->serviceNode($brand);
            $this->attachRating($service);
            $nodes = [$store];

            if ($hasOffer) {
                $nodes[] = $this->productNode($brand);
            }

            $nodes[] = $service;

            return $this->graph($nodes);
        }

        if ($hasOffer) {
            $product = $this->productNode($brand);
            $this->attachRating($product);

            return $this->graph([$store, $product]);
        }

        $this->attachRating($store);

        return $this->graph([$store]);
    }

    /**
     * Wrap nodes in a single `@graph`, with `@context` exactly once at the top.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function graph(array $nodes): array
    {
        if ($this->isHome) {
            array_unshift($nodes, $this->websiteNode());
        }

        if ($this->faq !== []) {
            $nodes[] = $this->faqNode();
        }

        if ($this->breadcrumbs !== []) {
            $nodes[] = $this->breadcrumbNode();
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $nodes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeNode(string $brand): array
    {
        return [
            '@type' => 'OnlineStore',
            'name' => $brand,
            'url' => self::siteUrl(),
            'logo' => self::absolute((string) config('store.seo.logo')),
            'description' => (string) trans('store.seo_description'),
            'email' => (string) config('store.support.email'),
            'telephone' => '+966537998099',
            'identifier' => 'FL-621205220',
            'sameAs' => self::sameAs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productNode(string $brand): array
    {
        return [
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
     * @return array<string, mixed>
     */
    private function serviceNode(string $brand): array
    {
        $node = [
            '@type' => 'Service',
            'name' => $this->service['name'],
            'provider' => [
                '@type' => 'OnlineStore',
                'name' => $brand,
                'url' => self::siteUrl(),
            ],
            'areaServed' => 'SA',
        ];

        if ($this->service['description'] !== null && $this->service['description'] !== '') {
            $node['description'] = $this->service['description'];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteNode(): array
    {
        return [
            '@type' => 'WebSite',
            'name' => (string) trans('store.seo_brand'),
            'url' => $this->locale === 'en' ? self::siteUrl().'/en' : self::siteUrl(),
            'inLanguage' => $this->locale,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function faqNode(): array
    {
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(
                fn (array $entry): array => [
                    '@type' => 'Question',
                    'name' => $entry['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $entry['answer'],
                    ],
                ],
                $this->faq,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function breadcrumbNode(): array
    {
        $items = [];

        foreach ($this->breadcrumbs as $position => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Hang the rating (and its reviews) on whichever node is being described.
     *
     * @param  array<string, mixed>  $node
     */
    private function attachRating(array &$node): void
    {
        if ($this->rating === null) {
            return;
        }

        $node['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => $this->rating['average'],
            'reviewCount' => $this->rating['count'],
            'bestRating' => 5,
            'worstRating' => 1,
        ];

        if ($this->reviews !== []) {
            $node['review'] = array_map(
                function (array $review): array {
                    $node = [
                        '@type' => 'Review',
                        'author' => ['@type' => 'Person', 'name' => $review['name']],
                        'reviewBody' => $review['body'],
                        'reviewRating' => [
                            '@type' => 'Rating',
                            'ratingValue' => $review['rating'],
                            'bestRating' => 5,
                            'worstRating' => 1,
                        ],
                    ];

                    if ($review['datePublished'] !== null) {
                        $node['datePublished'] = $review['datePublished'];
                    }

                    return $node;
                },
                $this->reviews,
            );
        }
    }

    /**
     * Every public profile the store owns, plus the WhatsApp line customers
     * actually reach it on. The config stays the source for the socials;
     * nothing here is invented.
     *
     * @return list<string>
     */
    private static function sameAs(): array
    {
        $sameAs = array_values((array) config('store.socials'));
        $whatsapp = (string) config('store.support.whatsapp_url');

        if ($whatsapp !== '' && ! in_array($whatsapp, $sameAs, true)) {
            $sameAs[] = $whatsapp;
        }

        return array_values(array_unique(array_filter($sameAs)));
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

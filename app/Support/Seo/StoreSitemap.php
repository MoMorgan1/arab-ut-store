<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use Carbon\CarbonImmutable;

/**
 * Builds the storefront sitemap.
 *
 * Every entry lists both locale URLs as `xhtml:link` alternates, which is how
 * Google is told the Arabic and English pages are translations of one another
 * rather than duplicates competing for the same terms.
 */
final readonly class StoreSitemap
{
    /**
     * Catalog services whose product pages are individually indexable.
     */
    private const CATALOG_SERVICES = [ServiceType::Sbc, ServiceType::Objectives];

    public function __construct(private StoreCatalogReader $catalog) {}

    /**
     * @return list<array{
     *     loc: string,
     *     alternates: array{ar: string, en: string},
     *     lastmod: string|null,
     * }>
     */
    public function entries(): array
    {
        $entries = [];

        foreach (StoreCanonicalUrls::staticRouteNames() as $routeName) {
            $entries[] = $this->entry($routeName);
        }

        foreach (self::CATALOG_SERVICES as $service) {
            foreach ($this->catalog->publicProductSlugs($service) as $product) {
                $entries[] = $this->entry(
                    "store.{$service->value}.show",
                    ['slug' => $product['slug']],
                    $product['updatedAt'],
                );
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array{loc: string, alternates: array{ar: string, en: string}, lastmod: string|null}
     */
    private function entry(
        string $routeName,
        array $parameters = [],
        ?CarbonImmutable $lastModified = null,
    ): array {
        $alternates = StoreCanonicalUrls::alternatesFor($routeName, $parameters);

        return [
            // Arabic is the default locale, so its unprefixed URL is the one
            // the sitemap submits and the alternates hang off.
            'loc' => $alternates['ar'],
            'alternates' => $alternates,
            'lastmod' => $lastModified?->toAtomString(),
        ];
    }
}

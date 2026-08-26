<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolves the canonical URL and hreflang alternates for indexable store pages.
 *
 * Every storefront page is reachable under three paths: the unprefixed Arabic
 * default (`/sbc`), the explicit Arabic prefix (`/ar/sbc`), and the English
 * prefix (`/en/sbc`). Without a canonical the first two compete as duplicates,
 * and without hreflang the third is not recognised as a translation.
 *
 * Arabic canonicalises to the unprefixed path because that is the only Arabic
 * form the application itself links to; English canonicalises to `/en/...`.
 */
final class StoreCanonicalUrls
{
    /**
     * Route names, without the `localized.` prefix, that search engines may index.
     *
     * Cart, checkout, account, auth, and admin routes are deliberately absent:
     * they are private or transactional and must not advertise a canonical.
     *
     * @var list<string>
     */
    public const INDEXABLE = [
        'home',
        'store.reviews',
        'store.privacy',
        'store.returns',
        'store.warranty',
        'store.ea_backup_codes',
        'store.terms',
        'store.sbc',
        'store.sbc.show',
        'store.objectives',
        'store.objectives.show',
        'store.fut_champions',
        'store.rivals',
    ];

    /**
     * The indexable routes that take no parameters, so the sitemap can list
     * them directly. Parameterised routes need their slugs supplied.
     *
     * @return list<string>
     */
    public static function staticRouteNames(): array
    {
        return array_values(array_filter(
            self::INDEXABLE,
            static fn (string $name): bool => ! str_ends_with($name, '.show'),
        ));
    }

    /**
     * Both locale URLs for one indexable route.
     *
     * @param  array<string, string>  $parameters
     * @return array{ar: string, en: string}
     */
    public static function alternatesFor(string $routeName, array $parameters = []): array
    {
        return [
            'ar' => route($routeName, $parameters),
            'en' => route(
                self::LOCALIZED_PREFIX.$routeName,
                ['locale' => 'en', ...$parameters],
            ),
        ];
    }

    private const LOCALIZED_PREFIX = 'localized.';

    /**
     * @return array{canonical: string, alternates: array<string, string>}|null
     *                                                                          Null when the current route is not an indexable store page.
     */
    public static function forRequest(Request $request): ?array
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $name = $route->getName();

        if ($name === null) {
            return null;
        }

        $base = Str::after($name, self::LOCALIZED_PREFIX);

        if (! in_array($base, self::INDEXABLE, true)) {
            return null;
        }

        $parameters = self::scalarParameters($route->parameters());

        $arabic = route($base, $parameters);
        $english = route(
            self::LOCALIZED_PREFIX.$base,
            ['locale' => 'en', ...$parameters],
        );

        return [
            'canonical' => app()->getLocale() === 'en' ? $english : $arabic,
            'alternates' => [
                'ar' => $arabic,
                'en' => $english,
                'x-default' => $arabic,
            ],
        ];
    }

    /**
     * Keep only the scalar route parameters both URL variants share.
     *
     * `locale` is dropped because each variant supplies its own, and non-scalar
     * bindings are dropped because they cannot be re-encoded into a URL safely.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    private static function scalarParameters(array $parameters): array
    {
        $scalars = [];

        foreach ($parameters as $key => $value) {
            if ($key === 'locale') {
                continue;
            }

            if (is_string($value) || is_int($value)) {
                $scalars[$key] = (string) $value;
            }
        }

        return $scalars;
    }
}

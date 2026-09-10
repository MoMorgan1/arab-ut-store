<?php

namespace App\Support\PublicHandle;

use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns the product segment of an admin URL into a Product.
 *
 * Every human-visible admin product URL addresses the product by its storefront
 * slug (fut-champions), which is unique and always present. A request made with
 * the old 26-character ULID public_id still resolves and is then sent a
 * permanent (301) redirect to the same route with the slug, so a link already
 * sitting in an old tab keeps working while the address bar catches up. The
 * ULID stays the internal join key and never appears in a generated URL.
 */
final class ProductHandle
{
    /**
     * The product route constraint: broad enough for a slug and a ULID alike.
     * Which one arrived is decided when the row is resolved.
     */
    public static function routePattern(): string
    {
        return '[0-9A-Za-z-]+';
    }

    /** Whether the segment is a legacy ULID rather than a storefront slug. */
    public static function isUlid(string $handle): bool
    {
        return Str::isUlid($handle);
    }

    /**
     * Whether the segment has the shape this store issues for a product slug:
     * lowercase alphanumeric words joined by single hyphens.
     */
    public static function looksLikeProductSlug(string $handle): bool
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $handle) === 1;
    }

    /**
     * A product for a staff member. A ULID-shaped segment is tried against the
     * internal public_id first; when no row matches, the lookup falls through to
     * the slug, because a 26-character storefront slug can itself be
     * Crockford-shaped. Only a segment that matches neither a real row nor a
     * recognised slug shape is a 404.
     */
    public static function resolveForAdmin(string $handle): Product
    {
        if (self::isUlid($handle)) {
            $product = Product::query()
                ->where('public_id', mb_strtoupper($handle))
                ->first();

            if ($product !== null) {
                return $product;
            }
        }

        if (self::looksLikeProductSlug($handle)) {
            return Product::query()->where('slug', $handle)->firstOrFail();
        }

        throw (new ModelNotFoundException)->setModel(Product::class);
    }

    /**
     * A permanent redirect to the same route addressed by the slug, or null when
     * the request already used the slug URL. Only safe on safe (GET) requests:
     * a 301 would drop the body of a POST.
     */
    public static function legacyRedirect(Request $request, Product $product): ?RedirectResponse
    {
        $current = $request->route('product');
        $currentHandle = is_string($current) ? $current : '';

        if (! self::isUlid($currentHandle)) {
            return null;
        }

        // A ULID-shaped slug resolves through the fallback lookup, so the
        // segment already is the canonical handle and a redirect would point the
        // request at itself forever.
        if ($currentHandle === self::handleFor($product)) {
            return null;
        }

        $route = $request->route();

        if ($route === null || $route->getName() === null) {
            return null;
        }

        // Route defaults (such as the resolved locale) are merged into
        // parameters() but are not part of the URI; keeping them would append
        // them as a stray query string. Only real URI segments belong here.
        $parameters = array_intersect_key(
            $route->parameters(),
            array_flip($route->parameterNames()),
        );
        $parameters['product'] = (string) $product->slug;

        $url = route($route->getName(), $parameters, absolute: false);
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->to($url, 301);
    }

    /** The handle that addresses this product: its storefront slug. */
    public static function handleFor(Product $product): string
    {
        return (string) $product->slug;
    }
}

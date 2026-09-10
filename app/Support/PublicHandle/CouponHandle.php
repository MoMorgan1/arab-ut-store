<?php

namespace App\Support\PublicHandle;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns the coupon segment of an admin URL into a Coupon.
 *
 * Every human-visible admin coupon URL addresses the coupon by the code staff
 * read out loud (WELCOME10). A request made with the old 26-character ULID
 * public_id still resolves and is then sent a permanent (301) redirect to the
 * same route with the code, so a link already sitting in an old tab keeps
 * working while the address bar catches up. The ULID stays the internal join
 * key and never appears in a generated URL.
 */
final class CouponHandle
{
    /**
     * The coupon route constraint: broad enough for a code and a ULID alike.
     * Which one arrived is decided when the row is resolved.
     */
    public static function routePattern(): string
    {
        return '[0-9A-Za-z-]+';
    }

    /** Whether the segment is a legacy ULID rather than a coupon code. */
    public static function isUlid(string $handle): bool
    {
        return Str::isUlid($handle);
    }

    /**
     * Whether the segment has the shape this store issues for a coupon code:
     * three to twenty-four alphanumeric characters and hyphens. A 26-character
     * ULID can never match, so the two shapes stay disjoint.
     */
    public static function looksLikeCouponCode(string $handle): bool
    {
        return preg_match('/\A[A-Za-z0-9-]{3,24}\z/D', $handle) === 1;
    }

    /**
     * A coupon for a staff member. A ULID-shaped segment is tried against the
     * internal public_id first; when no row matches, the lookup falls through to
     * the code, so a code that happens to be Crockford-shaped still opens. Only
     * a segment that matches neither a real row nor a known code shape is a 404.
     */
    public static function resolveForAdmin(string $handle): Coupon
    {
        if (self::isUlid($handle)) {
            $coupon = Coupon::query()
                ->where('public_id', mb_strtoupper($handle))
                ->first();

            if ($coupon !== null) {
                return $coupon;
            }
        }

        if (self::looksLikeCouponCode($handle)) {
            return Coupon::query()->where('code', mb_strtoupper($handle))->firstOrFail();
        }

        throw (new ModelNotFoundException)->setModel(Coupon::class);
    }

    /**
     * A permanent redirect to the same route addressed by the code, or null when
     * the request already used the code URL. Only safe on safe (GET) requests:
     * a 301 would drop the body of a POST.
     */
    public static function legacyRedirect(Request $request, Coupon $coupon): ?RedirectResponse
    {
        $current = $request->route('coupon');
        $currentHandle = is_string($current) ? $current : '';

        if (! self::isUlid($currentHandle)) {
            return null;
        }

        // A ULID-shaped code resolves through the fallback lookup, so the
        // segment already is the canonical handle and a redirect would point the
        // request at itself forever.
        if ($currentHandle === self::handleFor($coupon)) {
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
        $parameters['coupon'] = (string) $coupon->code;

        $url = route($route->getName(), $parameters, absolute: false);
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->to($url, 301);
    }

    /** The handle that addresses this coupon: its code. */
    public static function handleFor(Coupon $coupon): string
    {
        return (string) $coupon->code;
    }

    /**
     * The locale-aware URL of a coupon's admin detail page. Write controllers
     * return this so the browser never has to rebuild the path from a code.
     */
    public static function adminDetailUrl(Request $request, Coupon $coupon): string
    {
        $currentRouteName = (string) $request->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        return route(
            $prefix.'marketing.coupons.show',
            ['coupon' => (string) $coupon->code],
            absolute: false,
        );
    }
}

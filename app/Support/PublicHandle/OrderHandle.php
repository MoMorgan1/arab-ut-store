<?php

namespace App\Support\PublicHandle;

use App\Checkout\OrderNumber;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns the order segment of a URL into an Order.
 *
 * Every human-visible order URL addresses the order by its short number
 * (AUT-1043, or an imported Salla number such as 277538068). Orders placed before the numbers
 * existed are still reachable by the old 26-character ULID public_id: such a
 * request resolves normally and is then sent a permanent (301) redirect to the
 * same route with the order number, so a link already sitting in an old email
 * keeps working while the address bar catches up. The ULID stays the internal
 * join key and never appears in a generated URL.
 */
final class OrderHandle
{
    /**
     * The order-number shapes written by the Salla order import.
     *
     * The importer stores the CSV value verbatim (`ImportSallaOrders.php:458`),
     * and what Salla puts in that column is its own bare numeric order number -
     * `277538068`, not `UT-277538068`. Every one of the 31,983 imported orders
     * in production carries the bare shape and none carries the prefix, so the
     * prefixed pattern this class shipped with matched no row that exists: it
     * was written from an assumption about the data rather than from the data.
     * Both shapes are recognised because the prefixed form is what the fixtures
     * and some development databases hold, and a handle class that refuses a
     * shape the database contains is exactly the bug being fixed here.
     */
    public const IMPORTED_PATTERN = '/^UT-[0-9]+$/';

    /** Salla's own order number, as the importer stores it: bare digits. */
    public const IMPORTED_NUMERIC_PATTERN = '/^[0-9]+$/';

    /**
     * The order route constraint: broad enough for a short number and a ULID
     * alike. Which one arrived is decided when the row is resolved.
     */
    public static function routePattern(): string
    {
        return '[0-9A-Za-z-]+';
    }

    /** Whether the segment is a legacy ULID rather than a short order number. */
    public static function isUlid(string $handle): bool
    {
        return Str::isUlid($handle);
    }

    /**
     * Whether the segment has a shape this store issues for an order number:
     * the sequential AUT- number, the older random AUT- number, or an imported
     * Salla number in either shape it is stored in. Anything else must not be
     * treated as an order number.
     */
    public static function looksLikeOrderNumber(string $handle): bool
    {
        return preg_match(OrderNumber::PATTERN, $handle) === 1
            || preg_match(OrderNumber::LEGACY_PATTERN, $handle) === 1
            || preg_match(self::IMPORTED_PATTERN, $handle) === 1
            || preg_match(self::IMPORTED_NUMERIC_PATTERN, $handle) === 1;
    }

    /** An order the given customer owns, resolved by short number or ULID. */
    public static function resolveForCustomer(User $user, string $handle): Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where(self::column($handle), self::value($handle))
            ->firstOrFail();
    }

    /** An order for a staff member, resolved by short number or ULID. */
    public static function resolveForAdmin(string $handle): Order
    {
        return Order::query()
            ->where(self::column($handle), self::value($handle))
            ->firstOrFail();
    }

    /**
     * A permanent redirect to the same route addressed by the order number, or
     * null when the request already used the short URL. Only safe on safe
     * (GET) requests: a 301 would drop the body of a POST.
     */
    public static function legacyRedirect(Request $request, Order $order): ?RedirectResponse
    {
        $current = $request->route('order');
        $number = (string) $order->getAttribute('order_number');

        if (! is_string($current) || $current === $number) {
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
        $parameters['order'] = $number;

        $url = route($route->getName(), $parameters, absolute: false);
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->to($url, 301);
    }

    /**
     * The column a handle should be matched against. A ULID resolves on the
     * internal public_id; a recognised order number resolves on order_number;
     * any other shape is not a handle this app ever issues, so it is a 404.
     */
    public static function column(string $handle): string
    {
        if (self::isUlid($handle)) {
            return 'public_id';
        }

        if (self::looksLikeOrderNumber($handle)) {
            return 'order_number';
        }

        throw (new ModelNotFoundException)->setModel(Order::class);
    }

    /**
     * The value to compare against the column. ULIDs are stored uppercase, and
     * SQLite matches TEXT case-sensitively, so a lowercase ULID in an old link
     * is normalised before the query. Order numbers are already uppercase.
     */
    public static function value(string $handle): string
    {
        return self::isUlid($handle) ? mb_strtoupper($handle) : $handle;
    }
}

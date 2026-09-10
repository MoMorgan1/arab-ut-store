<?php

namespace App\Support\PublicHandle;

use App\Customers\CustomerNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns the customer segment of a URL into a User.
 *
 * Every human-visible customer URL addresses the customer by the short number
 * support staff read out loud (CUS-7K4QXM). Accounts that predate the numbers
 * are still reachable by the old 26-character ULID public_id: such a request
 * resolves normally and is then sent a permanent (301) redirect to the same
 * route with the customer number, so a link already sitting in an old tab keeps
 * working while the address bar catches up. Accounts that have no customer
 * number at all — every staff account, and a customer that predates the
 * backfill — get no redirect: the address they arrived on is the address the
 * app still issues for them. The ULID stays the internal join key and never
 * appears in a generated URL for an account that has a number.
 */
final class CustomerHandle
{
    /**
     * The customer route constraint: broad enough for a short number and a ULID
     * alike. Which one arrived is decided when the row is resolved.
     */
    public static function routePattern(): string
    {
        return '[0-9A-Za-z-]+';
    }

    /** Whether the segment is a legacy ULID rather than a short customer number. */
    public static function isUlid(string $handle): bool
    {
        return Str::isUlid($handle);
    }

    /**
     * Whether the segment has the shape this store issues for a customer number
     * (CUS- followed by six unambiguous characters). Matching is case-insensitive
     * because a number typed by hand may arrive in any case.
     */
    public static function looksLikeCustomerNumber(string $handle): bool
    {
        return preg_match(CustomerNumber::PATTERN, mb_strtoupper($handle)) === 1;
    }

    /** A customer for a staff member, resolved by short number or ULID. */
    public static function resolveForAdmin(string $handle): User
    {
        return User::query()
            ->where(self::column($handle), self::value($handle))
            ->firstOrFail();
    }

    /**
     * A permanent redirect to the same route addressed by the customer number,
     * or null when the request already used the short URL or the account has no
     * number. An account without a number keeps the address it arrived on (its
     * ULID); issuing a redirect for it would send the browser to a URL that
     * does not resolve. Only safe on safe (GET) requests: a 301 would drop the
     * body of a POST.
     */
    public static function legacyRedirect(Request $request, User $user): ?RedirectResponse
    {
        $current = $request->route('customer');
        $number = $user->customer_number;

        if (! is_string($number) || $number === '') {
            return null;
        }

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
        $parameters['customer'] = $number;

        $url = route($route->getName(), $parameters, absolute: false);
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->to($url, 301);
    }

    /**
     * The column a handle should be matched against. A ULID resolves on the
     * internal public_id; a recognised customer number resolves on
     * customer_number; any other shape is not a handle this app ever issues, so
     * it is a 404.
     */
    public static function column(string $handle): string
    {
        if (self::isUlid($handle)) {
            return 'public_id';
        }

        if (self::looksLikeCustomerNumber($handle)) {
            return 'customer_number';
        }

        throw (new ModelNotFoundException)->setModel(User::class);
    }

    /**
     * The value to compare against the column. ULIDs and customer numbers are
     * both stored uppercase, and SQLite matches TEXT case-sensitively, so a
     * lowercase value in a hand-typed link is normalised before the query.
     */
    public static function value(string $handle): string
    {
        return mb_strtoupper($handle);
    }

    /**
     * The handle that addresses this account: the short number when it has one,
     * otherwise the ULID public_id (staff accounts, and customers not yet
     * backfilled). For callers that hold the two columns rather than a model.
     */
    public static function handleForValues(?string $number, string $publicId): string
    {
        return is_string($number) && $number !== '' ? $number : $publicId;
    }

    /**
     * The handle that addresses this account: the short number when it has one,
     * otherwise the ULID public_id (staff accounts).
     */
    public static function handleFor(User $user): string
    {
        return self::handleForValues($user->customer_number, (string) $user->public_id);
    }
}

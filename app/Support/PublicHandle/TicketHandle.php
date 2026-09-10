<?php

namespace App\Support\PublicHandle;

use App\Models\SupportTicket;
use App\Support\TicketNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns the ticket segment of an admin URL into a SupportTicket.
 *
 * Every human-visible support ticket URL addresses the ticket by its short
 * number (TKT-7K4QXM). Tickets that predate the numbers are still reachable by
 * the old 26-character ULID public_id: such a request resolves normally and is
 * then sent a permanent (301) redirect to the same route with the ticket number,
 * so a link already sitting in an old tab keeps working while the address bar
 * catches up. The ULID stays the internal join key and never appears in a
 * generated URL.
 */
final class TicketHandle
{
    /**
     * The ticket route constraint: broad enough for a short number and a ULID
     * alike. Which one arrived is decided when the row is resolved.
     */
    public static function routePattern(): string
    {
        return '[0-9A-Za-z-]+';
    }

    /** Whether the segment is a legacy ULID rather than a short ticket number. */
    public static function isUlid(string $handle): bool
    {
        return Str::isUlid($handle);
    }

    /**
     * Whether the segment has the shape this store issues for a ticket number
     * (TKT- followed by six unambiguous characters). Matching is case-insensitive
     * because a number typed by hand may arrive in any case.
     */
    public static function looksLikeTicketNumber(string $handle): bool
    {
        return preg_match(TicketNumber::PATTERN, mb_strtoupper($handle)) === 1;
    }

    /** A ticket for a staff member, resolved by short number or ULID. */
    public static function resolveForAdmin(string $handle): SupportTicket
    {
        return SupportTicket::query()
            ->where(self::column($handle), self::value($handle))
            ->firstOrFail();
    }

    /**
     * A permanent redirect to the same route addressed by the ticket number.
     *
     * Only fires when the request arrived by the legacy ULID: a request already
     * addressed by the ticket number — in any case — resolves in place with a
     * 200, and a ticket with no number keeps the address it arrived on. Only safe
     * on safe (GET) requests: a 301 would drop the body of a PATCH.
     */
    public static function legacyRedirect(Request $request, SupportTicket $ticket): ?RedirectResponse
    {
        $current = $request->route('ticket');
        $number = (string) $ticket->ticket_number;

        if ($number === '') {
            return null;
        }

        if (! is_string($current) || ! self::isUlid($current)) {
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
        $parameters['ticket'] = $number;

        $url = route($route->getName(), $parameters, absolute: false);
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->to($url, 301);
    }

    /**
     * The column a handle should be matched against. A ULID resolves on the
     * internal public_id; a recognised ticket number resolves on ticket_number;
     * any other shape is not a handle this app ever issues, so it is a 404.
     */
    public static function column(string $handle): string
    {
        if (self::isUlid($handle)) {
            return 'public_id';
        }

        if (self::looksLikeTicketNumber($handle)) {
            return 'ticket_number';
        }

        throw (new ModelNotFoundException)->setModel(SupportTicket::class);
    }

    /**
     * The value to compare against the column. ULIDs and ticket numbers are both
     * stored uppercase, and SQLite matches TEXT case-sensitively, so a lowercase
     * value in a hand-typed link is normalised before the query.
     */
    public static function value(string $handle): string
    {
        return mb_strtoupper($handle);
    }

    /**
     * The handle that addresses this ticket: the short number when it has one,
     * otherwise the ULID public_id.
     */
    public static function handleForValues(?string $number, string $publicId): string
    {
        return is_string($number) && $number !== '' ? $number : $publicId;
    }

    /**
     * The handle that addresses this ticket: the short number when it has one,
     * otherwise the ULID public_id.
     */
    public static function handleFor(SupportTicket $ticket): string
    {
        $number = $ticket->getAttribute('ticket_number');

        return self::handleForValues(
            is_string($number) ? $number : null,
            (string) $ticket->public_id,
        );
    }
}

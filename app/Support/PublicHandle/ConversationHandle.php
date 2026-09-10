<?php

namespace App\Support\PublicHandle;

use App\Models\ChatConversation;
use App\Support\ChatNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns the conversation segment of an admin URL into a ChatConversation.
 *
 * Every human-visible support conversation URL addresses the thread by the
 * short id staff read out loud (CHT-7K4QXM). Threads that predate the numbers
 * are still reachable by the old 26-character ULID public_id: such a request
 * resolves normally and is then sent a permanent (301) redirect to the same
 * route with the short id, so a link already sitting in an old tab keeps
 * working while the address bar catches up. The ULID stays the internal join
 * key and never appears in a generated URL.
 */
final class ConversationHandle
{
    /**
     * The conversation route constraint: broad enough for a short id and a ULID
     * alike. Which one arrived is decided when the row is resolved.
     */
    public static function routePattern(): string
    {
        return '[0-9A-Za-z-]+';
    }

    /** Whether the segment is a legacy ULID rather than a short conversation id. */
    public static function isUlid(string $handle): bool
    {
        return Str::isUlid($handle);
    }

    /**
     * Whether the segment has the shape this store issues for a conversation id
     * (CHT- followed by six unambiguous characters). Matching is case-insensitive
     * because an id typed by hand may arrive in any case.
     */
    public static function looksLikeChatId(string $handle): bool
    {
        return preg_match(ChatNumber::PATTERN, mb_strtoupper($handle)) === 1;
    }

    /**
     * A conversation for a staff member, resolved by short id or ULID. Guest
     * conversations are excluded from the admin queue, so they are a 404 here.
     */
    public static function resolveForAdmin(string $handle): ChatConversation
    {
        return ChatConversation::query()
            ->where(self::column($handle), self::value($handle))
            ->whereNotNull('user_id')
            ->firstOrFail();
    }

    /**
     * A permanent redirect to the same route addressed by the short id.
     *
     * Only fires when the request arrived by the legacy ULID: a request already
     * addressed by the short id — in any case — resolves in place with a 200, and
     * a thread with no short id keeps the address it arrived on. Only safe on
     * safe (GET) requests: a 301 would drop the body of a POST.
     */
    public static function legacyRedirect(Request $request, ChatConversation $conversation): ?RedirectResponse
    {
        $current = $request->route('conversation');
        $number = (string) $conversation->short_id;

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
        $parameters['conversation'] = $number;

        $url = route($route->getName(), $parameters, absolute: false);
        $query = $request->getQueryString();

        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        return redirect()->to($url, 301);
    }

    /**
     * The column a handle should be matched against. A ULID resolves on the
     * internal public_id; a recognised conversation id resolves on short_id;
     * any other shape is not a handle this app ever issues, so it is a 404.
     */
    public static function column(string $handle): string
    {
        if (self::isUlid($handle)) {
            return 'public_id';
        }

        if (self::looksLikeChatId($handle)) {
            return 'short_id';
        }

        throw (new ModelNotFoundException)->setModel(ChatConversation::class);
    }

    /**
     * The value to compare against the column. ULIDs and conversation ids are
     * both stored uppercase, and SQLite matches TEXT case-sensitively, so a
     * lowercase value in a hand-typed link is normalised before the query.
     */
    public static function value(string $handle): string
    {
        return mb_strtoupper($handle);
    }

    /**
     * The handle that addresses this thread: the short id when it has one,
     * otherwise the ULID public_id. For callers that hold the two columns rather
     * than a model.
     */
    public static function handleForValues(?string $shortId, string $publicId): string
    {
        return is_string($shortId) && $shortId !== '' ? $shortId : $publicId;
    }

    /**
     * The handle that addresses this thread: the short id when it has one,
     * otherwise the ULID public_id.
     */
    public static function handleFor(ChatConversation $conversation): string
    {
        $shortId = $conversation->getAttribute('short_id');

        return self::handleForValues(
            is_string($shortId) ? $shortId : null,
            (string) $conversation->public_id,
        );
    }
}

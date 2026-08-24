<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RespondsToAdminChatAction
{
    /**
     * Answer a staff chat action in the shape the caller can actually use.
     *
     * The admin pages are Inertia, and Inertia refuses a plain JSON body —
     * "All Inertia requests must receive a valid Inertia response". These four
     * endpoints returned JSON unconditionally, so every reply, note, take-over
     * and resolve wrote its row correctly and then threw a modal in Mohamed's
     * face. A redirect back is the Inertia-native answer: the page re-renders
     * with the new message already in its props, which is also exactly the
     * refresh a chat wants.
     *
     * The JSON branch is kept because it is a real contract, not dead code —
     * the feature tests assert on it, and it is what any non-Inertia caller
     * would expect.
     *
     * `X-Inertia` rather than expectsJson(): Inertia sends
     * `Accept: application/json` too, so expectsJson() is true for both and
     * cannot tell them apart.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respondToChatAction(Request $request, array $payload, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            return back();
        }

        return response()->json(['data' => $payload], $status)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Refuse a staff chat action, in the caller's own shape.
     *
     * Same split as above, and for the same reason: a JSON 409 handed to an
     * Inertia visit is the "must receive a valid Inertia response" modal, not a
     * usable message. An Inertia caller gets the reason in the error bag, which
     * the page can render beside the composer.
     */
    private function refuseChatAction(Request $request, string $code, string $message, int $status = 409): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia')) {
            return back()->withErrors(['chat' => $message]);
        }

        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}

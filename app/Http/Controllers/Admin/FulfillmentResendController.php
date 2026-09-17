<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\ResendFulfillmentItem;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResendFulfillmentItem as ResendRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Answers with what was written, not with what was attempted.
 *
 * The status codes are chosen so a caller can act on them without reading the
 * body: 200 for something that happened, 409 for a state that refused it, 503
 * for a supplier that did. The `outcome` string is the same vocabulary the
 * audit row carries, so a support question and a log line use one word.
 */
final class FulfillmentResendController extends Controller
{
    public function __construct(private readonly ResendFulfillmentItem $action) {}

    public function __invoke(ResendRequest $request, string $item): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        // The route already admits on this permission and the Action checks it
        // again. Three layers, per permissions.md, because this is the
        // high-risk one on the screen.
        Gate::forUser($actor)->authorize(AdminPermission::FulfillmentAct->value);

        $result = $this->action->execute(
            actor: $actor,
            itemPublicId: $item,
            action: $request->action(),
            reasonCode: $request->reasonCode(),
            challengePosition: $request->challengePosition(),
            ipAddress: $request->ip(),
            locale: $request->route('locale') === 'en' ? 'en' : 'ar',
        );

        return response()->json([
            'data' => [
                'outcome' => $result['outcome']->value,
                'action' => $result['action'],
            ],
        ], $result['outcome']->httpStatus())
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

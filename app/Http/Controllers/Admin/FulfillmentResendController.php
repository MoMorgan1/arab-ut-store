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
    /**
     * Outcome to HTTP status.
     *
     * `queued` is deliberately not 201: nothing was created and nothing was
     * placed. An outbox row went back to pending, which is a state change on
     * an existing row and the caller is told so in the body.
     *
     * @var array<string, int>
     */
    private const STATUSES = [
        'queued' => 200,
        'resume_accepted' => 200,
        'retry_accepted' => 200,
        // The first press is still running, or the publisher has the row.
        // Nothing was sent twice, and nothing is wrong.
        'busy' => 409,
        'in_flight' => 409,
        // The row moved between the render and the press.
        'not_actionable' => 409,
        // The supplier said no, or the guarded update affected nothing.
        'refused' => 503,
    ];

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
                'outcome' => $result['outcome'],
                'action' => $result['action'],
            ],
        ], self::STATUSES[$result['outcome']] ?? 409)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

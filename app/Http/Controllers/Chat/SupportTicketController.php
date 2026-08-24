<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Chat\ResolveChatOwner;
use App\Actions\Support\OpenSupportTicket;
use App\Enums\Chat\ChatConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ChatErrorResponse;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly ResolveChatOwner $resolveChatOwner,
        private readonly OpenSupportTicket $openSupportTicket,
        private readonly ChatErrorResponse $chatErrorResponse,
    ) {}

    public function store(Request $request, string $conversationPublicId): JsonResponse
    {
        $owner = $this->resolveChatOwner->forRequest($request);

        if ($owner->userId() === null) {
            return $this->chatErrorResponse->error('handoff_requires_login', 'handoff_requires_login', 403);
        }

        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->where('public_id', $conversationPublicId)
            ->first();

        if (! $conversation instanceof ChatConversation) {
            // Owner-scoped lookup: a purged conversation and another owner's
            // conversation are indistinguishable here, so both answer the same
            // 404. A separate "expired" code would confirm to a prober that the
            // id exists and simply is not theirs.
            return $this->chatErrorResponse->error('conversation_not_found', 'conversation_not_found', 404);
        }

        if ($conversation->status !== ChatConversationStatus::Open) {
            return $this->chatErrorResponse->error('conversation_closed', 'conversation_closed', 409);
        }

        $customer = $conversation->user ?? User::query()->findOrFail((int) $owner->userId());
        $ticket = $this->openSupportTicket->execute($conversation, $customer, openedVia: 'customer_endpoint');

        return response()->json([
            'data' => [
                'ticket' => [
                    'publicId' => $ticket->public_id,
                    'ticketNumber' => $ticket->ticket_number,
                    'status' => $ticket->status->value,
                    'subject' => $ticket->subject,
                    'createdAt' => $ticket->created_at?->toISOString(),
                ],
                'handoffState' => $conversation->fresh()->handoff_state->value,
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}

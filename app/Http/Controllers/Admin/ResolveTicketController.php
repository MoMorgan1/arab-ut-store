<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Support\ResolveSupportTicket;
use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\Chat\ChatHandoffState;
use App\Http\Controllers\Admin\Concerns\RespondsToAdminChatAction;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\User;
use App\Support\PublicHandle\TicketHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ResolveTicketController extends Controller
{
    use RespondsToAdminChatAction;

    public function __construct(
        private readonly ResolveSupportTicket $resolveSupportTicket,
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function __invoke(Request $request, string $ticket): JsonResponse|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::ChatReply->value);

        // Resolve ticket by short number or legacy public_id without a lock first;
        // ResolveSupportTicket establishes the canonical conversation -> ticket lock
        // order inside its transaction (design 3.4).
        $resolved = TicketHandle::resolveForAdmin($ticket);

        $conversation = $resolved->conversation;
        abort_if(! $conversation instanceof ChatConversation || $conversation->user_id === null, 404);

        $resolvedTicket = $this->resolveSupportTicket->execute($resolved, $actor);

        // Audit metadata contains identifiers only, maintaining transcript privacy.
        $this->recordStaffAudit->execute(
            actor: $actor,
            subject: $resolvedTicket,
            event: new StaffAuditEvent(
                action: 'chat.ticket.resolved',
                metadata: [
                    'ticket_number' => (string) $resolvedTicket->ticket_number,
                    'conversation_short_id' => (string) $conversation->short_id,
                    'target_user_id' => (int) $conversation->user_id,
                ],
                ipAddress: $request->ip(),
            ),
        );

        return $this->respondToChatAction($request, [
            'ticket' => [
                'publicId' => (string) $resolvedTicket->public_id,
                'ticketNumber' => (string) $resolvedTicket->ticket_number,
                'status' => $resolvedTicket->status->value,
                'resolvedAt' => $resolvedTicket->resolved_at?->utc()->toIso8601String(),
            ],
            'handoffState' => ChatHandoffState::Resolved->value,
        ], 200);
    }
}

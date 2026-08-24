<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Support\TakeOverConversation;
use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Exceptions\Support\TicketAlreadyAssignedException;
use App\Http\Controllers\Admin\Concerns\RespondsToAdminChatAction;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ConversationTakeOverController extends Controller
{
    use RespondsToAdminChatAction;

    public function __construct(
        private readonly TakeOverConversation $takeOverConversation,
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function __invoke(Request $request, string $publicId): JsonResponse|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::ChatReply->value);

        // Guest conversations are excluded from staff queue operations and return 404.
        /** @var ChatConversation|null $conversation */
        $conversation = ChatConversation::query()
            ->where('public_id', $publicId)
            ->whereNotNull('user_id')
            ->first();

        abort_if($conversation === null, 404);

        try {
            $ticket = $this->takeOverConversation->execute($conversation, $actor);
        } catch (TicketAlreadyAssignedException $exception) {
            return $this->refuseChatAction(
                $request,
                'ticket_already_assigned',
                $exception->getMessage(),
            );
        }

        // Emitting chat.ticket.assigned on take-over tracks ownership changes without persisting conversation content in audit.
        $this->recordStaffAudit->execute(
            actor: $actor,
            subject: $ticket,
            event: new StaffAuditEvent(
                action: 'chat.ticket.assigned',
                metadata: [
                    'ticket_number' => (string) $ticket->ticket_number,
                    'conversation_short_id' => (string) $conversation->short_id,
                    'target_user_id' => (int) $conversation->user_id,
                ],
                ipAddress: $request->ip(),
            ),
        );

        return $this->respondToChatAction($request, [
            'ticket' => [
                'publicId' => (string) $ticket->public_id,
                'ticketNumber' => (string) $ticket->ticket_number,
                'status' => $ticket->status->value,
                'assignedAdminId' => $ticket->assigned_admin_id,
            ],
            'handoffState' => $conversation->fresh()->handoff_state->value,
        ], 200);
    }
}

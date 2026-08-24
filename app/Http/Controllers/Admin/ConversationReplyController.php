<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Support\SendStaffReply;
use App\Actions\Support\TakeOverConversation;
use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Exceptions\Support\TicketAlreadyAssignedException;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ConversationReplyController extends Controller
{
    public function __construct(
        private readonly TakeOverConversation $takeOverConversation,
        private readonly SendStaffReply $sendStaffReply,
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function __invoke(Request $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::ChatReply->value);

        // Guest conversations are excluded from the admin queue and return 404 unconditionally.
        /** @var ChatConversation|null $conversation */
        $conversation = ChatConversation::query()
            ->where('public_id', $publicId)
            ->whereNotNull('user_id')
            ->first();

        abort_if($conversation === null, 404);

        $maxLength = (int) config('chat.max_message_length', 4000);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.$maxLength],
            'client_message_id' => ['nullable', 'string', 'max:255'],
        ]);

        $content = trim((string) $validated['content']);

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => ['The message content cannot be empty.'],
            ]);
        }

        $clientMessageId = isset($validated['client_message_id']) ? (string) $validated['client_message_id'] : null;

        try {
            // Implicit takeover inside the same transaction ensures the ticket exists and handoff is active before replying.
            /** @var array{message: ChatMessage, ticket: SupportTicket} $result */
            $result = DB::transaction(function () use ($conversation, $actor, $content, $clientMessageId): array {
                $ticket = $this->takeOverConversation->execute($conversation, $actor);
                $message = $this->sendStaffReply->execute($ticket, $actor, $content, $clientMessageId);

                return ['message' => $message, 'ticket' => $ticket];
            });
        } catch (TicketAlreadyAssignedException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'ticket_already_assigned',
                    'message' => $exception->getMessage(),
                ],
            ], 409)->header('Cache-Control', 'no-store, private');
        }

        $message = $result['message'];
        $ticket = $result['ticket'];

        // Audit metadata contains metrics and keys only; message body is excluded for transcript privacy.
        $this->recordStaffAudit->execute(
            actor: $actor,
            subject: $message,
            event: new StaffAuditEvent(
                action: 'chat.reply.sent',
                metadata: [
                    'ticket_number' => (string) $ticket->ticket_number,
                    'conversation_short_id' => (string) $conversation->short_id,
                    'target_user_id' => (int) $conversation->user_id,
                    'character_count' => mb_strlen($content),
                ],
                ipAddress: $request->ip(),
            ),
        );

        return response()->json([
            'data' => [
                'message' => [
                    'publicId' => (string) $message->public_id,
                    'senderType' => $message->sender_type->value,
                    'messageType' => $message->message_type->value,
                    'content' => (string) $message->content,
                    'createdAt' => $message->created_at?->utc()->toIso8601String(),
                ],
                'ticket' => [
                    'publicId' => (string) $ticket->public_id,
                    'ticketNumber' => (string) $ticket->ticket_number,
                    'status' => $ticket->status->value,
                ],
                'handoffState' => $conversation->fresh()->handoff_state->value,
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Support\AddInternalNote;
use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ConversationNoteController extends Controller
{
    public function __construct(
        private readonly AddInternalNote $addInternalNote,
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function __invoke(Request $request, string $publicId): JsonResponse
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

        $maxLength = (int) config('chat.max_message_length', 4000);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.$maxLength],
        ]);

        $content = trim((string) $validated['content']);

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => ['The note content cannot be empty.'],
            ]);
        }

        $message = $this->addInternalNote->execute($conversation, $actor, $content);

        /** @var SupportTicket|null $ticket */
        $ticket = $conversation->liveTicket ?? $conversation->tickets()->latest('id')->first();

        $metadata = [
            'conversation_short_id' => (string) $conversation->short_id,
            'target_user_id' => (int) $conversation->user_id,
            'character_count' => mb_strlen($content),
        ];

        if ($ticket instanceof SupportTicket) {
            $metadata['ticket_number'] = (string) $ticket->ticket_number;
        }

        // Audit metadata contains metrics and keys only; message body is excluded for transcript privacy.
        $this->recordStaffAudit->execute(
            actor: $actor,
            subject: $message,
            event: new StaffAuditEvent(
                action: 'chat.note.added',
                metadata: $metadata,
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
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}

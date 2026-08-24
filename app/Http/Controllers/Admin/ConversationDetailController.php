<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminShell;
use App\Enums\AdminPermission;
use App\Enums\Chat\ChatSenderType;
use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ConversationDetailController extends Controller
{
    public function __construct(private readonly AdminShell $shell) {}

    public function __invoke(Request $request, string $publicId): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::ChatView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        /** @var ChatConversation|null $conversation */
        $conversation = ChatConversation::query()
            ->where('public_id', $publicId)
            ->whereNotNull('user_id')
            ->with([
                'user',
                'liveTicket.assignedAdmin',
                'tickets.assignedAdmin',
                // Staff bubbles carry a responder name; without this the map
                // below would lazy-load one user per staff message.
                'messages' => fn ($q) => $q->with('staffUser')->orderBy('id', 'asc'),
                'agentTurns' => fn ($q) => $q->with('runs')->orderBy('id', 'asc'),
            ])
            ->withCount('messages')
            ->first();

        abort_if($conversation === null, 404);

        $messages = $conversation->messages->map(function (ChatMessage $msg): array {
            return [
                'publicId' => (string) $msg->public_id,
                'senderType' => $msg->sender_type->value,
                'messageType' => $msg->message_type->value,
                'content' => (string) $msg->content,
                'staffName' => $this->staffNameFor($msg),
                'createdAt' => $msg->created_at !== null
                    ? Carbon::parse($msg->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        })->values()->all();

        $turns = $conversation->agentTurns->map(function (AgentTurn $turn): array {
            /** @var AgentRun|null $latestRun */
            $latestRun = $turn->runs->sortByDesc('id')->first();

            return [
                'publicId' => (string) $turn->public_id,
                'status' => $turn->status->value,
                'promptVersion' => (string) $turn->prompt_version,
                'createdAt' => $turn->created_at !== null
                    ? Carbon::parse($turn->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
                'latestRunStatus' => $latestRun?->status->value,
                'latencyMs' => $latestRun?->latency_ms,
                'inputTokens' => $latestRun?->input_tokens,
                'outputTokens' => $latestRun?->output_tokens,
                'model' => $latestRun?->model,
            ];
        })->values()->all();

        $conversationSummary = [
            'publicId' => (string) $conversation->public_id,
            'status' => $conversation->status->value,
            'locale' => (string) $conversation->locale,
            'ownerType' => $conversation->user_id !== null ? 'customer' : 'guest',
            'customerName' => $conversation->user?->name,
            'messageCount' => (int) ($conversation->messages_count ?? $conversation->messages->count()),
            'lastMessageAt' => $conversation->last_message_at !== null
                ? Carbon::parse($conversation->last_message_at, 'UTC')->utc()->toIso8601String()
                : null,
            'createdAt' => $conversation->created_at !== null
                ? Carbon::parse($conversation->created_at, 'UTC')->utc()->toIso8601String()
                : '',
            'closedAt' => $conversation->closed_at !== null
                ? Carbon::parse($conversation->closed_at, 'UTC')->utc()->toIso8601String()
                : null,
            'closeReason' => $conversation->close_reason?->value,
            'shortId' => (string) $conversation->short_id,
            'handoffState' => $conversation->handoff_state->value,
        ];

        $ticket = $conversation->liveTicket;

        if ($ticket === null) {
            $ticket = $conversation->tickets->sortByDesc('id')->first();
        }

        $assignedAdmin = $ticket?->assignedAdmin;

        $ticketSummary = $ticket === null ? null : [
            'publicId' => (string) $ticket->public_id,
            'ticketNumber' => (string) $ticket->ticket_number,
            'status' => $ticket->status->value,
            'subject' => $ticket->subject,
            'assignedAdminName' => $assignedAdmin?->name,
            // Whether *this* admin already owns it decides between "Take over"
            // and a disabled control, so the id comparison happens server-side
            // rather than shipping another account's id to the browser.
            'assignedToMe' => $ticket->assigned_admin_id !== null
                && $ticket->assigned_admin_id === $actor->id,
            'openedAt' => $ticket->created_at !== null
                ? Carbon::parse($ticket->created_at, 'UTC')->utc()->toIso8601String()
                : null,
        ];

        return Inertia::render('admin/conversations/show', [
            'auth' => null,
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'conversation' => $conversationSummary,
            'ticket' => $ticketSummary,
            'canReply' => $actor->can(AdminPermission::ChatReply->value),
            'messages' => $messages,
            'turns' => $turns,
        ]);
    }

    /**
     * A staff account deleted after the reply nulls staff_user_id, so the name
     * snapshotted in metadata is the only record left of who answered.
     */
    private function staffNameFor(ChatMessage $message): ?string
    {
        if ($message->sender_type !== ChatSenderType::Staff) {
            return null;
        }

        $name = $message->staffUser?->name;

        if ($name !== null && $name !== '') {
            return $name;
        }

        $metadata = $message->metadata;

        return is_array($metadata) && isset($metadata['staffName'])
            ? (string) $metadata['staffName']
            : null;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminShell;
use App\Enums\AdminPermission;
use App\Enums\Chat\ChatSenderType;
use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\PublicHandle\ConversationHandle;
use App\Support\PublicHandle\TicketHandle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ConversationDetailController extends Controller
{
    public function __construct(private readonly AdminShell $shell) {}

    public function __invoke(Request $request, string $conversation): Response|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::ChatView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $model = ConversationHandle::resolveForAdmin($conversation);

        // A legacy ULID still resolves, then the browser is sent to the short
        // URL so the address bar and every generated link agree. A request that
        // already used the short id — in any case — stays put.
        $redirect = ConversationHandle::legacyRedirect($request, $model);

        if ($redirect instanceof RedirectResponse) {
            return $redirect;
        }

        $model->load([
            'user',
            'liveTicket.assignedAdmin',
            'tickets.assignedAdmin',
            // Staff bubbles carry a responder name; without this the map
            // below would lazy-load one user per staff message.
            'messages.staffUser',
            'agentTurns.runs',
        ]);
        $model->loadCount('messages');

        $messages = $model->messages->map(function (ChatMessage $msg): array {
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

        $turns = $model->agentTurns->values()->map(function (AgentTurn $turn, int $index): array {
            /** @var AgentRun|null $latestRun */
            $latestRun = $turn->runs->sortByDesc('id')->first();

            return [
                // Position within the conversation, oldest first. The internal
                // turn ULID is never shown.
                'ordinal' => $index + 1,
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
        })->all();

        $currentRouteName = (string) $request->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $handle = ConversationHandle::handleFor($model);

        $conversationSummary = [
            'shortId' => (string) $model->short_id,
            'url' => route(
                $prefix.'conversations.show',
                ['conversation' => $handle],
                absolute: false,
            ),
            'status' => $model->status->value,
            'locale' => (string) $model->locale,
            'ownerType' => $model->user_id !== null ? 'customer' : 'guest',
            'customerName' => $model->user?->name,
            'messageCount' => (int) ($model->messages_count ?? $model->messages->count()),
            'lastMessageAt' => $model->last_message_at !== null
                ? Carbon::parse($model->last_message_at, 'UTC')->utc()->toIso8601String()
                : null,
            'createdAt' => $model->created_at !== null
                ? Carbon::parse($model->created_at, 'UTC')->utc()->toIso8601String()
                : '',
            'closedAt' => $model->closed_at !== null
                ? Carbon::parse($model->closed_at, 'UTC')->utc()->toIso8601String()
                : null,
            'closeReason' => $model->close_reason?->value,
            'handoffState' => $model->handoff_state->value,
        ];

        $ticket = $model->liveTicket;

        if ($ticket === null) {
            $ticket = $model->tickets->sortByDesc('id')->first();
        }

        $assignedAdmin = $ticket?->assignedAdmin;

        $ticketSummary = $ticket === null ? null : [
            'number' => (string) $ticket->ticket_number,
            'resolveUrl' => route(
                $prefix.'tickets.resolve',
                ['ticket' => TicketHandle::handleFor($ticket)],
                absolute: false,
            ),
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
            'replyUrl' => route(
                $prefix.'conversations.reply',
                ['conversation' => $handle],
                absolute: false,
            ),
            'noteUrl' => route(
                $prefix.'conversations.note',
                ['conversation' => $handle],
                absolute: false,
            ),
            'takeOverUrl' => route(
                $prefix.'conversations.take-over',
                ['conversation' => $handle],
                absolute: false,
            ),
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

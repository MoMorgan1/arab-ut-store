<?php

namespace App\Http\Controllers\Chat;

use App\Actions\AI\ResolveAssistantMode;
use App\Actions\Chat\CreateOrGetActiveConversation;
use App\Actions\Chat\ResolveChatOwner;
use App\Actions\Chat\RestartChatConversation;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AgentTurnPresenter;
use App\Http\Presenters\ChatPresenter;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Support\SubjectPreview;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatConversationController extends Controller
{
    public function __construct(
        private readonly ResolveChatOwner $resolveChatOwner,
        private readonly CreateOrGetActiveConversation $createOrGetActiveConversation,
        private readonly RestartChatConversation $restartChatConversation,
        private readonly ResolveAssistantMode $resolveAssistantMode,
        private readonly AgentTurnPresenter $agentTurnPresenter,
        private readonly ChatPresenter $chatPresenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $this->resolveChatOwner->forRequest($request);

        if ($owner->userId() === null) {
            return response()->json([
                'data' => [
                    'conversations' => [],
                    'hasMore' => false,
                    'oldestCursor' => null,
                ],
            ])->header('Cache-Control', 'no-store, private');
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            'before_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-HJ-KM-NP-TV-Z]{26}$/i'],
        ]);

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 10;
        $beforeId = $validated['before_id'] ?? null;

        // The activity expression is repeated rather than aliased because the
        // cursor has to compare against exactly what the ordering uses, and
        // MariaDB does not allow a select alias inside WHERE.
        $activity = 'COALESCE(last_message_at, closed_at, updated_at)';

        $query = ChatConversation::query()
            ->forOwner($owner)
            ->orderByLastActivityDesc()
            // Without a tiebreaker two threads sharing a last_message_at
            // paginate in an unstable order and one of them can be served
            // twice or not at all.
            ->orderByDesc('id');

        if ($beforeId !== null && $beforeId !== '') {
            $beforeConversation = ChatConversation::query()
                ->forOwner($owner)
                ->where('public_id', $beforeId)
                ->first();

            if ($beforeConversation instanceof ChatConversation) {
                // A plain `id < cursor` silently drops every thread whose id is
                // higher but whose activity is older — a thread that received a
                // staff reply today sorts first, and each later page then hides
                // everything created after it. The cursor must step through the
                // same (activity, id) order the query is sorted by.
                $beforeActivity = $beforeConversation->last_message_at
                    ?? $beforeConversation->closed_at
                    ?? $beforeConversation->updated_at;
                $beforeKey = $beforeConversation->id;

                $query->where(function (Builder $cursor) use ($activity, $beforeActivity, $beforeKey): void {
                    $cursor->whereRaw($activity.' < ?', [$beforeActivity])
                        ->orWhere(function (Builder $tie) use ($activity, $beforeActivity, $beforeKey): void {
                            $tie->whereRaw($activity.' = ?', [$beforeActivity])
                                ->where('id', '<', $beforeKey);
                        });
                });
            }
        }

        $records = $query->with(['liveTicket', 'tickets'])->limit($limit + 1)->get();

        $hasMore = $records->count() > $limit;
        $items = $records->take($limit);
        $oldestCursor = $items->last()?->public_id;

        $previews = $this->firstCustomerMessagePerConversation(array_values(
            $items->map(fn (ChatConversation $conversation): int => $conversation->id)->all(),
        ));

        $conversations = $items->map(function (ChatConversation $conversation) use ($previews): array {
            $subject = $conversation->subject
                ?? SubjectPreview::fromMessage($previews[$conversation->id] ?? null);
            $ticket = $conversation->liveTicket;

            if ($ticket === null) {
                $ticket = $conversation->tickets->sortByDesc('id')->first();
            }

            $ticketNumber = $ticket?->ticket_number;

            return [
                'publicId' => $conversation->public_id,
                'subject' => $subject,
                'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
                'status' => $conversation->status->value,
                'ticketNumber' => $ticketNumber,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'conversations' => $conversations,
                'hasMore' => $hasMore,
                'oldestCursor' => $oldestCursor,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The earliest customer message body for each of the given conversations.
     *
     * Eager-loading the `messages` relation would pull every message of up to
     * ten threads across the wire to read one string from each — a thread with
     * a long history alone can be hundreds of rows. A grouped MIN(id) subquery
     * fetches exactly one row per conversation in one extra query, so the
     * history endpoint stays inside the page query budget no matter how long
     * the threads are.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, string>
     */
    private function firstCustomerMessagePerConversation(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return ChatMessage::query()
            ->select(['conversation_id', 'content'])
            ->whereIn('id', function ($subquery) use ($conversationIds): void {
                $subquery->selectRaw('MIN(id)')
                    ->from('chat_messages')
                    ->whereIn('conversation_id', $conversationIds)
                    ->where('sender_type', ChatSenderType::Customer->value)
                    ->where('message_type', '!=', ChatMessageType::InternalNote->value)
                    ->groupBy('conversation_id');
            })
            ->get()
            ->mapWithKeys(fn (ChatMessage $message): array => [
                (int) $message->conversation_id => (string) $message->content,
            ])
            ->all();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:10'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $owner = $this->resolveChatOwner->forRequest($request);
        $locale = $validated['locale'] ?? null;
        $conversation = $this->createOrGetActiveConversation->execute($owner, $request, $locale);
        $assistantMode = $this->resolveAssistantMode->for($owner);

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : (int) config('chat.default_page_size', 50);
        $bounded = $this->chatPresenter->loadBoundedMessages($conversation, limit: $limit);

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $assistantMode,
                $this->latestTurnState($conversation),
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $owner = $this->resolveChatOwner->forRequest($request);

        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->where('public_id', $publicId)
            ->first();

        if (! $conversation instanceof ChatConversation) {
            return response()->json([
                'error' => [
                    'code' => 'conversation_not_found',
                    'message' => 'The requested conversation was not found.',
                ],
            ], 404)->header('Cache-Control', 'no-store, private');
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-HJ-KM-NP-TV-Z]{26}$/i'],
        ]);

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : (int) config('chat.default_page_size', 50);
        $beforeId = $validated['before_id'] ?? null;

        if ($beforeId !== null) {
            $cursorExists = $conversation->messages()
                ->where('public_id', $beforeId)
                ->exists();

            if (! $cursorExists) {
                return response()->json([
                    'error' => [
                        'code' => 'invalid_cursor',
                        'message' => 'The provided pagination cursor is invalid for this conversation.',
                    ],
                ], 422)->header('Cache-Control', 'no-store, private');
            }
        }

        $bounded = $this->chatPresenter->loadBoundedMessages($conversation, $beforeId, $limit);
        $assistantMode = $this->resolveAssistantMode->for($owner);

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $assistantMode,
                $this->latestTurnState($conversation),
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function restart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:10'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $owner = $this->resolveChatOwner->forRequest($request);
        $conversation = $this->restartChatConversation->execute($owner, $request, $validated['locale'] ?? null);
        $assistantMode = $this->resolveAssistantMode->for($owner);
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : (int) config('chat.default_page_size', 50);
        $bounded = $this->chatPresenter->loadBoundedMessages($conversation, limit: $limit);

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $assistantMode,
                $this->latestTurnState($conversation),
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestTurnState(ChatConversation $conversation): ?array
    {
        $latestTurn = AgentTurn::query()
            ->where('conversation_id', $conversation->id)
            ->with(['assistantMessage', 'conversation'])
            ->orderByDesc('id')
            ->first();

        return $latestTurn instanceof AgentTurn
            ? $this->agentTurnPresenter->turn($latestTurn)
            : null;
    }
}

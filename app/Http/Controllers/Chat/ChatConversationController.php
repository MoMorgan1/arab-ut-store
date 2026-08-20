<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Chat\CreateOrGetActiveConversation;
use App\Actions\Chat\ResolveChatOwner;
use App\Actions\Chat\RestartChatConversation;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ChatPresenter;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatConversationController extends Controller
{
    public function __construct(
        private readonly ResolveChatOwner $resolveChatOwner,
        private readonly CreateOrGetActiveConversation $createOrGetActiveConversation,
        private readonly RestartChatConversation $restartChatConversation,
        private readonly ChatPresenter $chatPresenter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedConversationRequest($request);

        $owner = $this->resolveChatOwner->forRequest($request);
        $locale = $validated['locale'] ?? null;
        $conversation = $this->createOrGetActiveConversation->execute($owner, $request, $locale);

        return $this->conversationResponse($conversation, $this->boundedLimit($validated));
    }

    public function restart(Request $request): JsonResponse
    {
        $validated = $this->validatedConversationRequest($request);

        $owner = $this->resolveChatOwner->forRequest($request);
        $conversation = $this->restartChatConversation->execute(
            $owner,
            $request,
            $validated['locale'] ?? null,
        );

        return $this->conversationResponse($conversation, $this->boundedLimit($validated));
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

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array{locale?: string|null, limit?: int|null} */
    private function validatedConversationRequest(Request $request): array
    {
        return $request->validate([
            'locale' => ['nullable', 'string', 'max:10'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /** @param array{limit?: int|null} $validated */
    private function boundedLimit(array $validated): int
    {
        return isset($validated['limit'])
            ? (int) $validated['limit']
            : (int) config('chat.default_page_size', 50);
    }

    private function conversationResponse(ChatConversation $conversation, int $limit): JsonResponse
    {
        $bounded = $this->chatPresenter->loadBoundedMessages($conversation, limit: $limit);

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store, private');
    }
}

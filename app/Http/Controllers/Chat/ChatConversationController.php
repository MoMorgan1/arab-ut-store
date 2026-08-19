<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Chat\CreateOrGetActiveConversation;
use App\Actions\Chat\ResolveChatOwner;
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
        private readonly ChatPresenter $chatPresenter,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:10'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $owner = $this->resolveChatOwner->forRequest($request);
        $locale = $validated['locale'] ?? null;
        $conversation = $this->createOrGetActiveConversation->execute($owner, $request, $locale);

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : (int) config('chat.default_page_size', 50);
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
}

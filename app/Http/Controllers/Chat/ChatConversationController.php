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
        $owner = $this->resolveChatOwner->forRequest($request);
        $locale = is_string($request->input('locale')) ? (string) $request->input('locale') : null;
        $conversation = $this->createOrGetActiveConversation->execute($owner, $request, $locale);

        $limit = min((int) ($request->query('limit', config('chat.default_page_size', 50))), 100);
        $bounded = $this->chatPresenter->loadBoundedMessages($conversation, limit: $limit);

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store');
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
            ], 404)->header('Cache-Control', 'no-store');
        }

        $limit = min((int) ($request->query('limit', config('chat.default_page_size', 50))), 100);
        $beforeId = is_string($request->query('before_id')) ? (string) $request->query('before_id') : null;
        $bounded = $this->chatPresenter->loadBoundedMessages($conversation, $beforeId, $limit);

        return response()->json([
            'data' => $this->chatPresenter->conversation(
                $conversation,
                $bounded['messages'],
                $bounded['hasMore'],
                $bounded['oldestCursor'],
            ),
        ])->header('Cache-Control', 'no-store');
    }
}

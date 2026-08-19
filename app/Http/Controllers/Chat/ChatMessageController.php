<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Chat\CreateChatMessage;
use App\Actions\Chat\ResolveChatOwner;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ChatPresenter;
use App\Models\ChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChatMessageController extends Controller
{
    public function __construct(
        private readonly ResolveChatOwner $resolveChatOwner,
        private readonly CreateChatMessage $createChatMessage,
        private readonly ChatPresenter $chatPresenter,
    ) {}

    public function store(Request $request, string $publicId): JsonResponse
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
            'content' => ['required', 'string', 'max:'.config('chat.max_message_length', 4000)],
            'client_message_id' => ['required', 'string', 'max:64'],
        ]);

        $content = trim($validated['content']);

        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => [trans('validation.required', ['attribute' => 'content'])],
            ]);
        }

        $result = $this->createChatMessage->execute(
            $conversation,
            $content,
            $validated['client_message_id'],
        );

        return response()->json([
            'data' => [
                'message' => $this->chatPresenter->message($result['message'], $conversation->public_id),
                'demoReply' => $result['demoReply'] !== null
                    ? $this->chatPresenter->message($result['demoReply'], $conversation->public_id)
                    : null,
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Http\Request;

final readonly class CreateOrGetActiveConversation
{
    public function execute(ChatOwner $owner, Request $request, ?string $locale = null): ChatConversation
    {
        $sessionPointer = $request->session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY);

        if (is_string($sessionPointer) && $sessionPointer !== '') {
            $conversation = ChatConversation::query()
                ->forOwner($owner)
                ->open()
                ->where('public_id', $sessionPointer)
                ->first();

            if ($conversation instanceof ChatConversation) {
                return $conversation;
            }
        }

        $latestConversation = ChatConversation::query()
            ->forOwner($owner)
            ->open()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if ($latestConversation instanceof ChatConversation) {
            $request->session()->put(
                ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY,
                $latestConversation->public_id,
            );

            return $latestConversation;
        }

        $effectiveLocale = in_array($locale, ['ar', 'en'], true) ? $locale : app()->getLocale();

        $conversation = new ChatConversation([
            'user_id' => $owner->userId(),
            'guest_key' => $owner->guestKey(),
            'status' => ChatConversationStatus::Open,
            'locale' => $effectiveLocale,
            'last_message_at' => now(),
        ]);
        $conversation->save();

        $systemContent = $conversation->locale === 'en'
            ? "Hello 👋 I'm the Arab UT assistant. Type your message, and you can sign in anytime if you'd like to track your orders."
            : 'هلا 👋 أنا مساعد عرب التيميت. اكتب رسالتك، وبإمكانك تطلب تسجيل الدخول لاحقًا لو احتجت متابعة الطلبات.';

        $conversation->messages()->create([
            'sender_type' => ChatSenderType::System,
            'message_type' => ChatMessageType::System,
            'content' => $systemContent,
        ]);

        $request->session()->put(
            ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY,
            $conversation->public_id,
        );

        return $conversation;
    }
}

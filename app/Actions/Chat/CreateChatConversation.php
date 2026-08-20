<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Facades\DB;

final readonly class CreateChatConversation
{
    public function execute(ChatOwner $owner, string $locale): ChatConversation
    {
        return DB::transaction(
            fn (): ChatConversation => $this->create($owner, $this->normalizeLocale($locale)),
        );
    }

    private function create(ChatOwner $owner, string $locale): ChatConversation
    {
        $conversation = new ChatConversation([
            'user_id' => $owner->userId(),
            'guest_key' => $owner->guestKey(),
            'status' => ChatConversationStatus::Open,
            'locale' => $locale,
            'last_message_at' => now(),
        ]);
        $conversation->save();
        $conversation->messages()->create([
            'sender_type' => ChatSenderType::System,
            'message_type' => ChatMessageType::System,
            'content' => $this->onboardingContent($locale),
        ]);

        return $conversation;
    }

    private function normalizeLocale(string $locale): string
    {
        if (in_array($locale, ['ar', 'en'], true)) {
            return $locale;
        }

        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }

    private function onboardingContent(string $locale): string
    {
        return $locale === 'en'
            ? "Hello 👋 I'm the Arab UT assistant. Type your message, and you can sign in anytime if you'd like to track your orders."
            : 'هلا 👋 أنا مساعد عرب التيميت. اكتب رسالتك، وبإمكانك تطلب تسجيل الدخول لاحقًا لو احتجت متابعة الطلبات.';
    }
}

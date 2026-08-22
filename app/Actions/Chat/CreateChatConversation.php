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
        $effectiveLocale = $locale === 'en' ? 'en' : 'ar';

        return DB::transaction(function () use ($owner, $effectiveLocale): ChatConversation {
            $conversation = ChatConversation::query()->create([
                'user_id' => $owner->userId(),
                'guest_key' => $owner->guestKey(),
                'status' => ChatConversationStatus::Open,
                'locale' => $effectiveLocale,
                'last_message_at' => now(),
            ]);

            $conversation->messages()->create([
                'sender_type' => ChatSenderType::System,
                'message_type' => ChatMessageType::System,
                'content' => self::seedContent($effectiveLocale),
            ]);

            return $conversation;
        });
    }

    public static function seedContent(string $locale): string
    {
        return $locale === 'en'
            ? "Hello 👋 I'm the Arab UT assistant. Type your message, and you can sign in anytime if you'd like to track your orders."
            : 'هلا 👋 أنا مساعد عرب التيميت. اكتب رسالتك، وبإمكانك تطلب تسجيل الدخول لاحقًا لو احتجت متابعة الطلبات.';
    }
}

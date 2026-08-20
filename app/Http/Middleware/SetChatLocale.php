<?php

namespace App\Http\Middleware;

use App\Actions\Chat\ResolveChatOwner;
use App\Models\ChatConversation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetChatLocale
{
    public function __construct(private ResolveChatOwner $resolveChatOwner) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->localeFor($request));

        return $next($request);
    }

    private function localeFor(Request $request): string
    {
        $conversationPublicId = $request->route('conversation');

        if (is_string($conversationPublicId)) {
            $conversation = ChatConversation::query()
                ->forOwner($this->resolveChatOwner->forRequest($request))
                ->where('public_id', $conversationPublicId)
                ->first();

            if ($conversation instanceof ChatConversation) {
                return $conversation->locale;
            }
        }

        $requestedLocale = $request->input('locale');

        if (is_string($requestedLocale) && in_array($requestedLocale, config('store.locales'), true)) {
            return $requestedLocale;
        }

        return config('store.default_locale');
    }
}

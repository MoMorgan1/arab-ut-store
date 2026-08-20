<?php

use App\Http\Controllers\Chat\ChatConversationController;
use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Middleware\EnsureChatEnabled;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\SetChatLocale;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureChatEnabled::class, NoStore::class])->group(function (): void {
    Route::post('/chat/conversations', [ChatConversationController::class, 'store'])
        ->middleware([SetChatLocale::class, 'throttle:chat-conversations'])
        ->name('chat.conversations.store');

    Route::post('/chat/conversations/restart', [ChatConversationController::class, 'restart'])
        ->middleware([SetChatLocale::class, 'throttle:chat-conversations'])
        ->name('chat.conversations.restart');

    Route::get('/chat/conversations/{conversation}', [ChatConversationController::class, 'show'])
        ->middleware([SetChatLocale::class, 'throttle:chat-read'])
        ->name('chat.conversations.show');

    Route::post('/chat/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])
        ->middleware([SetChatLocale::class, 'throttle:chat-messages'])
        ->name('chat.messages.store');
});

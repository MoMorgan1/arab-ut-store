<?php

use App\Http\Controllers\Chat\AgentTurnController;
use App\Http\Controllers\Chat\ChatConversationController;
use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\ServicePriceController;
use App\Http\Controllers\Chat\SupportTicketController;
use App\Http\Middleware\EnsureChatEnabled;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\SetChatLocale;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureChatEnabled::class, NoStore::class])->group(function (): void {
    Route::get('/chat/service-prices', ServicePriceController::class)
        ->middleware(['throttle:chat-read'])
        ->name('chat.service-prices');

    Route::post('/chat/conversations', [ChatConversationController::class, 'store'])
        ->middleware([SetChatLocale::class, 'throttle:chat-conversations'])
        ->name('chat.conversations.store');

    Route::post('/chat/conversations/restart', [ChatConversationController::class, 'restart'])
        ->middleware([SetChatLocale::class, 'throttle:chat-conversations'])
        ->name('chat.conversations.restart');

    Route::get('/chat/conversations', [ChatConversationController::class, 'index'])
        ->middleware([SetChatLocale::class, 'throttle:chat-read'])
        ->name('chat.conversations.index');

    Route::get('/chat/conversations/{conversation}', [ChatConversationController::class, 'show'])
        ->middleware([SetChatLocale::class, 'throttle:chat-read'])
        ->name('chat.conversations.show');

    Route::post('/chat/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])
        ->middleware([SetChatLocale::class, 'throttle:chat-messages'])
        ->name('chat.messages.store');

    Route::post('/chat/conversations/{conversation}/ticket', [SupportTicketController::class, 'store'])
        ->middleware([SetChatLocale::class, 'throttle:chat-conversations'])
        ->name('chat.tickets.store');

    Route::post('/chat/conversations/{conversation}/agent-turns', [AgentTurnController::class, 'store'])
        ->middleware([SetChatLocale::class, 'throttle:agent-turns'])
        ->name('chat.agent-turns.store');

    Route::get('/chat/conversations/{conversation}/agent-turns/{turn}', [AgentTurnController::class, 'show'])
        ->middleware([SetChatLocale::class, 'throttle:chat-read'])
        ->name('chat.agent-turns.show');

    Route::post('/chat/conversations/{conversation}/agent-turns/{turn}/retry', [AgentTurnController::class, 'retry'])
        ->middleware([SetChatLocale::class, 'throttle:agent-turns'])
        ->name('chat.agent-turns.retry');
});

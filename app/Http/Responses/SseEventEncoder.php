<?php

namespace App\Http\Responses;

use App\Enums\AI\AppStreamEventType;
use JsonException;

final class SseEventEncoder
{
    /**
     * @param  array<string, mixed>  $safeData
     *
     * @throws JsonException
     */
    public function event(AppStreamEventType $type, array $safeData): string
    {
        $json = json_encode($safeData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "event: {$type->value}\ndata: {$json}\n\n";
    }

    public function heartbeat(): string
    {
        return ": heartbeat\n\n";
    }
}

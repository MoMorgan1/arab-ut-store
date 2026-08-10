<?php

namespace App\Validation;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use Illuminate\Validation\Rule;

final class CoinsSelectionRules
{
    /** @return array<string, mixed> */
    public function for(mixed $platform, mixed $delivery): array
    {
        $isPc = $platform === Platform::Pc->value;

        return [
            'platform' => ['required', Rule::enum(Platform::class)->only([Platform::PlayStation, Platform::Pc])],
            'delivery' => $isPc ? ['missing'] : ['required', Rule::enum(DeliveryMode::class)],
            'quantity' => [
                'required',
                'integer',
                'min:'.config('coins.quantity.minimum'),
                'max:'.$this->maximum($isPc, $delivery),
                'multiple_of:'.config('coins.quantity.increment'),
            ],
        ];
    }

    private function maximum(bool $isPc, mixed $delivery): int
    {
        return match (true) {
            $isPc => (int) config('coins.platforms.pc.maximum'),
            $delivery === DeliveryMode::Fast->value => (int) config('coins.platforms.playstation.deliveries.fast.maximum'),
            default => (int) config('coins.platforms.playstation.deliveries.normal.maximum'),
        };
    }
}

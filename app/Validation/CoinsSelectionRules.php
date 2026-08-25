<?php

namespace App\Validation;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\ValueObjects\Pricing\CoinsQuantityRules;
use Closure;
use Illuminate\Validation\Rule;

final class CoinsSelectionRules
{
    /** @return array<string, mixed> */
    public function for(mixed $platform, mixed $delivery): array
    {
        $isPc = $platform === Platform::Pc->value;
        $rules = CoinsQuantityRules::fromConfiguration((array) config('coins.quantity'));

        return [
            'platform' => ['required', Rule::enum(Platform::class)->only([Platform::PlayStation, Platform::Pc])],
            'delivery' => $isPc ? ['missing'] : ['required', Rule::enum(DeliveryMode::class)],
            'quantity' => [
                'required',
                'integer',
                'min:'.$rules->minimum(),
                'max:'.$this->maximum($isPc, $delivery),
                // The step widens as the quantity climbs, so no single
                // multiple_of can express what is buyable.
                static function (string $attribute, mixed $value, Closure $fail) use ($rules): void {
                    // A query string arrives as text, so the value reaching a
                    // closure rule is not yet the integer the rules above proved
                    // it to be.
                    if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                        return;
                    }

                    if (! $rules->accepts((int) $value)) {
                        $fail('store.errors.coins_quantity_step')->translate();
                    }
                },
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

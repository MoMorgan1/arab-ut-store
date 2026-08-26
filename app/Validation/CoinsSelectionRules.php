<?php

namespace App\Validation;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Services\Catalog\CoinsCatalogReader;
use Closure;
use Illuminate\Validation\Rule;

final class CoinsSelectionRules
{
    /** @return array<string, mixed> */
    public function for(mixed $platform, mixed $delivery): array
    {
        $isPc = $platform === Platform::Pc->value;
        // The live rules, not the config defaults - the admin can move the
        // floor and the rounding unit without a deploy.
        $rules = app(CoinsCatalogReader::class)->quantityRules();

        return [
            'platform' => ['required', Rule::enum(Platform::class)->only([Platform::PlayStation, Platform::Pc])],
            'delivery' => $isPc ? ['missing'] : ['required', Rule::enum(DeliveryMode::class)],
            'quantity' => [
                'required',
                'integer',
                'min:'.$rules->minimum(),
                'max:'.$this->maximum($isPc, $delivery),
                // multiple_of cannot express this: the floor is not zero, so
                // what is buyable is the floor plus whole rounding units, and the
                // ceiling depends on the platform and delivery speed chosen.
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

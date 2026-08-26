<?php

use App\Enums\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a customer order the amount they actually want.
 *
 * The bands were doing two jobs: deciding where the slider stops and deciding
 * what could be bought. That second job is now the rounding unit's, so a typed
 * quantity is rounded to five thousand rather than dragged onto the nearest
 * band step. Five thousand divides the floor and every band step already in
 * use, so nothing that was buyable stops being buyable.
 */
return new class extends Migration
{
    private const ROUNDING_UNIT = 5_000;

    public function up(): void
    {
        $this->rewriteConfiguration(fn (array $configuration): array => [
            ...$configuration,
            'roundingUnit' => self::ROUNDING_UNIT,
        ]);
    }

    public function down(): void
    {
        $this->rewriteConfiguration(static function (array $configuration): array {
            unset($configuration['roundingUnit']);

            return $configuration;
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    private function rewriteConfiguration(callable $mutate): void
    {
        $row = DB::table('service_price_schedules')
            ->where('service_type', ServiceType::Coins->value)
            ->first();

        if ($row === null) {
            return;
        }

        $configuration = json_decode((string) $row->configuration, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($configuration)) {
            return;
        }

        DB::table('service_price_schedules')
            ->where('id', $row->id)
            ->update([
                'configuration' => json_encode($mutate($configuration), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};

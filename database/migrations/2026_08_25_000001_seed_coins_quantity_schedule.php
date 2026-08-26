<?php

use App\Enums\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves the Coins quantity limits out of config and into the schedule table the
 * admin already edits, so changing what a customer may buy stops needing a deploy.
 *
 * The seeded values are exactly what config/coins.php carried, which stays as the
 * fallback for a fresh database and as the documented default.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('service_price_schedules')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'service_type' => ServiceType::Coins->value,
            'version' => 1,
            'configuration' => json_encode($this->seededQuantityRules(), JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('service_price_schedules')
            ->where('service_type', ServiceType::Coins->value)
            ->delete();
    }

    /** @return array<string, mixed> */
    private function seededQuantityRules(): array
    {
        return [
            'minimum' => 50_000,
            'tiers' => [
                ['upTo' => 500_000, 'step' => 10_000],
                ['upTo' => 2_000_000, 'step' => 50_000],
                ['upTo' => 20_000_000, 'step' => 250_000],
            ],
            'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
        ];
    }
};

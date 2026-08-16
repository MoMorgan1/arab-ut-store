<?php

use App\Enums\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_price_schedules')) {
            Schema::create('service_price_schedules', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->string('service_type')->unique();
                $this->positiveVersionColumn($table);
                $table->json('configuration');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE service_price_schedules ADD CONSTRAINT service_price_schedules_version_positive CHECK (version > 0)');
            }
        }

        $now = now();

        foreach ($this->approvedSchedules() as $serviceType => $configuration) {
            DB::table('service_price_schedules')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'service_type' => $serviceType,
                'version' => 1,
                'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_price_schedules');
    }

    private function positiveVersionColumn(Blueprint $table): ColumnDefinition
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $table->rawColumn('version', 'integer not null default 1 check (version > 0)');
        }

        return $table->unsignedInteger('version')->default(1);
    }

    /** @return array<string, array<string, mixed>> */
    private function approvedSchedules(): array
    {
        return [
            ServiceType::FutChampions->value => [
                'ranks' => [
                    '1' => 22_000,
                    '2' => 19_000,
                    '3' => 17_000,
                    '4' => 15_000,
                    '5' => 13_000,
                    '6' => 10_000,
                ],
                'urgent_surcharge_halalah' => 4_000,
            ],
            ServiceType::Rivals->value => [
                'steps' => [
                    '7:6' => 11_000,
                    '6:5' => 12_000,
                    '5:4' => 13_000,
                    '4:3' => 14_000,
                    '3:2' => 15_000,
                    '2:1' => 16_000,
                    '1:elite' => 17_000,
                ],
            ],
        ];
    }
};

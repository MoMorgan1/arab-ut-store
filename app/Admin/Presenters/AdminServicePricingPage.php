<?php

namespace App\Admin\Presenters;

use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;
use App\Models\User;

/**
 * @phpstan-type AdminServicePricingScheduleData array{
 *     serviceType: string,
 *     version: int,
 *     isActive: bool,
 *     updatedAt: string,
 *     configuration: array<string, mixed>
 * }
 */
final readonly class AdminServicePricingPage
{
    /**
     * @return array{
     *     schedules: list<AdminServicePricingScheduleData>
     * }
     */
    public function for(User $actor): array
    {
        $schedules = ServicePriceSchedule::query()
            ->whereIn('service_type', [ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins])
            ->orderBy('id')
            ->get();

        $projected = array_values($schedules->map(fn (ServicePriceSchedule $schedule): array => [
            'serviceType' => $schedule->service_type->value,
            'version' => (int) $schedule->version,
            'isActive' => (bool) $schedule->is_active,
            'updatedAt' => $schedule->updated_at?->toIso8601String() ?? '',
            'configuration' => (array) $schedule->configuration,
        ])->all());

        return [
            'schedules' => $projected,
        ];
    }
}

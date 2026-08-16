<?php

namespace App\Actions\Pricing;

use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;
use App\ValueObjects\Pricing\FutChampionsPricing;
use App\ValueObjects\Pricing\RivalsPricing;
use DomainException;

final readonly class ReadManualServicePricing
{
    /** @return array{schedule: ServicePriceSchedule, pricing: FutChampionsPricing} */
    public function futChampions(bool $lock = false): array
    {
        $schedule = $this->activeSchedule(ServiceType::FutChampions, $lock);

        return [
            'schedule' => $schedule,
            'pricing' => FutChampionsPricing::fromConfiguration($schedule->configuration),
        ];
    }

    /** @return array{schedule: ServicePriceSchedule, pricing: RivalsPricing} */
    public function rivals(bool $lock = false): array
    {
        $schedule = $this->activeSchedule(ServiceType::Rivals, $lock);

        return [
            'schedule' => $schedule,
            'pricing' => RivalsPricing::fromConfiguration($schedule->configuration),
        ];
    }

    private function activeSchedule(ServiceType $serviceType, bool $lock): ServicePriceSchedule
    {
        $query = ServicePriceSchedule::query()->where('service_type', $serviceType);

        if ($lock) {
            $query->lockForUpdate();
        }

        $schedule = $query->first();

        if (! $schedule instanceof ServicePriceSchedule || ! $schedule->is_active) {
            throw new DomainException('The requested manual-service pricing is unavailable.');
        }

        return $schedule;
    }
}

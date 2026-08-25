<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Exceptions\AdminServicePricingConflict;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use App\ValueObjects\Pricing\CoinsQuantityRules;
use App\ValueObjects\Pricing\FutChampionsPricing;
use App\ValueObjects\Pricing\RivalsPricing;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateServicePriceSchedule
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $newConfiguration
     */
    public function execute(
        User $actor,
        ServiceType|string $serviceType,
        int $expectedVersion,
        array $newConfiguration,
        ?string $ipAddress = null,
    ): ServicePriceSchedule {
        if (! $actor->is_active || ! $actor->can(AdminPermission::SettingsManage->value)) {
            throw new AuthorizationException('This action requires settings.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update service price schedules.');
        }

        $type = is_string($serviceType) ? ServiceType::tryFrom($serviceType) : $serviceType;

        if ($type === null || ! in_array($type, [ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins], true)) {
            throw ValidationException::withMessages([
                'service_type' => ['The requested service type is not supported.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $type,
            $expectedVersion,
            $newConfiguration,
            $ipAddress,
        ): ServicePriceSchedule {
            /** @var ServicePriceSchedule $schedule */
            $schedule = ServicePriceSchedule::query()
                ->where('service_type', $type)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $schedule->version !== $expectedVersion) {
                throw new AdminServicePricingConflict(
                    serviceType: $type->value,
                    currentVersion: (int) $schedule->version,
                    currentActive: (bool) $schedule->is_active,
                    currentConfiguration: (array) $schedule->configuration,
                );
            }

            // Validate configuration by instantiating the domain value object inside the transaction
            // before saving. Catch DomainException and surface it as a 422 ValidationException.
            try {
                match ($type) {
                    ServiceType::FutChampions => FutChampionsPricing::fromConfiguration($newConfiguration),
                    ServiceType::Rivals => RivalsPricing::fromConfiguration($newConfiguration),
                    default => CoinsQuantityRules::fromConfiguration($newConfiguration),
                };
            } catch (DomainException $exception) {
                $field = 'configuration';
                $message = $exception->getMessage();

                if (str_contains(strtolower($message), 'urgent surcharge')) {
                    $field = 'configuration.urgent_surcharge_halalah';
                } elseif (str_contains(strtolower($message), 'rank')) {
                    $field = 'configuration.ranks';
                } elseif (str_contains(strtolower($message), 'tier') || str_contains(strtolower($message), 'band')) {
                    $field = 'configuration.tiers';
                } elseif (str_contains(strtolower($message), 'preset')) {
                    $field = 'configuration.presets';
                } elseif (str_contains(strtolower($message), 'minimum')) {
                    $field = 'configuration.minimum';
                } elseif (str_contains(strtolower($message), 'step')) {
                    $field = 'configuration.steps';
                }

                throw ValidationException::withMessages([
                    $field => [$message],
                ]);
            }

            $previousConfiguration = (array) $schedule->configuration;
            $diff = $this->calculatePriceDiff($type, $previousConfiguration, $newConfiguration);

            if (! empty($diff['changed'])) {
                $previousVersion = (int) $schedule->version;
                $newVersion = $previousVersion + 1;

                $schedule->version = $newVersion;
                $schedule->configuration = $newConfiguration;
                $schedule->save();

                $this->recordStaffAudit->execute(
                    $actor,
                    $schedule,
                    new StaffAuditEvent(
                        action: 'settings.service_pricing_updated',
                        metadata: [
                            'service_type' => $type->value,
                            'previous_version' => $previousVersion,
                            'new_version' => $newVersion,
                            'prices_changed' => $diff['changed'],
                            'prices_previous' => $diff['previous'],
                            'prices_new' => $diff['new'],
                        ],
                        ipAddress: $ipAddress,
                    ),
                );
            }

            return $schedule;
        });
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $new
     * @return array{
     *     changed: list<string>,
     *     previous: array<string, int|null>,
     *     new: array<string, int|null>
     * }
     */
    private function calculatePriceDiff(ServiceType $type, array $previous, array $new): array
    {
        /** @var list<string> $changed */
        $changed = [];
        /** @var array<string, int|null> $previousValues */
        $previousValues = [];
        /** @var array<string, int|null> $newValues */
        $newValues = [];

        if ($type === ServiceType::FutChampions) {
            $prevRanks = is_array($previous['ranks'] ?? null) ? $previous['ranks'] : [];
            $newRanks = is_array($new['ranks'] ?? null) ? $new['ranks'] : [];

            for ($rank = 1; $rank <= 6; $rank++) {
                $key = (string) $rank;
                $prevPrice = isset($prevRanks[$key]) ? (int) $prevRanks[$key] : (isset($prevRanks[$rank]) ? (int) $prevRanks[$rank] : null);
                $newPrice = isset($newRanks[$key]) ? (int) $newRanks[$key] : (isset($newRanks[$rank]) ? (int) $newRanks[$rank] : null);

                if ($prevPrice !== $newPrice) {
                    $fieldKey = "ranks.{$rank}";
                    $changed[] = $fieldKey;
                    $previousValues[$fieldKey] = $prevPrice;
                    $newValues[$fieldKey] = $newPrice;
                }
            }

            $prevUrgent = isset($previous['urgent_surcharge_halalah']) ? (int) $previous['urgent_surcharge_halalah'] : null;
            $newUrgent = isset($new['urgent_surcharge_halalah']) ? (int) $new['urgent_surcharge_halalah'] : null;

            if ($prevUrgent !== $newUrgent) {
                $changed[] = 'urgent_surcharge_halalah';
                $previousValues['urgent_surcharge_halalah'] = $prevUrgent;
                $newValues['urgent_surcharge_halalah'] = $newUrgent;
            }
        } elseif ($type === ServiceType::Rivals) {
            $prevSteps = is_array($previous['steps'] ?? null) ? $previous['steps'] : [];
            $newSteps = is_array($new['steps'] ?? null) ? $new['steps'] : [];
            $steps = ['7:6', '6:5', '5:4', '4:3', '3:2', '2:1', '1:elite'];

            foreach ($steps as $step) {
                $prevPrice = isset($prevSteps[$step]) ? (int) $prevSteps[$step] : null;
                $newPrice = isset($newSteps[$step]) ? (int) $newSteps[$step] : null;

                if ($prevPrice !== $newPrice) {
                    $fieldKey = "steps.{$step}";
                    $changed[] = $fieldKey;
                    $previousValues[$fieldKey] = $prevPrice;
                    $newValues[$fieldKey] = $newPrice;
                }
            }
        } elseif ($type === ServiceType::Coins) {
            // Coins carries what a customer may buy rather than a price, so the
            // audit records the floor and each band's ceiling and step.
            $prevMinimum = isset($previous['minimum']) ? (int) $previous['minimum'] : null;
            $newMinimum = isset($new['minimum']) ? (int) $new['minimum'] : null;

            if ($prevMinimum !== $newMinimum) {
                $changed[] = 'minimum';
                $previousValues['minimum'] = $prevMinimum;
                $newValues['minimum'] = $newMinimum;
            }

            $prevTiers = is_array($previous['tiers'] ?? null) ? array_values($previous['tiers']) : [];
            $newTiers = is_array($new['tiers'] ?? null) ? array_values($new['tiers']) : [];

            foreach (range(0, max(count($prevTiers), count($newTiers)) - 1) as $index) {
                foreach (['upTo', 'step'] as $part) {
                    $prevPart = isset($prevTiers[$index][$part]) ? (int) $prevTiers[$index][$part] : null;
                    $newPart = isset($newTiers[$index][$part]) ? (int) $newTiers[$index][$part] : null;

                    if ($prevPart !== $newPart) {
                        $fieldKey = "tiers.{$index}.{$part}";
                        $changed[] = $fieldKey;
                        $previousValues[$fieldKey] = $prevPart;
                        $newValues[$fieldKey] = $newPart;
                    }
                }
            }
        }

        return [
            'changed' => $changed,
            'previous' => $previousValues,
            'new' => $newValues,
        ];
    }
}

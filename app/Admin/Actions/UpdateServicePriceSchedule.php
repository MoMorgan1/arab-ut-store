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
     * What actually changed between two stored configurations.
     *
     * This used to name every field by hand, per service type, which meant a
     * field added later was invisible to it: the save is gated on this diff, so
     * an edit touching only the new field validated, returned 200 and wrote
     * nothing. Weekly matches could not be put on sale, and the coins rounding
     * unit and quick amounts could not be changed on their own.
     *
     * Comparing the flattened configurations instead makes the diff total by
     * construction, and yields the same dotted keys the audit already used -
     * ranks.1, steps.7:6, tiers.0.upTo.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $new
     * @return array{
     *     changed: list<string>,
     *     previous: array<string, scalar|null>,
     *     new: array<string, scalar|null>
     * }
     */
    private function calculatePriceDiff(ServiceType $type, array $previous, array $new): array
    {
        $previousLeaves = self::flatten($previous);
        $newLeaves = self::flatten($new);

        /** @var list<string> $changed */
        $changed = [];
        /** @var array<string, scalar|null> $previousValues */
        $previousValues = [];
        /** @var array<string, scalar|null> $newValues */
        $newValues = [];

        foreach (array_keys($previousLeaves + $newLeaves) as $key) {
            $before = $previousLeaves[$key] ?? null;
            $after = $newLeaves[$key] ?? null;

            if ($before === $after) {
                continue;
            }

            $changed[] = $key;
            $previousValues[$key] = $before;
            $newValues[$key] = $after;
        }

        sort($changed);

        return [
            'changed' => $changed,
            'previous' => $previousValues,
            'new' => $newValues,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $configuration
     * @return array<string, scalar|null>
     */
    private static function flatten(array $configuration, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($configuration as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $leaves = [...$leaves, ...self::flatten($value, $path)];

                continue;
            }

            $leaves[$path] = is_scalar($value) ? $value : null;
        }

        return $leaves;
    }
}

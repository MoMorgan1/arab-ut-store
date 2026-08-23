<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Exceptions\AdminServicePricingConflict;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateServicePriceScheduleStatus
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        ServiceType|string $serviceType,
        string $action,
        bool $expectedActive,
        ?string $ipAddress = null,
    ): ServicePriceSchedule {
        if (! $actor->is_active || ! $actor->can(AdminPermission::SettingsManage->value)) {
            throw new AuthorizationException('This action requires settings.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update service schedule availability.');
        }

        $type = is_string($serviceType) ? ServiceType::tryFrom($serviceType) : $serviceType;

        if ($type === null || ! in_array($type, [ServiceType::FutChampions, ServiceType::Rivals], true)) {
            throw ValidationException::withMessages([
                'service_type' => ['The requested service type is not supported.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $type,
            $action,
            $expectedActive,
            $ipAddress,
        ): ServicePriceSchedule {
            /** @var ServicePriceSchedule $schedule */
            $schedule = ServicePriceSchedule::query()
                ->where('service_type', $type)
                ->lockForUpdate()
                ->firstOrFail();

            if ((bool) $schedule->is_active !== $expectedActive) {
                throw new AdminServicePricingConflict(
                    serviceType: $type->value,
                    currentVersion: (int) $schedule->version,
                    currentActive: (bool) $schedule->is_active,
                    currentConfiguration: (array) $schedule->configuration,
                );
            }

            $newActive = match ($action) {
                'deactivate' => false,
                'activate' => true,
                default => throw new InvalidArgumentException("Invalid action: {$action}"),
            };

            $previousActive = (bool) $schedule->is_active;

            if ($previousActive !== $newActive) {
                $schedule->is_active = $newActive;
                $schedule->save();

                $auditAction = $newActive ? 'settings.service_pricing_activated' : 'settings.service_pricing_deactivated';

                $this->recordStaffAudit->execute(
                    $actor,
                    $schedule,
                    new StaffAuditEvent(
                        action: $auditAction,
                        metadata: [
                            'service_type' => $type->value,
                            'previous_active' => $previousActive,
                            'new_active' => $newActive,
                        ],
                        ipAddress: $ipAddress,
                    ),
                );
            }

            return $schedule;
        });
    }
}

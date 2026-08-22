<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminCustomerStatusConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateAdminCustomerStatus
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $customerPublicId,
        string $action,
        string $reasonCode,
        ?string $caseReference,
        bool $expectedActive,
        ?string $ipAddress = null,
    ): User {
        if (! $actor->is_active || ! $actor->can(AdminPermission::CustomersUpdateStatus->value)) {
            throw new AuthorizationException('This action requires customers.update_status permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update customer status.');
        }

        return DB::transaction(function () use (
            $actor,
            $customerPublicId,
            $action,
            $reasonCode,
            $caseReference,
            $expectedActive,
            $ipAddress,
        ): User {
            /** @var User $target */
            $target = User::query()
                ->where('public_id', $customerPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->role !== UserRole::Customer) {
                throw new AuthorizationException('Only customer accounts can have their status updated.');
            }

            if ((bool) $target->is_active !== $expectedActive) {
                throw new AdminCustomerStatusConflict((string) $target->public_id, (bool) $target->is_active);
            }

            $newActive = match ($action) {
                'suspend' => false,
                'reactivate' => true,
                default => throw new InvalidArgumentException("Invalid action: {$action}"),
            };

            $previousActive = (bool) $target->is_active;
            $target->is_active = $newActive;
            $target->save();

            if ($action === 'suspend') {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            $auditAction = $action === 'suspend' ? 'customers.suspended' : 'customers.reactivated';

            $this->recordStaffAudit->execute(
                $actor,
                $target,
                new StaffAuditEvent(
                    action: $auditAction,
                    metadata: [
                        'reason_code' => $reasonCode,
                        'case_reference' => $caseReference,
                        'previous_active' => $previousActive,
                        'new_active' => $newActive,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $target;
        });
    }
}

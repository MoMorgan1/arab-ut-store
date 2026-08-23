<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminStaffGrantRejected;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Promotes an existing account to Admin or Staff.
 *
 * The UI equivalent of `admin:grant-role`, and deliberately the same shape: it
 * never creates an account and never sets a password. The person must already
 * have signed up, which means their email is real and theirs, and they still
 * have to pass password login and enroll TOTP before any Admin page opens.
 */
final class GrantStaffAccess
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $email,
        string $newRole,
        ?string $ipAddress = null,
    ): User {
        if (! $actor->is_active || ! $actor->can(AdminPermission::StaffManage->value)) {
            throw new AuthorizationException('This action requires staff.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may grant staff access.');
        }

        $targetRole = match ($newRole) {
            'admin' => UserRole::Admin,
            'staff' => UserRole::Staff,
            default => throw new InvalidArgumentException("Invalid role: {$newRole}"),
        };

        $normalizedEmail = mb_strtolower(trim($email));

        return DB::transaction(function () use (
            $actor,
            $normalizedEmail,
            $targetRole,
            $ipAddress,
        ): User {
            $target = User::query()
                ->whereRaw('lower(email) = ?', [$normalizedEmail])
                ->lockForUpdate()
                ->first();

            if (! $target instanceof User) {
                throw AdminStaffGrantRejected::noSuchAccount();
            }

            if ($target->role === UserRole::ServiceAccount) {
                throw AdminStaffGrantRejected::serviceAccount();
            }

            if ($actor->id === $target->id) {
                throw AdminStaffGrantRejected::self();
            }

            if ($target->role === $targetRole) {
                throw AdminStaffGrantRejected::alreadyGranted($targetRole->value);
            }

            if (! $target->is_active) {
                throw AdminStaffGrantRejected::inactiveAccount();
            }

            $previousRole = $target->role;
            $target->role = $targetRole;
            $target->save();

            $this->recordStaffAudit->execute(
                $actor,
                $target,
                new StaffAuditEvent(
                    action: 'staff.role_changed',
                    metadata: [
                        'previous_role' => $previousRole->value,
                        'new_role' => $targetRole->value,
                        'source' => 'admin_ui',
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $target;
        });
    }
}

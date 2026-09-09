<?php

namespace App\Console\Commands;

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GrantAdminRole extends Command
{
    protected $signature = 'admin:grant-role
        {email : Email address of the existing account}
        {--role=admin : Role to assign: admin or staff}
        {--revoke : Demote the account back to a customer instead}';

    protected $description = 'Bootstrap or revoke Admin/Staff access for an existing account (server operators only)';

    public function handle(RecordStaffAudit $recordStaffAudit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $revoke = (bool) $this->option('revoke');
        $roleOption = mb_strtolower(trim((string) $this->option('role')));

        $targetRole = $revoke
            ? UserRole::Customer
            : match ($roleOption) {
                'admin' => UserRole::Admin,
                'staff' => UserRole::Staff,
                default => null,
            };

        if ($targetRole === null) {
            $this->components->error('The --role option must be "admin" or "staff".');

            return self::INVALID;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user instanceof User) {
            $this->components->error('No account exists for that email address.');

            return self::FAILURE;
        }

        if ($user->role === UserRole::ServiceAccount) {
            $this->components->error('Service accounts cannot receive interactive Admin access.');

            return self::FAILURE;
        }

        if ($user->role === $targetRole) {
            $this->components->info("{$user->email} already has the {$targetRole->value} role.");

            return self::SUCCESS;
        }

        $previousRole = $user->role;

        DB::transaction(function () use ($user, $targetRole, $previousRole, $recordStaffAudit): void {
            $user->forceFill(['role' => $targetRole])->save();

            $recordStaffAudit->execute(
                null,
                $user,
                new StaffAuditEvent('staff.role_changed', [
                    'previous_role' => $previousRole->value,
                    'new_role' => $targetRole->value,
                    'source' => 'console',
                ], null),
            );
        });

        $this->components->info("{$user->email} is now {$targetRole->value}.");

        if ($targetRole !== UserRole::Customer) {
            $this->components->bulletList([
                'Sign in with email and password (Google/WhatsApp login is refused for privileged roles).',
                'Accounts without a password are routed through password setup first.',
                'Confirm TOTP at /admin/security/mfa before ordinary Admin pages open.',
            ]);
        }

        return self::SUCCESS;
    }
}

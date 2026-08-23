<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminCustomerContactConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdateAdminCustomerContact
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $customerPublicId,
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        string $expectedUpdatedAt,
        ?string $ipAddress = null,
    ): User {
        if (! $actor->is_active || ! $actor->can(AdminPermission::CustomersUpdateContact->value)) {
            throw new AuthorizationException('This action requires customers.update_contact permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update customer contact details.');
        }

        return DB::transaction(function () use (
            $actor,
            $customerPublicId,
            $firstName,
            $lastName,
            $email,
            $phone,
            $expectedUpdatedAt,
            $ipAddress,
        ): User {
            /** @var User $target */
            $target = User::query()
                ->where('public_id', $customerPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->role !== UserRole::Customer) {
                throw new AuthorizationException('Only customer accounts can have their contact details updated.');
            }

            $currentUpdatedAtIso = $target->updated_at?->toIso8601String() ?? '';
            if ($currentUpdatedAtIso !== $expectedUpdatedAt) {
                throw new AdminCustomerContactConflict((string) $target->public_id, $currentUpdatedAtIso);
            }

            $previousValues = [
                'first_name' => (string) $target->first_name,
                'last_name' => (string) $target->last_name,
                'email' => (string) $target->email,
                'phone' => $target->phone !== null ? (string) $target->phone : null,
            ];

            $newValues = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
            ];

            /** @var list<string> $changed */
            $changed = [];
            /** @var array<string, string|null> $previous */
            $previous = [];
            /** @var array<string, string|null> $new */
            $new = [];

            foreach ($newValues as $field => $newValue) {
                if ($previousValues[$field] !== $newValue) {
                    $changed[] = $field;
                    $previous[$field] = $previousValues[$field];
                    $new[$field] = $newValue;
                }
            }

            if (! empty($changed)) {
                $target->first_name = $firstName;
                $target->last_name = $lastName;
                $target->email = $email;
                $target->phone = $phone;
                $target->save();

                $this->recordStaffAudit->execute(
                    $actor,
                    $target,
                    new StaffAuditEvent(
                        action: 'customers.contact_updated',
                        metadata: [
                            'changed' => $changed,
                            'previous' => $previous,
                            'new' => $new,
                        ],
                        ipAddress: $ipAddress,
                    ),
                );
            }

            return $target;
        });
    }
}

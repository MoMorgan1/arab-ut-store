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

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string|null}  $expected
     *                                                                                                     The values the caller was shown, refused if the row has moved since.
     */
    public function execute(
        User $actor,
        string $customerPublicId,
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        array $expected,
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
            $expected,
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

            $previousValues = [
                'first_name' => (string) $target->first_name,
                'last_name' => (string) $target->last_name,
                'email' => (string) $target->email,
                'phone' => $target->phone !== null ? (string) $target->phone : null,
            ];

            // Compared against the live row rather than a timestamp token: a
            // second-precision updated_at lets two edits inside the same second
            // both look current, and the caller only ever needs to be stopped
            // when a value it was shown has actually moved.
            if ($previousValues !== $expected) {
                throw new AdminCustomerContactConflict((string) $target->public_id, $previousValues);
            }

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

                // A verified-at for a number that no longer exists is a claim
                // about nothing. Clearing an existing phone drops its stamp;
                // replacing one keeps it, per the owner decision that a new
                // value entered by an Admin is trusted without re-verification.
                if ($phone === null) {
                    $target->phone_verified_at = null;
                }

                $target->save();

                $this->recordStaffAudit->execute(
                    $actor,
                    $target,
                    new StaffAuditEvent(
                        action: 'customers.contact_updated',
                        metadata: [
                            'contact_changed' => $changed,
                            'contact_previous' => $previous,
                            'contact_new' => $new,
                        ],
                        ipAddress: $ipAddress,
                    ),
                );
            }

            return $target;
        });
    }
}

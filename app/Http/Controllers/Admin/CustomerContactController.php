<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminCustomerContact;
use App\Enums\AdminPermission;
use App\Exceptions\AdminCustomerContactConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminCustomerContact as UpdateContactRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CustomerContactController extends Controller
{
    public function __construct(
        private readonly UpdateAdminCustomerContact $action,
    ) {}

    public function __invoke(UpdateContactRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CustomersUpdateContact->value);

        try {
            $user = $this->action->execute(
                actor: $actor,
                customerPublicId: $publicId,
                firstName: $request->firstName(),
                lastName: $request->lastName(),
                email: $request->email(),
                phone: $request->phone(),
                expected: $request->expected(),
                ipAddress: $request->ip(),
            );
        } catch (UniqueConstraintViolationException $exception) {
            // Validation checks uniqueness outside the locking transaction, so a
            // concurrent edit to a different customer can still claim the same
            // identifier first. Report the field that lost the race instead of
            // letting the driver exception surface as a 500.
            throw $this->duplicateIdentifierFailure($request, $publicId, $exception);
        } catch (AdminCustomerContactConflict $exception) {
            return response()->json([
                'customer' => $exception->customerPublicId,
                'current' => [
                    'firstName' => $exception->current['first_name'],
                    'lastName' => $exception->current['last_name'],
                    'email' => $exception->current['email'],
                    'phone' => $exception->current['phone'],
                ],
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'data' => [
                'firstName' => (string) $user->first_name,
                'lastName' => (string) $user->last_name,
                'email' => (string) $user->email,
                'phone' => $user->phone !== null ? (string) $user->phone : null,
                'updatedAt' => $user->updated_at?->utc()->toIso8601String() ?? now()->utc()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }

    /**
     * Translate a unique-index violation back into the field that lost the race.
     */
    private function duplicateIdentifierFailure(
        UpdateContactRequest $request,
        string $publicId,
        UniqueConstraintViolationException $exception,
    ): Throwable {
        $errors = [];

        if ($this->identifierTaken('email', $request->email(), $publicId)) {
            $errors['email'] = [trans('validation.unique', ['attribute' => 'email'])];
        }

        $phone = $request->phone();

        if ($phone !== null && $this->identifierTaken('phone', $phone, $publicId)) {
            $errors['phone'] = [trans('validation.unique', ['attribute' => 'phone'])];
        }

        if ($errors === []) {
            return $exception;
        }

        return ValidationException::withMessages($errors);
    }

    private function identifierTaken(string $column, string $value, string $publicId): bool
    {
        return User::query()
            ->where($column, $value)
            ->where('public_id', '!=', $publicId)
            ->exists();
    }
}

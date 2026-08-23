<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminCustomerContact;
use App\Enums\AdminPermission;
use App\Exceptions\AdminCustomerContactConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminCustomerContact as UpdateContactRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

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
                expectedUpdatedAt: $request->expectedUpdatedAt(),
                ipAddress: $request->ip(),
            );
        } catch (AdminCustomerContactConflict $exception) {
            return response()->json([
                'customer' => $exception->customerPublicId,
                'updatedAt' => $exception->currentUpdatedAt,
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
                'updatedAt' => $user->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

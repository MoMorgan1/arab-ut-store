<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateServicePriceSchedule;
use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Exceptions\AdminServicePricingConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateServicePriceScheduleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ServicePricingController extends Controller
{
    public function __construct(
        private readonly UpdateServicePriceSchedule $action,
    ) {}

    public function __invoke(UpdateServicePriceScheduleRequest $request, string $serviceType): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::SettingsManage->value);

        $type = ServiceType::tryFrom($serviceType);

        if ($type === null || ! in_array($type, [ServiceType::FutChampions, ServiceType::Rivals, ServiceType::Coins], true)) {
            throw ValidationException::withMessages([
                'service_type' => ['The requested service type is not supported.'],
            ]);
        }

        try {
            $schedule = $this->action->execute(
                actor: $actor,
                serviceType: $type,
                expectedVersion: $request->expectedVersion(),
                newConfiguration: $request->configuration(),
                ipAddress: $request->ip(),
            );
        } catch (AdminServicePricingConflict $exception) {
            return response()->json([
                'serviceType' => $exception->serviceType,
                'version' => $exception->currentVersion,
                'isActive' => $exception->currentActive,
                'configuration' => $exception->currentConfiguration,
                'message' => $exception->getMessage(),
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'data' => [
                'serviceType' => $schedule->service_type->value,
                'version' => (int) $schedule->version,
                'isActive' => (bool) $schedule->is_active,
                'configuration' => (array) $schedule->configuration,
                'updatedAt' => $schedule->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

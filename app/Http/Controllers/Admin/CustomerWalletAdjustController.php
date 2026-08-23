<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\AdjustCustomerWallet;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustAdminCustomerWallet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CustomerWalletAdjustController extends Controller
{
    public function __construct(
        private readonly AdjustCustomerWallet $action,
    ) {}

    public function __invoke(AdjustAdminCustomerWallet $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::WalletAdjust->value);

        $amountHalalah = $request->amountHalalah();
        $result = $this->action->execute(
            actor: $actor,
            customerPublicId: $publicId,
            amountHalalah: $amountHalalah,
            reason: $request->reason(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'balance' => [
                    'amountMinor' => (string) $result['balance_after_halalah'],
                    'currency' => 'SAR',
                ],
                'entry' => [
                    'id' => (string) $result['entry']->public_id,
                    'type' => $result['entry']->type->value,
                    'direction' => $amountHalalah > 0 ? 'credit' : 'debit',
                    'amount' => [
                        'amountMinor' => (string) $result['entry']->amount_halalah,
                        'currency' => 'SAR',
                    ],
                    'createdAt' => $result['entry']->created_at->toIso8601String(),
                    'reference' => $result['entry']->reference,
                ],
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

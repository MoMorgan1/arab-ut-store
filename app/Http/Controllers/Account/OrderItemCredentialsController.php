<?php

namespace App\Http\Controllers\Account;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Models\User;
use App\ValueObjects\Cart\ManualServiceCredentials;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderItemCredentialsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        $order = Order::query()
            ->where('public_id', (string) $request->route('order'))
            ->where('user_id', $user->id)
            ->firstOrFail();
        $item = OrderItem::query()
            ->where('public_id', (string) $request->route('orderItem'))
            ->where('order_id', $order->id)
            ->whereIn('service_type', [ServiceType::FutChampions, ServiceType::Rivals])
            ->with('secret')
            ->firstOrFail();
        $secret = $item->secret;
        $payload = $secret?->encrypted_payload;

        if (! $secret instanceof OrderItemSecret
            || $secret->deleted_at !== null
            || ! is_array($payload)) {
            abort(404);
        }

        try {
            $credentials = ManualServiceCredentials::fromValidated($payload);
        } catch (DomainException) {
            abort(404);
        }

        if ($credentials->payload() !== $payload
            || $credentials->maskedSummary() !== $secret->masked_summary) {
            abort(404);
        }

        SecretAccessLog::create([
            'order_item_secret_id' => $secret->id,
            'user_id' => $user->id,
            'purpose' => 'customer_order_reveal',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $this->responsePayload($payload)])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function responsePayload(array $payload): array
    {
        if ($payload['platform'] === 'playstation') {
            return [
                'platform' => 'playstation',
                'playstationEmail' => $payload['playstation_email'],
                'playstationPassword' => $payload['playstation_password'],
                'eaBackupCodes' => $payload['ea_backup_codes'],
                'playstationBackupCodes' => $payload['playstation_backup_codes'],
            ];
        }

        return [
            'platform' => 'pc',
            'pcStore' => $payload['pc_store'],
            'eaEmail' => $payload['ea_email'],
            'eaPassword' => $payload['ea_password'],
            'eaBackupCodes' => $payload['ea_backup_codes'],
            ...($payload['pc_store'] === 'steam' ? [
                'steamUsername' => $payload['steam_username'],
                'steamPassword' => $payload['steam_password'],
            ] : []),
        ];
    }
}

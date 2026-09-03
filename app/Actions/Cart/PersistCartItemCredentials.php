<?php

namespace App\Actions\Cart;

use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\ValueObjects\Cart\ManualServiceCredentials;

final readonly class PersistCartItemCredentials
{
    public const POLICY_VERSION = 'store-fulfillment-2026-08-12';

    /** @param array<string, mixed> $credentials */
    public function execute(CartItem $cartItem, array $credentials): void
    {
        $secret = new CartItemSecret([
            'cart_item_id' => $cartItem->id,
        ]);
        $this->replace($secret, $credentials);
    }

    /** @param array<string, mixed> $credentials */
    public function replace(CartItemSecret $secret, array $credentials): void
    {
        $secret->forceFill([
            'masked_summary' => [
                'has_password' => true,
                'backup_code_count' => count($credentials['backup_codes']),
            ],
            'retained_until' => null,
            'deleted_at' => null,
        ]);
        $secret->encrypted_payload = $this->payload($credentials);
        $secret->save();
    }

    /**
     * Re-persists an edited manual-service secret through the same value
     * object the add path uses, so the masked summary the cart reads stays
     * in sync with the payload and `credentialsReady` keeps meaning it.
     */
    public function replaceManual(CartItemSecret $secret, ManualServiceCredentials $credentials): void
    {
        $secret->forceFill([
            'masked_summary' => $credentials->maskedSummary(),
            'retained_until' => null,
            'deleted_at' => null,
        ]);
        $secret->encrypted_payload = $credentials->payload();
        $secret->save();
    }

    /** @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function payload(array $credentials): array
    {
        $payload = [
            'ea_email' => $credentials['ea_email'],
            'ea_password' => $credentials['ea_password'],
            'backup_codes' => array_values($credentials['backup_codes']),
        ];

        if (! array_key_exists('companion_market_open', $credentials)) {
            return $payload;
        }

        if (array_key_exists('current_balance', $credentials)) {
            $payload['current_balance'] = (int) $credentials['current_balance'];
        }

        return [
            ...$payload,
            'companion_market_open' => true,
            'policy_version' => self::POLICY_VERSION,
            'policy_accepted_at' => now()->utc()->toIso8601String(),
        ];
    }
}

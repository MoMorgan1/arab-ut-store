<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResolveCartOwner
{
    public const SESSION_KEY = 'coins_guest_owner_token';

    public function forRequest(Request $request): CartOwner
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser instanceof Authenticatable) {
            return CartOwner::user($this->authenticatedUserId($authenticatedUser));
        }

        $existingOwner = $this->existingGuestForRequest($request);

        if ($existingOwner !== null) {
            return $existingOwner;
        }

        $rawToken = bin2hex(random_bytes(32));
        $request->session()->put(self::SESSION_KEY, $rawToken);

        return $this->ownerForRawToken($rawToken);
    }

    public function existingGuestForRequest(Request $request): ?CartOwner
    {
        $rawToken = $request->session()->get(self::SESSION_KEY);

        if (! is_string($rawToken) || preg_match('/\A[0-9a-f]{64}\z/D', $rawToken) !== 1) {
            return null;
        }

        return $this->ownerForRawToken($rawToken);
    }

    private function ownerForRawToken(string $rawToken): CartOwner
    {
        $currentOwner = CartOwner::guest(hash_hmac('sha256', $rawToken, $this->applicationKey()));
        $previousOwners = $this->previousOwners($rawToken, $currentOwner);

        if ($previousOwners === []) {
            return $currentOwner;
        }

        return $this->rekeyActiveCart($currentOwner, $previousOwners);
    }

    private function authenticatedUserId(Authenticatable $user): int
    {
        $identifier = $user->getAuthIdentifier();

        if (! is_int($identifier) && (! is_string($identifier) || ! ctype_digit($identifier))) {
            throw new RuntimeException('The authenticated cart owner is unavailable.');
        }

        return (int) $identifier;
    }

    private function applicationKey(): string
    {
        $applicationKey = config('app.key');

        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new RuntimeException('The application key is unavailable.');
        }

        return $applicationKey;
    }

    /** @return list<CartOwner> */
    private function previousOwners(string $rawToken, CartOwner $currentOwner): array
    {
        $previousKeys = config('app.previous_keys', []);

        if (! is_array($previousKeys)) {
            return [];
        }

        $owners = [];

        foreach ($previousKeys as $previousKey) {
            if (! is_string($previousKey) || $previousKey === '') {
                continue;
            }

            $owner = CartOwner::guest(hash_hmac('sha256', $rawToken, $previousKey));

            if ($owner->databaseKey() !== $currentOwner->databaseKey()) {
                $owners[$owner->databaseKey()] = $owner;
            }
        }

        return array_values($owners);
    }

    /** @param list<CartOwner> $previousOwners */
    private function rekeyActiveCart(CartOwner $currentOwner, array $previousOwners): CartOwner
    {
        return DB::transaction(function () use ($currentOwner, $previousOwners): CartOwner {
            $activeCarts = $this->lockedCandidateCarts($currentOwner, $previousOwners);

            if (! isset($activeCarts[$currentOwner->databaseKey()])) {
                $this->rekeyFirstPreviousCart($activeCarts, $previousOwners, $currentOwner);
            }

            return $currentOwner;
        }, attempts: 3);
    }

    /**
     * @param  list<CartOwner>  $previousOwners
     * @return array<string, Cart>
     */
    private function lockedCandidateCarts(CartOwner $currentOwner, array $previousOwners): array
    {
        $ownerKeys = array_map(
            fn (CartOwner $owner): string => $owner->databaseKey(),
            [$currentOwner, ...$previousOwners],
        );
        $activeCarts = Cart::query()
            ->whereNull('user_id')
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->whereIn('active_owner_key', $ownerKeys)
            ->orderBy('active_owner_key')
            ->lockForUpdate()
            ->get();

        return $activeCarts->keyBy('active_owner_key')->all();
    }

    /**
     * @param  array<string, Cart>  $activeCarts
     * @param  list<CartOwner>  $previousOwners
     */
    private function rekeyFirstPreviousCart(
        array $activeCarts,
        array $previousOwners,
        CartOwner $currentOwner,
    ): void {
        foreach ($previousOwners as $previousOwner) {
            $activeCart = $activeCarts[$previousOwner->databaseKey()] ?? null;

            if ($activeCart === null) {
                continue;
            }

            $activeCart->session_key = $currentOwner->sessionKey();
            $activeCart->save();

            return;
        }
    }
}

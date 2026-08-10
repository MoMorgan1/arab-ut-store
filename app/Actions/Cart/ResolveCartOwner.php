<?php

namespace App\Actions\Cart;

use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
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

        $rawToken = $request->session()->get(self::SESSION_KEY);

        if (! is_string($rawToken) || preg_match('/\A[0-9a-f]{64}\z/D', $rawToken) !== 1) {
            $rawToken = bin2hex(random_bytes(32));
            $request->session()->put(self::SESSION_KEY, $rawToken);
        }

        return CartOwner::guest(hash_hmac('sha256', $rawToken, $this->applicationKey()));
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
}

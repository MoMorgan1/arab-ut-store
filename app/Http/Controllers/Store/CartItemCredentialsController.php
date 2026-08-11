<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\ResolveCartOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CartItemCredentialsRequest;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class CartItemCredentialsController extends Controller
{
    public function show(
        Request $request,
        string $cartItem,
        ResolveCartOwner $resolveCartOwner,
    ): JsonResponse {
        $secret = $this->ownedSecret($cartItem, $resolveCartOwner->forRequest($request));
        $credentials = $this->credentials($secret);

        abort_if($credentials === null, Response::HTTP_NOT_FOUND);

        return response()->json(['data' => [
            'eaEmail' => $credentials['ea_email'],
            'eaPassword' => $credentials['ea_password'],
            'backupCodes' => $credentials['backup_codes'],
        ]]);
    }

    public function update(
        CartItemCredentialsRequest $request,
        string $cartItem,
        ResolveCartOwner $resolveCartOwner,
    ): Response {
        DB::transaction(function () use ($request, $cartItem, $resolveCartOwner): void {
            $secret = $this->ownedSecret(
                $cartItem,
                $resolveCartOwner->forRequest($request),
                lock: true,
            );
            $secret->encrypted_payload = $request->validated();
            $secret->masked_summary = [
                'has_password' => true,
                'backup_code_count' => 3,
            ];
            $secret->retained_until = null;
            $secret->deleted_at = null;
            $secret->save();
        }, attempts: 3);

        return response()->noContent();
    }

    private function ownedSecret(string $publicId, CartOwner $owner, bool $lock = false): CartItemSecret
    {
        $ownedCartIds = Cart::query();
        $ownedCartIds->activeForOwner($owner);

        $query = CartItemSecret::query()
            ->whereHas('cartItem', fn (Builder $item): Builder => $item
                ->where('public_id', $publicId)
                ->whereIn('cart_id', $ownedCartIds->select('id')));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array{ea_email: string, ea_password: string, backup_codes: array{string, string, string}}|null */
    private function credentials(CartItemSecret $secret): ?array
    {
        $payload = $secret->encrypted_payload;

        if (! is_array($payload)
            || count($payload) !== 3
            || array_diff(array_keys($payload), ['ea_email', 'ea_password', 'backup_codes']) !== []
            || ! is_string($payload['ea_email'])
            || ! is_string($payload['ea_password'])
            || ! is_array($payload['backup_codes'])
            || count($payload['backup_codes']) !== 3
            || array_filter(
                $payload['backup_codes'],
                fn (mixed $code): bool => ! is_string($code)
                    || preg_match('/\A[0-9]{8}\z/D', $code) !== 1,
            ) !== []
            || count(array_unique($payload['backup_codes'])) !== 3) {
            return null;
        }

        /** @var array{string, string, string} $codes */
        $codes = array_values($payload['backup_codes']);

        return [
            'ea_email' => $payload['ea_email'],
            'ea_password' => $payload['ea_password'],
            'backup_codes' => $codes,
        ];
    }
}

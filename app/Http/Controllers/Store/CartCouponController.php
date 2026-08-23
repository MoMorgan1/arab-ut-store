<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Checkout\ApplyCoupon;
use App\Checkout\AppliedCoupon;
use App\Enums\CouponRejection;
use App\Exceptions\Checkout\CouponRejected;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartCouponController extends Controller
{
    public function __construct(private readonly ApplyCoupon $applyCoupon) {}

    public function store(Request $request, ResolveCartOwner $resolveCartOwner): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $activeCart = Cart::query()
            ->activeForOwner($resolveCartOwner->forRequest($request))
            ->first();

        if (! $activeCart instanceof Cart || ! $activeCart->items()->exists()) {
            return $this->rejection($request, new CouponRejected(CouponRejection::Invalid));
        }

        $user = $request->user();

        try {
            $applied = $this->applyCoupon->apply(
                $activeCart,
                (string) $validated['code'],
                $user instanceof User ? $user : null,
            );
        } catch (CouponRejected $exception) {
            return $this->rejection($request, $exception);
        }

        return response()
            ->json(['data' => $this->present($applied)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, ResolveCartOwner $resolveCartOwner): JsonResponse
    {
        $activeCart = Cart::query()
            ->activeForOwner($resolveCartOwner->forRequest($request))
            ->first();

        if ($activeCart instanceof Cart) {
            $this->applyCoupon->remove($activeCart);
        }

        return response()
            ->json(['data' => ['removed' => true]])
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return array{code: string, discountType: string, discountHalalah: int} */
    private function present(AppliedCoupon $applied): array
    {
        return [
            'code' => $applied->code,
            'discountType' => $applied->discountType,
            'discountHalalah' => $applied->discountHalalah,
        ];
    }

    private function rejection(Request $request, CouponRejected $exception): JsonResponse
    {
        $localized = $request->route('locale') === 'en';
        $message = match ($exception->reason) {
            CouponRejection::Minimum => (string) trans('store.cart_page.coupon_minimum', [
                'amount' => $this->formatMoney($exception->minimumOrderHalalah),
            ]),
            default => (string) trans("store.cart_page.{$exception->reason->value}", locale: $localized ? 'en' : 'ar'),
        };

        return response()
            ->json(['error' => [
                'code' => $exception->reason->value,
                'message' => $message,
            ]], 422)
            ->header('Cache-Control', 'no-store, private');
    }

    private function formatMoney(int $amountHalalah): string
    {
        return 'SAR '.number_format($amountHalalah / 100, 2);
    }
}

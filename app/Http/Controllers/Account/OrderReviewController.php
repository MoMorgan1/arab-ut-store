<?php

namespace App\Http\Controllers\Account;

use App\Actions\Reviews\SubmitOrderReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreOrderReviewRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class OrderReviewController extends Controller
{
    public function __construct(private readonly SubmitOrderReview $action) {}

    public function store(StoreOrderReviewRequest $request, string $order): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $review = $this->action->execute(
            $user,
            $order,
            $request->rating(),
            $request->body(),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'rating' => (int) $review->rating,
                    'visible' => (bool) $review->is_visible,
                ],
            ])->header('Cache-Control', 'no-store, private');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('account.orders.review.submitted_toast'),
        ]);

        return redirect()->to($this->orderUrl($order));
    }

    private function orderUrl(string $order): string
    {
        return route(
            app()->getLocale() === 'en'
                ? 'localized.account.orders.show'
                : 'account.orders.show',
            ['order' => $order],
            absolute: false,
        );
    }
}

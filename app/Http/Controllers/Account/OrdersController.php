<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Account\Queries\CountLiveOrders;
use App\Account\Queries\ReadLiveOrders;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrdersController extends Controller
{
    public function __construct(
        private readonly ReadLiveOrders $orders,
        private readonly CountLiveOrders $counts,
        private readonly AccountShell $shell,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(ReadLiveOrders::FILTERS)],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $locale = app()->getLocale();

        return Inertia::render('account/orders', [
            ...$this->shell->for($user, $locale),
            'counts' => $this->counts->for($user),
            ...$this->orders->for(
                $user,
                $locale,
                $validated['status'] ?? 'all',
                $validated['q'] ?? null,
            ),
        ]);
    }
}

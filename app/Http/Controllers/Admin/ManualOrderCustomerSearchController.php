<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Queries\SearchManualOrderCustomers;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Finds the customer a manual order is for.
 *
 * A customer must already exist (owner, 2026-09-13); a new one is created on
 * the customers screen. So this only searches, and returns at most eight rows
 * of the four fields a picker shows.
 */
final class ManualOrderCustomerSearchController extends Controller
{
    public function __construct(
        private readonly SearchManualOrderCustomers $search,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::OrdersCreate->value);

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);

        return response()
            ->json(['data' => ['customers' => $this->search->execute((string) $validated['q'])]])
            ->header('Cache-Control', 'no-store, private');
    }
}

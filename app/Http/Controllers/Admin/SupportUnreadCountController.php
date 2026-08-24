<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportUnreadCountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Live tickets whose conversation has a customer message newer than the
        // last staff reply (design 6.1) — not simply every open ticket. A count
        // of all open tickets would never drop when Mohamed answers, so the
        // badge would sit lit and stop meaning anything.
        $count = SupportTicket::query()
            ->live()
            ->whereHas('conversation', function (Builder $conversation): void {
                $conversation->where(function (Builder $unread): void {
                    $unread->whereNull('last_staff_message_at')
                        ->orWhereColumn('last_message_at', '>', 'last_staff_message_at');
                });
            })
            ->count();

        return response()->json([
            'count' => $count,
        ])->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\IssueAdminOrderTrackingLink;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderTrackingLinkController extends Controller
{
    public function __construct(
        private readonly IssueAdminOrderTrackingLink $action,
    ) {}

    public function __invoke(Request $request, string $order): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $payload = $this->action->execute(
            actor: $actor,
            orderHandle: $order,
            ipAddress: $request->ip(),
        );

        // The link is a capability. No shared cache may hold it, and no
        // history entry either - the same reason the reveal endpoint answers
        // this way.
        return response()->json(['data' => $payload], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

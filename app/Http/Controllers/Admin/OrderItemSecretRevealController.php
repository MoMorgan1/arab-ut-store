<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\RevealOrderItemSecret as RevealOrderItemSecretAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RevealOrderItemSecret as RevealOrderItemSecretRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class OrderItemSecretRevealController extends Controller
{
    public function __construct(
        private readonly RevealOrderItemSecretAction $action,
    ) {}

    public function __invoke(
        RevealOrderItemSecretRequest $request,
        string $publicId,
        string $itemPublicId,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $payload = $this->action->execute(
            actor: $actor,
            orderPublicId: $publicId,
            itemPublicId: $itemPublicId,
            purpose: $request->purpose(),
            caseReference: $request->caseReference(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => $payload,
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}

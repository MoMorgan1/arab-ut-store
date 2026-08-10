<?php

namespace App\Http\Middleware;

use App\Validation\CoinsSelectionRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ValidateCoinsCartResume
{
    public function __construct(private readonly CoinsSelectionRules $selectionRules) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $requestInput = $request->query();
        $unknownFields = array_diff(array_keys($requestInput), ['platform', 'delivery', 'quantity']);
        $validator = Validator::make(
            $requestInput,
            $this->selectionRules->for($requestInput['platform'] ?? null, $requestInput['delivery'] ?? null),
        );

        if ($unknownFields !== [] || $validator->fails()) {
            return response()->json([
                'message' => trans('store.cart.validation_error'),
                'errors' => $unknownFields === []
                    ? $validator->errors()
                    : ['request' => [trans('store.cart.unknown_fields')]],
            ], 422)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}

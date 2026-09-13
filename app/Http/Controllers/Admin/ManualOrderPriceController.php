<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SuggestManualOrderPrice;
use App\Enums\AdminPermission;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What the store would have charged for this item.
 *
 * A suggestion, not a quote: nothing is reserved, no price version is claimed,
 * and the figure that reaches the order is whatever staff leave in the field.
 * It exists so the number offered is the storefront's own, and it answers with
 * a reason instead of an error when it cannot price something - a form that
 * refuses to load because Objectives has no price table would be worse than
 * one that asks you to type.
 */
final class ManualOrderPriceController extends Controller
{
    public function __construct(
        private readonly SuggestManualOrderPrice $suggest,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::OrdersCreate->value);

        $validated = $request->validate([
            'service_type' => ['required', Rule::enum(ServiceType::class)],
            'platform' => ['required', Rule::enum(Platform::class)],
            'configuration' => ['nullable', 'array'],
        ]);

        /** @var array<string, mixed> $configuration */
        $configuration = is_array($validated['configuration'] ?? null) ? $validated['configuration'] : [];

        return response()
            ->json(['data' => $this->suggest->execute(
                ServiceType::from((string) $validated['service_type']),
                Platform::from((string) $validated['platform']),
                $this->wholeNumbers($configuration),
            )])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * A query string carries no types, so `coins_quantity=1250` arrives as a
     * string and every `is_int` check in the pricing action would reject it.
     * Only the keys that are genuinely counts are cast, and only when they are
     * written as whole numbers - so "1250x" stays a string and is refused
     * rather than silently becoming 1250.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function wholeNumbers(array $configuration): array
    {
        foreach (['coins_quantity', 'completion_count', 'rank', 'matches_played'] as $key) {
            $value = $configuration[$key] ?? null;

            if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                $configuration[$key] = (int) $value;
            }
        }

        $urgent = $configuration['urgent'] ?? null;

        if (is_string($urgent)) {
            $configuration['urgent'] = in_array($urgent, ['1', 'true'], true);
        }

        return $configuration;
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\DeliveryPhase;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape staff may submit.
 *
 * Every service this store sells is accepted (owner decision, 2026-09-12), so
 * the per-service configuration is validated by `withValidator` rather than by
 * one flat rule set: the fields Rivals needs are meaningless on Coins, and
 * requiring the union of them would make the form impossible to satisfy.
 */
final class CreateManualOrderRequest extends FormRequest
{
    /**
     * The Rivals ladder, bottom to top, as `RivalsPricing::LADDER` declares it.
     * Mirrored rather than exposed because the order it is written in is the
     * only thing that says which way a promotion climbs.
     */
    private const RIVALS_LADDER = ['7', '6', '5', '4', '3', '2', '1', 'elite'];

    public function authorize(): bool
    {
        return $this->user()?->can(AdminPermission::OrdersCreate->value) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'max:64'],
            'is_gift' => ['required', 'boolean'],

            // Present only on a bank transfer, and then completely. The amount
            // is checked against the items in the action, which is the only
            // place that knows the total.
            'payment' => ['required_if:is_gift,false', 'prohibited_if:is_gift,true', 'array'],
            'payment.amount_halalah' => ['required_with:payment', 'integer:strict', 'min:1', 'max:100000000'],
            'payment.reference' => ['required_with:payment', 'string', 'max:100'],
            'payment.received_at' => ['required_with:payment', 'date', 'before_or_equal:today'],

            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.service_type' => ['required', Rule::enum(ServiceType::class)],
            'items.*.platform' => ['required', Rule::enum(Platform::class)],
            'items.*.product_variant_id' => ['nullable', 'string', 'max:64'],
            'items.*.price_halalah' => ['required', 'integer:strict', 'min:0', 'max:100000000'],

            // The email is required and the password is not (owner decision,
            // 2026-09-13). An item placed by hand has already been signed into
            // by the person creating the order.
            'items.*.credentials' => ['nullable', 'array'],
            'items.*.credentials.ea_email' => ['required_with:items.*.credentials', 'email', 'max:255'],
            'items.*.credentials.ea_password' => ['nullable', 'string', 'max:255'],
            'items.*.credentials.backup_codes' => ['nullable', 'array', 'max:6'],
            'items.*.credentials.backup_codes.*' => ['string', 'max:32'],

            'items.*.placement' => ['nullable', 'array'],
            'items.*.placement.supplier' => ['required_with:items.*.placement', Rule::enum(Supplier::class)],
            'items.*.placement.supplier_order_id' => ['required_with:items.*.placement', 'string', 'max:100'],
            'items.*.placement.delivery_phase' => ['required_with:items.*.placement', Rule::enum(DeliveryPhase::class)],
            // A challenge placement is refused without ids, because a challenge
            // job carrying none is untrackable the moment it lands. Which items
            // need them is decided per phase in `validatePlacement` below.
            'items.*.placement.challenge_ids' => ['nullable', 'array', 'max:30'],
            // UUIDs, which is what the supplier issues and what
            // `ChallengeIds::isValid()` accepts. Anything else is refused by the
            // placement action as `invalid_challenge_ids`, so it is refused here
            // where the person filling the form can still fix it.
            'items.*.placement.challenge_ids.*' => ['uuid'],

            // `present`, not `required`: Laravel counts an empty array as
            // absent, and Objectives is priced by agreement with nothing to
            // configure - so `required` made the one service with no
            // configuration the one service that could not be ordered.
            'items.*.configuration' => ['present', 'array'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $items */
            $items = is_array($this->input('items')) ? $this->input('items') : [];

            foreach ($items as $index => $item) {
                $service = ServiceType::tryFrom((string) ($item['service_type'] ?? ''));

                if (! $service instanceof ServiceType) {
                    continue;
                }

                $this->validateConfiguration($validator, $index, $service, $item);
                $this->validatePlacement($validator, $index, $service, $item);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateConfiguration(Validator $validator, int $index, ServiceType $service, array $item): void
    {
        $configuration = is_array($item['configuration'] ?? null) ? $item['configuration'] : [];

        foreach ($this->requiredConfigurationKeys($service, $configuration) as $key) {
            $value = $configuration[$key] ?? null;

            if ($value === null || $value === '') {
                $validator->errors()->add(
                    "items.{$index}.configuration.{$key}",
                    "This service needs {$key}.",
                );
            }
        }

        $this->validateConfigurationValues($validator, $index, $service, $configuration);
    }

    /**
     * The values the storefront's own requests accept, so a manual order
     * cannot store a division, rank or mode that no pricing table knows and
     * no fulfillment step can read.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function validateConfigurationValues(
        Validator $validator,
        int $index,
        ServiceType $service,
        array $configuration,
    ): void {
        $refuse = function (string $key, string $message) use ($validator, $index): void {
            $validator->errors()->add("items.{$index}.configuration.{$key}", $message);
        };

        if ($service === ServiceType::Rivals) {
            $mode = $configuration['mode'] ?? null;

            if ($mode !== null && ! in_array($mode, ['promotion', 'weekly_matches'], true)) {
                $refuse('mode', 'Rivals is bought either as a promotion or as weekly matches.');
            }

            foreach (['current_division', 'target_division'] as $key) {
                $division = $configuration[$key] ?? null;

                if ($division !== null && ! in_array($division, self::RIVALS_LADDER, true)) {
                    $refuse($key, 'That is not a division on the Rivals ladder.');
                }
            }

            $from = array_search($configuration['current_division'] ?? null, self::RIVALS_LADDER, true);
            $to = array_search($configuration['target_division'] ?? null, self::RIVALS_LADDER, true);

            if ($mode === 'promotion' && $from !== false && $to !== false && $to <= $from) {
                $refuse('target_division', 'A promotion climbs the ladder, so the target sits above the current division.');
            }
        }

        if ($service === ServiceType::FutChampions) {
            $rank = $configuration['rank'] ?? null;

            if ($rank !== null && (! is_int($rank) || $rank < 1 || $rank > 6)) {
                $refuse('rank', 'FUT Champions ranks run from 1 to 6.');
            }

            $matches = $configuration['matches_played'] ?? null;

            if ($matches !== null && (! is_int($matches) || $matches < 0 || $matches > 100)) {
                $refuse('matches_played', 'Matches played runs from 0 to 100.');
            }
        }

        if ($service === ServiceType::Coins) {
            $quantity = $configuration['coins_quantity'] ?? null;

            if ($quantity !== null && (! is_int($quantity) || $quantity < 1)) {
                $refuse('coins_quantity', 'A coins order carries a whole number of thousands.');
            }
        }

        if ($service === ServiceType::Sbc) {
            $completions = $configuration['completion_count'] ?? null;

            if ($completions !== null && (! is_int($completions) || $completions < 1)) {
                $refuse('completion_count', 'An SBC order solves at least one challenge.');
            }
        }
    }

    /**
     * The keys the store cannot present the item without. Deliberately a subset
     * of `SafeOrderItemConfiguration::keys()`: that allowlist says what may be
     * stored, and this says what must be, so an optional key can be added to
     * one without becoming mandatory in the other.
     *
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function requiredConfigurationKeys(ServiceType $service, array $configuration): array
    {
        return match ($service) {
            ServiceType::Coins => ['coins_quantity'],
            ServiceType::Sbc => ['completion_count'],
            ServiceType::FutChampions => ['rank', 'matches_played'],
            // Weekly matches are a second way to buy Rivals: the same account
            // played for a week without promoting, so there is no route to
            // name. RivalsCartRequest:31 goes further and forbids the two
            // divisions outright on that mode; requiring them here would have
            // made the option impossible to buy through this form.
            ServiceType::Rivals => ($configuration['mode'] ?? null) === 'weekly_matches'
                ? ['mode']
                : ['mode', 'current_division', 'target_division'],
            ServiceType::Objectives => [],
        };
    }

    /**
     * A supplier reference only means something for a service a supplier bot
     * delivers. The three manual services are delivered by a person, so a
     * reference pasted onto one would create a job nothing can ever read.
     *
     * @param  array<string, mixed>  $item
     */
    private function validatePlacement(Validator $validator, int $index, ServiceType $service, array $item): void
    {
        $placement = $item['placement'] ?? null;

        if (! is_array($placement) || $placement === []) {
            return;
        }

        if (! in_array($service, [ServiceType::Coins, ServiceType::Sbc], true)) {
            $validator->errors()->add(
                "items.{$index}.placement",
                'Only Coins and SBC are delivered by a supplier, so only they carry a reference.',
            );

            return;
        }

        // UTT delivers coins only, so a challenge reference cannot be one of
        // theirs - the same asymmetry `Supplier::handlesChallenges()` carries.
        $supplier = Supplier::tryFrom((string) ($placement['supplier'] ?? ''));
        $phase = DeliveryPhase::tryFrom((string) ($placement['delivery_phase'] ?? ''));

        if ($supplier instanceof Supplier && $phase === DeliveryPhase::Challenge && ! $supplier->handlesChallenges()) {
            $validator->errors()->add(
                "items.{$index}.placement.supplier",
                'This supplier does not deliver challenges.',
            );
        }

        // Asked for here so the person filling the form is told, rather than
        // discovering it as a failed write: RecordSupplierPlacement returns
        // `challenge_ids_required` and the whole order rolls back.
        $challengeIds = $placement['challenge_ids'] ?? [];

        if ($phase === DeliveryPhase::Challenge && (! is_array($challengeIds) || $challengeIds === [])) {
            $validator->errors()->add(
                "items.{$index}.placement.challenge_ids",
                'A challenge already placed needs the challenge IDs, or nothing can track it.',
            );
        }

        if ($phase === DeliveryPhase::Coins && is_array($challengeIds) && $challengeIds !== []) {
            $validator->errors()->add(
                "items.{$index}.placement.challenge_ids",
                'The coins phase carries no challenge IDs.',
            );
        }
    }
}

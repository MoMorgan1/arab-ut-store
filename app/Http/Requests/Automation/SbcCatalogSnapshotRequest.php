<?php

namespace App\Http\Requests\Automation;

use App\Enums\ServiceType;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use DomainException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SbcCatalogSnapshotRequest extends CatalogSnapshotRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'products.*.serviceType' => [
                'required',
                Rule::in([ServiceType::Sbc->value]),
            ],
        ]);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $products = $this->input('products');

                if (! is_array($products)) {
                    return;
                }

                foreach ($products as $productIndex => $product) {
                    if (! is_array($product) || ! is_array($product['variants'] ?? null)) {
                        continue;
                    }

                    $expectedCounts = null;

                    foreach ($product['variants'] as $variantIndex => $variant) {
                        if (! is_array($variant)
                            || ! is_array($variant['configuration'] ?? null)
                            || ! is_int($variant['priceMinor'] ?? null)
                            || (($variant['salePriceMinor'] ?? null) !== null
                                && ! is_int($variant['salePriceMinor']))) {
                            continue;
                        }

                        $effectiveMinor = is_int($variant['salePriceMinor'] ?? null)
                            ? $variant['salePriceMinor']
                            : $variant['priceMinor'];

                        try {
                            $pricing = SbcCompletionPricing::fromConfiguration(
                                $variant['configuration'],
                                $effectiveMinor,
                                requireDeclared: true,
                            );
                        } catch (DomainException) {
                            $validator->errors()->add(
                                "products.{$productIndex}.variants.{$variantIndex}.configuration.completionPricing",
                                'The SBC completion pricing is invalid.',
                            );

                            continue;
                        }

                        if ($expectedCounts === null) {
                            $expectedCounts = $pricing->completionCounts();

                            continue;
                        }

                        if ($pricing->completionCounts() !== $expectedCounts) {
                            $validator->errors()->add(
                                "products.{$productIndex}.variants.{$variantIndex}.configuration.completionPricing",
                                'Every platform must offer the same SBC completion quantities.',
                            );
                        }
                    }
                }
            },
        ];
    }
}

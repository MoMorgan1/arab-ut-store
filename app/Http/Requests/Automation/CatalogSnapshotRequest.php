<?php

namespace App\Http\Requests\Automation;

use App\Enums\Market;
use App\Enums\Platform;
use App\Enums\ServiceType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CatalogSnapshotRequest extends FormRequest
{
    /** @var list<string> */
    private const TOP_LEVEL_KEYS = [
        'schemaVersion',
        'eventId',
        'runId',
        'generatedAt',
        'completeSnapshot',
        'categories',
        'products',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'eventId' => ['required', 'ulid'],
            'runId' => ['required', 'ulid'],
            'generatedAt' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'completeSnapshot' => ['required', 'accepted'],
            'categories' => ['required', 'array', 'max:50'],
            'categories.*' => 'array:externalId,slug,name,description,sortOrder,visible',
            'categories.*.externalId' => ['required', 'string', 'max:120', 'distinct:strict'],
            'categories.*.slug' => ['required', 'string', 'max:255', 'distinct:strict'],
            'categories.*.name' => ['required', 'array:ar,en'],
            'categories.*.name.ar' => ['required', 'string', 'max:255'],
            'categories.*.name.en' => ['required', 'string', 'max:255'],
            'categories.*.description' => ['required', 'array:ar,en'],
            'categories.*.description.ar' => ['nullable', 'string', 'max:2000'],
            'categories.*.description.en' => ['nullable', 'string', 'max:2000'],
            'categories.*.sortOrder' => ['required', 'integer', 'min:0'],
            'categories.*.visible' => ['required', 'boolean'],
            'products' => ['required', 'array', 'max:2000'],
            'products.*' => 'array:externalId,categoryExternalId,slug,serviceType,name,description,sortOrder,visible,variants,media',
            'products.*.externalId' => ['required', 'string', 'max:120', 'distinct:strict'],
            'products.*.categoryExternalId' => ['nullable', 'string', 'max:120'],
            'products.*.slug' => ['required', 'string', 'max:255', 'distinct:strict'],
            'products.*.serviceType' => ['required', Rule::enum(ServiceType::class)],
            'products.*.name' => ['required', 'array:ar,en'],
            'products.*.name.ar' => ['required', 'string', 'max:255'],
            'products.*.name.en' => ['required', 'string', 'max:255'],
            'products.*.description' => ['required', 'array:ar,en'],
            'products.*.description.ar' => ['nullable', 'string', 'max:5000'],
            'products.*.description.en' => ['nullable', 'string', 'max:5000'],
            'products.*.sortOrder' => ['required', 'integer', 'min:0'],
            'products.*.visible' => ['required', 'boolean'],
            'products.*.variants' => ['required', 'array', 'min:1', 'max:10'],
            'products.*.variants.*' => 'array:externalId,sku,platform,market,currency,name,priceMinor,salePriceMinor,priceVersion,active,configuration',
            'products.*.variants.*.externalId' => ['required', 'string', 'max:120', 'distinct:strict'],
            'products.*.variants.*.sku' => ['required', 'string', 'max:255', 'distinct:strict'],
            'products.*.variants.*.platform' => ['required', Rule::enum(Platform::class)],
            'products.*.variants.*.market' => ['required', Rule::enum(Market::class)],
            'products.*.variants.*.currency' => ['required', 'in:SAR'],
            'products.*.variants.*.name' => ['required', 'array:ar,en'],
            'products.*.variants.*.name.ar' => ['nullable', 'string', 'max:255'],
            'products.*.variants.*.name.en' => ['nullable', 'string', 'max:255'],
            'products.*.variants.*.priceMinor' => ['required', 'integer', 'min:0'],
            'products.*.variants.*.salePriceMinor' => ['nullable', 'integer', 'min:0'],
            'products.*.variants.*.priceVersion' => ['required', 'integer', 'min:1'],
            'products.*.variants.*.active' => ['required', 'boolean'],
            'products.*.variants.*.configuration' => ['required', 'array'],
            'products.*.media' => ['present', 'array', 'max:5'],
            'products.*.media.*' => 'array:url,alt,sortOrder',
            'products.*.media.*.url' => ['required', 'url:https', 'max:2048'],
            'products.*.media.*.alt' => ['required', 'array:ar,en'],
            'products.*.media.*.alt.ar' => ['nullable', 'string', 'max:255'],
            'products.*.media.*.alt.en' => ['nullable', 'string', 'max:255'],
            'products.*.media.*.sortOrder' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknownKeys = array_diff(array_keys($this->all()), self::TOP_LEVEL_KEYS);

                if ($unknownKeys !== []) {
                    $validator->errors()->add('snapshot', 'The snapshot contains undeclared fields.');
                }

                if ($this->header('X-ArabUT-Event') !== $this->input('eventId')) {
                    $validator->errors()->add('eventId', 'The signed event does not match the snapshot event.');
                }

                $this->validateCompleteSnapshot($validator);
                $this->validateGeneratedAt($validator);
                $this->validateCatalogRelationships($validator);
            },
        ];
    }

    private function validateCompleteSnapshot(Validator $validator): void
    {
        if ($this->input('completeSnapshot') !== true) {
            $validator->errors()->add('completeSnapshot', 'The snapshot must be explicitly complete.');
        }
    }

    private function validateGeneratedAt(Validator $validator): void
    {
        $value = $this->input('generatedAt');

        if (! is_string($value)) {
            return;
        }

        $generatedAt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );

        if ($generatedAt !== false && abs(now()->getTimestamp() - $generatedAt->getTimestamp()) > 300) {
            $validator->errors()->add('generatedAt', 'The snapshot generation time is outside the freshness window.');
        }
    }

    private function validateCatalogRelationships(Validator $validator): void
    {
        $categories = $this->input('categories');
        $products = $this->input('products');

        if (! is_array($categories) || ! is_array($products)) {
            return;
        }

        $categoryIds = collect($categories)
            ->pluck('externalId')
            ->filter(fn (mixed $value): bool => is_string($value))
            ->all();

        foreach ($products as $productIndex => $product) {
            if (! is_array($product)) {
                continue;
            }

            $categoryId = $product['categoryExternalId'] ?? null;

            if ($categoryId !== null && ! in_array($categoryId, $categoryIds, true)) {
                $validator->errors()->add(
                    "products.{$productIndex}.categoryExternalId",
                    'The referenced category is not part of this snapshot.',
                );
            }

            foreach (($product['variants'] ?? []) as $variantIndex => $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $platform = is_string($variant['platform'] ?? null)
                    ? Platform::tryFrom($variant['platform'])
                    : null;

                if ($platform !== null && ($variant['market'] ?? null) !== $platform->market()->value) {
                    $validator->errors()->add(
                        "products.{$productIndex}.variants.{$variantIndex}.market",
                        'The market must match the selected platform.',
                    );
                }
            }
        }
    }
}

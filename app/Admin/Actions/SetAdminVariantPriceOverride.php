<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Exceptions\AdminVariantPriceConflict;
use App\Models\ProductVariant;
use App\Models\User;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets or clears the admin price override on a variant.
 *
 * Writes only `admin_price_halalah` and `admin_completion_pricing`, never the
 * automation columns - so the next catalog snapshot has nothing to revert.
 *
 * Two properties keep money correct. The override is validated by constructing
 * the domain value object before saving, so the only price that can be stored
 * is one checkout can read back. And every change bumps `price_version`, which
 * is the version checkout re-validates under a lock - so a customer holding a
 * quote at the old price cannot complete the order at it.
 */
final class SetAdminVariantPriceOverride
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $completionPricing  the tier table, or null to keep automation's
     */
    public function execute(
        User $actor,
        string $variantPublicId,
        ?int $priceHalalah,
        ?array $completionPricing,
        int $expectedPriceVersion,
        ?string $ipAddress = null,
    ): ProductVariant {
        if (! $actor->is_active || ! $actor->can(AdminPermission::CatalogManage->value)) {
            throw new AuthorizationException('This action requires catalog.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may override prices.');
        }

        return DB::transaction(function () use (
            $actor,
            $variantPublicId,
            $priceHalalah,
            $completionPricing,
            $expectedPriceVersion,
            $ipAddress,
        ): ProductVariant {
            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()
                ->where('public_id', $variantPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $variant->price_version !== $expectedPriceVersion) {
                throw new AdminVariantPriceConflict(
                    (string) $variant->public_id,
                    (int) $variant->price_version,
                    $variant->effectivePriceHalalah(),
                );
            }

            $previousPrice = $variant->effectivePriceHalalah();
            $previousOverride = $variant->admin_price_halalah;

            if ($priceHalalah !== null) {
                $this->assertReadableByCheckout($variant, $priceHalalah, $completionPricing);
            }

            $variant->admin_price_halalah = $priceHalalah;
            $variant->admin_completion_pricing = $priceHalalah === null ? null : $completionPricing;
            // Bumped on every change, including clearing: an in-flight cart that
            // captured the previous version must fail checkout re-validation.
            $variant->price_version = ((int) $variant->price_version) + 1;
            $variant->save();

            $this->recordStaffAudit->execute(
                $actor,
                $variant,
                new StaffAuditEvent(
                    action: $priceHalalah === null
                        ? 'catalog.price_override_cleared'
                        : 'catalog.price_override_set',
                    metadata: [
                        'sku' => (string) $variant->sku,
                        'previous_override_halalah' => $previousOverride,
                        'new_override_halalah' => $priceHalalah,
                        'previous_effective_halalah' => $previousPrice,
                        'new_effective_halalah' => $variant->effectivePriceHalalah(),
                        'new_price_version' => (int) $variant->price_version,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $variant;
        });
    }

    /**
     * The store must be able to price this variant with the override in place.
     * Constructing the value object is the same check checkout performs, so a
     * configuration it would reject can never be saved.
     *
     * @param  array<string, mixed>|null  $completionPricing
     */
    private function assertReadableByCheckout(
        ProductVariant $variant,
        int $priceHalalah,
        ?array $completionPricing,
    ): void {
        if ($variant->service_type !== ServiceType::Sbc) {
            return;
        }

        $configuration = $completionPricing !== null
            ? ['completionPricing' => $completionPricing]
            : (is_array($variant->configuration) ? $variant->configuration : []);

        try {
            SbcCompletionPricing::fromConfiguration($configuration, $priceHalalah, requireDeclared: false);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'completion_pricing' => [$exception->getMessage()],
            ]);
        }
    }
}

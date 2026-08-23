<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminProductVisibilityConflict;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a product off the storefront, or puts it back.
 *
 * This writes `admin_hidden_at`, never `is_visible` or `archived_at`. Those two
 * belong to the catalog snapshot, which rewrites them on every run - so an admin
 * edit to them would look like it worked and then silently revert on the next
 * sync. Because this column is untouched by ingestion, the decision holds for
 * automation-owned products too, and it survives a product disappearing from a
 * snapshot and later coming back.
 */
final class SetAdminProductStorefrontVisibility
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $productPublicId,
        bool $hidden,
        bool $expectedHidden,
        ?string $ipAddress = null,
    ): Product {
        if (! $actor->is_active || ! $actor->can(AdminPermission::CatalogManage->value)) {
            throw new AuthorizationException('This action requires catalog.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may change storefront visibility.');
        }

        return DB::transaction(function () use ($actor, $productPublicId, $hidden, $expectedHidden, $ipAddress): Product {
            /** @var Product $product */
            $product = Product::query()
                ->where('public_id', $productPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $previouslyHidden = $product->admin_hidden_at !== null;

            if ($previouslyHidden !== $expectedHidden) {
                throw new AdminProductVisibilityConflict(
                    (string) $product->public_id,
                    $previouslyHidden,
                );
            }

            if ($previouslyHidden === $hidden) {
                return $product;
            }

            $product->admin_hidden_at = $hidden ? now() : null;
            $product->save();

            $this->recordStaffAudit->execute(
                $actor,
                $product,
                new StaffAuditEvent(
                    action: $hidden ? 'products.hidden_from_store' : 'products.restored_to_store',
                    metadata: [
                        'authority' => $product->authority->value,
                        'previous_hidden' => $previouslyHidden,
                        'new_hidden' => $hidden,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $product;
        });
    }
}

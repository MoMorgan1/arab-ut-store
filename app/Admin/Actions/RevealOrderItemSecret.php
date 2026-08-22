<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Exceptions\AdminSecretPurged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class RevealOrderItemSecret
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<Order|OrderItem|OrderItemSecret>
     * @throws AdminSecretPurged
     */
    public function execute(
        User $actor,
        string $orderPublicId,
        string $itemPublicId,
        string $purpose,
        ?string $caseReference,
        ?string $ipAddress,
    ): array {
        if (! $actor->is_active
            || ! $actor->can(AdminPermission::OrdersView->value)
            || ! $actor->can(AdminPermission::OrderCredentialsView->value)) {
            throw new AuthorizationException('Actor is not authorized to reveal credentials.');
        }

        /** @var Order $order */
        $order = Order::query()
            ->where('public_id', $orderPublicId)
            ->firstOrFail();

        /** @var OrderItem $item */
        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('public_id', $itemPublicId)
            ->firstOrFail();

        /** @var OrderItemSecret|null $secret */
        $secret = OrderItemSecret::query()
            ->where('order_item_id', $item->id)
            ->first();

        if ($secret === null) {
            throw (new ModelNotFoundException)->setModel(OrderItemSecret::class);
        }

        $deletedAt = $secret->getAttribute('deleted_at');
        $retainedUntil = $secret->getAttribute('retained_until');
        $retentionExpired = $retainedUntil instanceof CarbonInterface && $retainedUntil->isPast();

        if ($deletedAt !== null || $retentionExpired) {
            throw new AdminSecretPurged;
        }

        return DB::transaction(function () use ($actor, $item, $secret, $purpose, $caseReference, $ipAddress): array {
            SecretAccessLog::query()->create([
                'order_item_secret_id' => $secret->id,
                'user_id' => $actor->id,
                'purpose' => $purpose,
                'case_reference' => $caseReference,
                'ip_address' => $ipAddress,
            ]);

            $this->recordStaffAudit->execute(
                actor: $actor,
                subject: $secret,
                event: new StaffAuditEvent(
                    action: 'secrets.revealed',
                    metadata: [
                        'purpose' => $purpose,
                        'case_reference' => $caseReference,
                        'order_item_public_id' => (string) $item->public_id,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            /** @var array<string, mixed> $payload */
            $payload = is_array($secret->encrypted_payload) ? $secret->encrypted_payload : [];

            return $payload;
        });
    }
}

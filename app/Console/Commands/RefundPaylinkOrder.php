<?php

namespace App\Console\Commands;

use App\Actions\Checkout\RefundPaylinkOrder as RefundOrder;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

final class RefundPaylinkOrder extends Command
{
    protected $signature = 'payments:refund-paylink {order : Order number or public ULID} {--reason= : Staff-approved refund reason} {--actor= : Admin or staff email}';

    protected $description = 'Issue one fail-closed full Paylink refund for an order';

    public function handle(RefundOrder $refundOrder): int
    {
        $reason = $this->option('reason');
        $actorEmail = $this->option('actor');

        if (! is_string($reason) || trim($reason) === ''
            || ! is_string($actorEmail) || trim($actorEmail) === '') {
            $this->error('Both --reason and --actor are required.');

            return self::INVALID;
        }

        $actor = User::query()->where('email', $actorEmail)->first();
        $orderIdentifier = (string) $this->argument('order');
        $order = Order::query()
            ->where('order_number', $orderIdentifier)
            ->orWhere('public_id', $orderIdentifier)
            ->first();

        if (! $actor instanceof User
            || ! in_array($actor->role, [UserRole::Admin, UserRole::Staff], true)
            || ! $order instanceof Order) {
            $this->error('The authorized staff account or order was not found.');

            return self::FAILURE;
        }

        try {
            $refund = $refundOrder->execute($order, $reason, $actor);
        } catch (Throwable) {
            $this->error('The refund was not completed. Review the refund record before retrying.');

            return self::FAILURE;
        }

        $this->info('Paylink refund completed: '.$refund->public_id);

        return self::SUCCESS;
    }
}

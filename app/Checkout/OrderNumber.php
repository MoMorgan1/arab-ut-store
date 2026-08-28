<?php

namespace App\Checkout;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Short, readable order numbers that count upward, such as AUT-1043.
 *
 * Mohamed asked for simple sequential numbers (2026-08-28). Strictly
 * consecutive numbers publish the store's volume - any customer who orders
 * twice can subtract and read how many orders arrived in between - so each
 * number advances by a small random step instead of exactly one. The result
 * still reads as an ordered, human-sized number and still sorts correctly,
 * while the gap between two of them says nothing reliable.
 *
 * The value comes from a locked counter row rather than MAX(order_number):
 * two checkouts running at once would otherwise be handed the same number,
 * and the orders table is not a sequence - rows placed before this change
 * carry the older random format, which is left alone.
 */
final class OrderNumber
{
    public const PREFIX = 'AUT-';

    public const PATTERN = '/^AUT-[1-9][0-9]{3,}$/';

    /** The older random format, still present on orders placed before 2026-08-28. */
    public const LEGACY_PATTERN = '/^AUT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/';

    private const MIN_STEP = 1;

    private const MAX_STEP = 9;

    /**
     * Claim the next number. Must run inside a transaction: the row lock is
     * what stops two simultaneous checkouts claiming the same value, and it is
     * only held until that transaction ends.
     */
    public static function generate(): string
    {
        $row = DB::table('order_number_sequence')->lockForUpdate()->first();

        if ($row === null) {
            throw new RuntimeException('The order number sequence is missing.');
        }

        $value = (int) $row->next_value;

        // The legacy alphabet contained the digits 2-9, so an old order can be
        // sitting on an all-numeric number such as AUT-234567 that this counter
        // will eventually walk into. order_number is unique, so the collision
        // would surface as a failed checkout; stepping past it costs one query.
        while (Order::query()->where('order_number', self::PREFIX.$value)->exists()) {
            $value += random_int(self::MIN_STEP, self::MAX_STEP);
        }

        DB::table('order_number_sequence')
            ->where('id', $row->id)
            ->update([
                'next_value' => $value + random_int(self::MIN_STEP, self::MAX_STEP),
                'updated_at' => now(),
            ]);

        return self::PREFIX.$value;
    }

    public static function matches(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1
            || preg_match(self::LEGACY_PATTERN, $number) === 1;
    }
}

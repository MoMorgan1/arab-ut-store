<?php

namespace App\Imports\Salla;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\ExternalRef;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ImportSallaOrders
{
    public function __construct(
        private readonly CurrencyConverter $currencyConverter,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     filename: string,
     *     checksum: string,
     *     total_rows: int,
     *     total_orders: int,
     *     created: int,
     *     skipped: int,
     *     unmatched_customer: int,
     *     skipped_not_completed: int,
     *     skipped_zero_total: int,
     *     unconverted_currencies: array<string, int>,
     *     unrecognised_statuses: int,
     *     unrecognised_status_list: list<string>,
     *     batch_id: ?string
     * }
     */
    public function execute(string $path, bool $dryRun = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Order export file not found or unreadable: {$path}");
        }

        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException("Could not calculate checksum for file: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Could not open file: {$path}");
        }

        // Handle UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);
            throw new InvalidArgumentException("File contains no header row: {$path}");
        }

        $headerMap = $this->buildHeaderMap($headerRow);
        $this->validateRequiredHeaders($headerMap);

        $totalRows = 0;
        $totalOrders = 0;
        $createdCount = 0;
        $skippedCount = 0;
        $unmatchedCustomerCount = 0;
        $skippedNotCompletedCount = 0;
        $skippedZeroTotalCount = 0;
        /** @var array<string, int> $unconvertedCurrencies */
        $unconvertedCurrencies = [];
        $unrecognisedStatusCount = 0;
        /** @var list<string> $unrecognisedStatusList */
        $unrecognisedStatusList = [];

        /** @var string|null $currentOrderNumber */
        $currentOrderNumber = null;
        /** @var list<array<string, string>> $currentOrderRows */
        $currentOrderRows = [];

        $processCurrentOrder = function () use (
            &$currentOrderNumber,
            &$currentOrderRows,
            &$totalOrders,
            &$createdCount,
            &$skippedCount,
            &$unmatchedCustomerCount,
            &$skippedNotCompletedCount,
            &$skippedZeroTotalCount,
            &$unconvertedCurrencies,
            &$unrecognisedStatusCount,
            &$unrecognisedStatusList,
            $dryRun
        ): void {
            if ($currentOrderNumber === null || empty($currentOrderRows)) {
                return;
            }

            $totalOrders++;
            $result = $this->processOrder(
                $currentOrderNumber,
                $currentOrderRows,
                $dryRun
            );

            if ($result['status'] === 'created') {
                $createdCount++;
            } elseif ($result['status'] === 'skipped_unmatched_customer') {
                $skippedCount++;
                $unmatchedCustomerCount++;
            } elseif ($result['status'] === 'skipped_not_completed') {
                $skippedCount++;
                $skippedNotCompletedCount++;
            } elseif ($result['status'] === 'skipped_zero_total') {
                $skippedCount++;
                $skippedZeroTotalCount++;
            } elseif ($result['status'] === 'skipped_duplicate') {
                $skippedCount++;
            }

            if ($result['unconverted_currency'] !== null) {
                $unconvertedCurrencies[$result['unconverted_currency']] =
                    ($unconvertedCurrencies[$result['unconverted_currency']] ?? 0) + 1;
            }

            if ($result['unrecognised_status'] !== null) {
                $unrecognisedStatusCount++;
                if (! in_array($result['unrecognised_status'], $unrecognisedStatusList, true)) {
                    $unrecognisedStatusList[] = $result['unrecognised_status'];
                }
            }

            $currentOrderNumber = null;
            $currentOrderRows = [];
        };

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            $totalRows++;
            $rowData = $this->extractRowData($row, $headerMap);
            $orderNumber = $rowData['order_number'];

            if ($orderNumber === '') {
                continue;
            }

            if ($currentOrderNumber !== null && $currentOrderNumber !== $orderNumber) {
                $processCurrentOrder();
            }

            $currentOrderNumber = $orderNumber;
            $currentOrderRows[] = $rowData;
        }

        // Flush last buffered order
        $processCurrentOrder();
        fclose($handle);

        $reportData = [
            'dry_run' => $dryRun,
            'filename' => basename($path),
            'checksum' => $checksum,
            'total_rows' => $totalRows,
            'total_orders' => $totalOrders,
            'created' => $createdCount,
            'skipped' => $skippedCount,
            'unmatched_customer' => $unmatchedCustomerCount,
            'skipped_not_completed' => $skippedNotCompletedCount,
            'skipped_zero_total' => $skippedZeroTotalCount,
            'unconverted_currencies' => $unconvertedCurrencies,
            'unrecognised_statuses' => $unrecognisedStatusCount,
            'unrecognised_status_list' => $unrecognisedStatusList,
            'batch_id' => null,
        ];

        if (! $dryRun) {
            $batch = ImportBatch::create([
                'source' => 'salla',
                'filename' => basename($path),
                'checksum' => $checksum,
                'status' => 'completed',
                'created_count' => $createdCount,
                'updated_count' => 0,
                'skipped_count' => $skippedCount,
                'conflict_count' => $unmatchedCustomerCount,
                'report' => $reportData,
                'dry_run' => false,
            ]);
            $reportData['batch_id'] = (string) $batch->public_id;
        }

        return $reportData;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{status: 'created'|'skipped_duplicate'|'skipped_unmatched_customer'|'skipped_not_completed'|'skipped_zero_total', unrecognised_status: ?string, unconverted_currency: ?string}
     */
    private function processOrder(string $orderNumber, array $rows, bool $dryRun): array
    {
        // 1. Idempotency check via external_refs
        $existingRef = ExternalRef::query()
            ->where('source', 'salla')
            ->where('entity', 'order')
            ->where('external_id', $orderNumber)
            ->first();

        if ($existingRef !== null) {
            return [
                'status' => 'skipped_duplicate',
                'unrecognised_status' => null,
                'unconverted_currency' => null,
            ];
        }

        $firstRow = $rows[0];

        // 2. Customer matching by mobile
        $normalizedMobile = PhoneNormalizer::normalize($firstRow['mobile']);
        $customer = null;

        if ($normalizedMobile !== null) {
            // Customers only, and only ones the customer pass actually linked.
            // That pass refuses to identify a Salla customer whose email and
            // mobile point at two different people; matching on the bare phone
            // here would override that refusal and file a stranger's orders
            // against whoever holds the number.
            /** @var User|null $customer */
            $customer = User::query()
                ->where('role', UserRole::Customer)
                ->where('phone', $normalizedMobile)
                ->whereIn('id', ExternalRef::query()
                    ->where('source', 'salla')
                    ->where('entity', 'customer')
                    ->select('internal_id'))
                ->first();
        }

        if ($customer === null) {
            return [
                'status' => 'skipped_unmatched_customer',
                'unrecognised_status' => null,
                'unconverted_currency' => null,
            ];
        }

        // 3. Status mapping.
        //
        // Owner decision: only orders that actually completed are imported, and
        // they all land as Completed. Cancelled, failed, refunded and still-in-
        // progress orders are skipped rather than rewritten, because marking them
        // finished would count refunds and failures as real sales - and imported
        // spend feeds lifetime totals, so it would inflate loyalty tiers too.
        $statusMapping = SallaStatusMapper::map($firstRow['status'], $firstRow['payment_status']);
        $unrecognisedStatus = $statusMapping['isUnrecognised'] ? $statusMapping['originalStatus'] : null;

        $wasPaid = mb_strtolower(trim((string) $firstRow['payment_status'])) === 'paid';
        $isFailure = in_array(
            $statusMapping['status'],
            [OrderStatus::Cancelled, OrderStatus::Refunded],
            true,
        );

        // Cancelled, failed and refunded orders never come across. Everything
        // else does if the customer either got it or paid for it: a large slice
        // of the export sits in "awaiting review" simply because that is where
        // the old workflow left it, and most of those were paid - skipping them
        // would erase real revenue from the history and from lifetime spend.
        if ($isFailure || (! $wasPaid && $statusMapping['status'] !== OrderStatus::Completed)) {
            return [
                'status' => 'skipped_not_completed',
                'unrecognised_status' => $unrecognisedStatus,
                'unconverted_currency' => null,
            ];
        }

        $orderStatus = OrderStatus::Completed;

        // Zero-value orders are test rows and the coin-buying flow the owner ran
        // when he was purchasing coins FROM customers - money moving the other
        // way, not a sale. Neither belongs in the sales history.
        if (MoneyParser::parse($firstRow['cart_total']) === 0) {
            return [
                'status' => 'skipped_zero_total',
                'unrecognised_status' => null,
                'unconverted_currency' => null,
            ];
        }

        // 4. Currency
        $originalCurrency = trim($firstRow['currency']);
        $originalCurrency = $originalCurrency !== '' ? strtoupper($originalCurrency) : 'SAR';

        // What currency the order is STORED in is decided with the totals
        // below, because it depends on which figures are usable.

        // 5. Build items and calculate totals
        $orderDate = $firstRow['order_date'] !== '' ? $this->parseTimestamp($firstRow['order_date']) : now();
        // Only completed orders reach this point, so they are all treated as paid
        // and completed; the guard above already rejected everything else.
        $isPaid = true;

        $itemsData = [];
        $totalItemsSubtotal = 0;
        $totalItemsDiscount = 0;
        $totalItemsAmount = 0;

        foreach ($rows as $itemRow) {
            $sku = trim($itemRow['sku']);
            $productName = trim($itemRow['product_name']);
            $quantity = max(1, (int) $itemRow['quantity']);
            $unitPriceHalalah = MoneyParser::parse($itemRow['product_price']);
            $lineDiscountHalalah = MoneyParser::parse($itemRow['line_discount']);

            // Parse price after discount if present, or compute
            $parsedPriceAfterDiscount = MoneyParser::parse($itemRow['price_after_discount']);
            $subtotalHalalah = $unitPriceHalalah * $quantity;
            $lineTotalHalalah = $parsedPriceAfterDiscount > 0
                ? ($parsedPriceAfterDiscount * $quantity)
                : max(0, $subtotalHalalah - $lineDiscountHalalah);

            // Live variant match only on exact SKU equality
            $matchedVariant = null;
            if ($sku !== '') {
                /** @var ProductVariant|null $matchedVariant */
                $matchedVariant = ProductVariant::with('product')->where('sku', $sku)->first();
            }

            if ($matchedVariant !== null) {
                $variantId = $matchedVariant->id;
                $itemSku = $matchedVariant->sku;
                $nameAr = $matchedVariant->product->name_ar;
                $nameEn = $matchedVariant->product->name_en;
                $serviceType = $matchedVariant->service_type;
                $platform = $matchedVariant->platform;
            } else {
                $variantId = null;
                $itemSku = $sku !== '' ? $sku : 'salla-import';
                $nameAr = $productName !== '' ? $productName : 'منتج';
                $nameEn = $productName !== '' ? $productName : 'Product';
                $serviceType = ServiceType::Coins;
                $platform = Platform::PlayStation;
            }

            $itemStatus = OrderItemStatus::from($orderStatus->value);

            $itemsData[] = [
                'product_variant_id' => $variantId,
                'sku' => $itemSku,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'service_type' => $serviceType,
                'platform' => $platform,
                'status' => $itemStatus,
                'quantity' => $quantity,
                'unit_price_halalah' => $unitPriceHalalah,
                'subtotal_halalah' => $subtotalHalalah,
                'discount_halalah' => $lineDiscountHalalah,
                'total_halalah' => $lineTotalHalalah,
                'configuration' => null,
            ];

            $totalItemsSubtotal += $subtotalHalalah;
            $totalItemsDiscount += $lineDiscountHalalah;
            $totalItemsAmount += $lineTotalHalalah;
        }

        // Cart total on order level.
        //
        // Salla exports ITEM prices in SAR even for an order charged in
        // another currency - only this order-level cart total carries the
        // foreign amount. Checked against the export: a KWD order reading
        // 8.37 here lists its product at 102, and 102 SAR is 8.35 KWD.
        // Everything derived from items is therefore already SAR and must be
        // left alone; converting it would multiply those figures by the rate
        // a second time.
        $cartTotalHalalah = MoneyParser::parse($firstRow['cart_total']);
        $cartTotalInSar = $this->currencyConverter->toSar($cartTotalHalalah, $originalCurrency);

        if ($originalCurrency === 'SAR') {
            $currency = 'SAR';
            $orderTotalHalalah = $cartTotalHalalah;
            $conversionMetadata = null;
        } elseif ($totalItemsAmount > 0) {
            // The item prices are natively SAR, so their sum IS the SAR total.
            // Preferred over converting the cart total because the cart total's
            // currency is NOT reliable: a small number of foreign orders carry
            // a SAR cart total (KWD 3 of 1474, USD 1 of 167, EUR 1 of 33), and
            // putting those through the rate inflated them twelvefold - order
            // 116377952 became 1,245.42 SAR for a 102 SAR purchase. Summing the
            // items needs no rate, so it cannot make that mistake.
            $currency = 'SAR';
            $orderTotalHalalah = $totalItemsAmount;
            $conversionMetadata = [
                'source' => 'salla',
                'basis' => 'item_prices',
                'original_currency' => $originalCurrency,
                'original_total_minor' => $cartTotalHalalah,
                'rate_foreign_per_sar' => null,
                'rate_fetched_at' => null,
            ];
        } elseif ($cartTotalInSar['converted']) {
            // Nothing priced on the lines, so the cart total is all there is.
            $currency = 'SAR';
            $orderTotalHalalah = $cartTotalInSar['halalah'];
            $conversionMetadata = [
                'source' => 'salla',
                'basis' => 'exchange_rate',
                'original_currency' => $originalCurrency,
                'original_total_minor' => $cartTotalHalalah,
                'rate_foreign_per_sar' => $cartTotalInSar['rate'],
                'rate_fetched_at' => $cartTotalInSar['fetchedAt'],
            ];
        } else {
            // Nothing usable: keep what the export said rather than guess.
            $currency = $originalCurrency;
            $orderTotalHalalah = $cartTotalHalalah;
            $conversionMetadata = null;
        }

        $orderSubtotalHalalah = $totalItemsSubtotal > 0 ? $totalItemsSubtotal : $orderTotalHalalah;
        $orderDiscountHalalah = $totalItemsDiscount;
        $orderPaymentHalalah = $orderTotalHalalah;

        if (! $dryRun) {
            DB::transaction(function () use (
                $orderNumber,
                $customer,
                $orderStatus,
                $currency,
                $conversionMetadata,
                $orderSubtotalHalalah,
                $orderDiscountHalalah,
                $orderPaymentHalalah,
                $orderTotalHalalah,
                $orderDate,
                $itemsData,
            ): void {
                $order = new Order([
                    'user_id' => $customer->id,
                    'order_number' => $orderNumber,
                    'status' => $orderStatus,
                    'locale' => 'ar',
                    'currency' => $currency,
                    'subtotal_halalah' => $orderSubtotalHalalah,
                    'discount_halalah' => $orderDiscountHalalah,
                    'wallet_halalah' => 0,
                    'payment_halalah' => $orderPaymentHalalah,
                    'total_halalah' => $orderTotalHalalah,
                    'placed_at' => $orderDate,
                    'paid_at' => $orderDate,
                    'completed_at' => $orderDate,
                    'cancelled_at' => null,
                ]);
                $order->channel = 'salla_import';

                // Converting is lossy and one-way, so keep what it was: without
                // this nobody could audit a total, re-run the conversion at a
                // better rate, or answer a customer asking why their order
                // reads 102.20 SAR when they paid 8.37 KWD.
                $order->import_metadata = $conversionMetadata;
                $order->created_at = $orderDate;
                $order->updated_at = $orderDate;
                $order->save();

                foreach ($itemsData as $item) {
                    // Item money is left exactly as exported: Salla already
                    // quotes it in SAR. See the cart-total block above.
                    $orderItem = new OrderItem($item);
                    $orderItem->order_id = max(0, (int) $order->id);
                    $orderItem->created_at = $orderDate;
                    $orderItem->updated_at = $orderDate;
                    $orderItem->save();
                }

                ExternalRef::create([
                    'source' => 'salla',
                    'entity' => 'order',
                    'external_id' => $orderNumber,
                    'internal_id' => $order->id,
                ]);
            });
        }

        return [
            'status' => 'created',
            'unrecognised_status' => $unrecognisedStatus,
            'unconverted_currency' => $currency === 'SAR' ? null : $originalCurrency,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function buildHeaderMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $cleaned = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header));
            $normalized = mb_strtolower(str_replace([' ', '-'], '_', $cleaned));
            $map[$normalized] = $index;
            $map[$cleaned] = $index;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     */
    private function validateRequiredHeaders(array $map): void
    {
        $orderNumberPresent = isset($map['رقم الطلب']) || isset($map['order_number']) || isset($map['order_id']);
        $mobilePresent = isset($map['رقم الجوال']) || isset($map['mobile']) || isset($map['phone']);

        if (! $orderNumberPresent || ! $mobilePresent) {
            throw new InvalidArgumentException('Order export file is missing required headers (رقم الطلب, رقم الجوال).');
        }
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array{
     *     order_number: string,
     *     status: string,
     *     customer_name: string,
     *     mobile: string,
     *     payment_method: string,
     *     payment_status: string,
     *     order_date: string,
     *     coupon_code: string,
     *     cart_total: string,
     *     tax: string,
     *     currency: string,
     *     product_name: string,
     *     sku: string,
     *     quantity: string,
     *     product_price: string,
     *     price_before_discount: string,
     *     price_after_discount: string,
     *     line_discount: string,
     *     invoice_number: string
     * }
     */
    private function extractRowData(array $row, array $map): array
    {
        $get = function (array $keys) use ($row, $map): string {
            foreach ($keys as $key) {
                if (isset($map[$key]) && isset($row[$map[$key]])) {
                    $val = trim((string) $row[$map[$key]]);
                    if ($val !== '\N' && $val !== 'NULL') {
                        return $val;
                    }
                }
            }

            return '';
        };

        return [
            'order_number' => $get(['رقم الطلب', 'order_number', 'order_id']),
            'status' => $get(['حالة الطلب', 'status', 'order_status']),
            'customer_name' => $get(['اسم العميل', 'customer_name', 'name']),
            'mobile' => $get(['رقم الجوال', 'mobile', 'phone', 'mobile_number']),
            'payment_method' => $get(['طريقة الدفع', 'payment_method']),
            'payment_status' => $get(['حالة الدفع', 'payment_status']),
            'order_date' => $get(['تاريخ الطلب', 'order_date', 'date', 'created_at']),
            'coupon_code' => $get(['رمز الكوبون', 'coupon_code', 'coupon']),
            'cart_total' => $get(['مجموع السلة (على مستوى الطلب)', 'cart_total', 'total']),
            'tax' => $get(['الضريبة', 'tax']),
            'currency' => $get(['العملة', 'currency']),
            'product_name' => $get(['اسم المنتج', 'product_name', 'item_name']),
            'sku' => $get(['SKU', 'sku']),
            'quantity' => $get(['الكمية', 'quantity', 'qty']),
            'product_price' => $get(['سعر المنتج', 'product_price', 'price']),
            'price_before_discount' => $get(['سعر المنتج قبل الخصم', 'price_before_discount']),
            'price_after_discount' => $get(['سعر المنتج بعد الخصم', 'price_after_discount']),
            'line_discount' => $get(['الخصم (على مستوى المنتج)', 'line_discount', 'discount']),
            'invoice_number' => $get(['رقم الفاتورة', 'invoice_number', 'invoice']),
        ];
    }

    /**
     * Parse a Salla timestamp into the date type the models actually declare.
     *
     * The app calls Date::use(CarbonImmutable::class), so the Date facade and
     * now() both yield a CarbonImmutable - which does not satisfy the Carbon
     * typed date properties on User and Order. Naming the class explicitly keeps
     * the assignment honest instead of casting around the mismatch.
     */
    private function parseTimestamp(string $value): IlluminateCarbon
    {
        try {
            return IlluminateCarbon::parse(trim($value));
        } catch (\Throwable) {
            return IlluminateCarbon::now();
        }
    }
}

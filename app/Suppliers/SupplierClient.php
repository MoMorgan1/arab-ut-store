<?php

namespace App\Suppliers;

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierUnavailable;

/**
 * The boundary through which the store talks to an external supplier.
 *
 * Reads are cheap and constant; actions come from a customer pressing a
 * button. Both suppliers answer with HTTP 200 and a failure body, so callers
 * must read SupplierActionResult::$accepted rather than the HTTP status.
 */
interface SupplierClient
{
    public function supplier(): Supplier;

    /**
     * Read the supplier's current view of a placed order.
     *
     * @param  bool  $isExternalId  whether $supplierOrderId is our id rather
     *                              than the supplier's own internal id
     *
     * @throws SupplierUnavailable
     */
    public function observe(string $supplierOrderId, bool $isExternalId = true): RawSupplierObservation;

    /**
     * Push corrected credentials to the supplier.
     *
     * @param array{
     *     user?: string,
     *     pass?: string,
     *     ba?: string,
     *     ba2?: string,
     *     ba3?: string,
     *     ba4?: string,
     *     ba5?: string,
     *     platform?: string,
     *     persona?: string,
     *     limit?: int,
     *     sortMode?: string,
     *     continue?: int|bool
     * } $credentials
     *
     * @throws SupplierUnavailable
     */
    public function correctCredentials(string $supplierOrderId, array $credentials): SupplierActionResult;

    /**
     * Ask the supplier to resume a stopped or interrupted order.
     *
     * @throws SupplierUnavailable
     */
    public function resume(string $supplierOrderId): SupplierActionResult;

    /**
     * Ask the supplier to retry a failed challenge.
     *
     * UTT does not solve challenges and throws a LogicException instead.
     *
     * @throws SupplierUnavailable
     */
    public function retryChallenge(string $supplierOrderId, string $challengeId): SupplierActionResult;

    /**
     * Read the supplier's view of active challenge (SBC) solves.
     *
     * UTT does not solve challenges and throws a LogicException instead.
     *
     * @param  list<string>  $challengeIds
     * @return array<string, array<string, mixed>>
     *
     * @throws SupplierUnavailable
     */
    public function observeChallenges(array $challengeIds): array;
}

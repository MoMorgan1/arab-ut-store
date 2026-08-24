<?php

namespace App\Imports\Salla;

use App\Enums\UserRole;
use App\Models\ExternalRef;
use App\Models\ImportBatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ImportSallaCustomers
{
    /**
     * @return array{
     *     dry_run: bool,
     *     filename: string,
     *     checksum: string,
     *     total_processed: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     conflicts: int,
     *     conflict_details: list<array{
     *         salla_id: string,
     *         name: string,
     *         email: ?string,
     *         phone: ?string,
     *         staff_user_id?: int,
     *         email_user_id?: int,
     *         phone_user_id?: int,
     *         reason?: string,
     *         claimed_by?: string
     *     }>,
     *     batch_id: ?string
     * }
     */
    public function execute(string $path, bool $dryRun = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Customer export file not found or unreadable: {$path}");
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

        $totalProcessed = 0;
        $createdCount = 0;
        $updatedCount = 0;

        // A dry run writes nothing, so the lookups below cannot see rows this
        // same run would have created. The export contains customers who share
        // an email or a mobile with each other, so without this the preview
        // over-reports creations and under-reports matches - which defeats the
        // point of previewing. Keys are the normalised email and phone.
        /** @var array<string, string> $plannedIdentities keyed identity => the salla id that claimed it */
        $plannedIdentities = [];
        $skippedCount = 0;
        $conflictCount = 0;
        /** @var list<array{salla_id: string, name: string, email: ?string, phone: ?string, email_user_id: int, phone_user_id: int}> $conflictDetails */
        $conflictDetails = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            $totalProcessed++;
            $data = $this->extractRowData($row, $headerMap);
            $sallaId = $data['id'];

            if ($sallaId === '') {
                $skippedCount++;

                continue;
            }

            // 1. Check external_refs: already imported?
            $existingRef = ExternalRef::query()
                ->where('source', 'salla')
                ->where('entity', 'customer')
                ->where('external_id', $sallaId)
                ->first();

            if ($existingRef !== null) {
                $skippedCount++;

                continue;
            }

            $normalizedEmail = $data['email'] !== '' ? Str::lower(trim($data['email'])) : null;
            $normalizedPhone = PhoneNormalizer::normalize($data['mobile']);

            // Two DIFFERENT Salla customers sharing an email or a mobile are
            // two different people as far as we can tell, and merging them into
            // one login would let either take the account over by WhatsApp OTP
            // and read the other's order history. This has to run BEFORE the
            // lookups below: in a real run the second row would otherwise find
            // the account the first row just created and link to it silently.
            $planKeys = array_values(array_filter([
                $normalizedEmail === null ? null : 'email:'.$normalizedEmail,
                $normalizedPhone === null ? null : 'phone:'.$normalizedPhone,
            ]));

            $claimedBy = null;
            foreach ($planKeys as $planKey) {
                if (isset($plannedIdentities[$planKey])) {
                    $claimedBy = $plannedIdentities[$planKey];

                    break;
                }
            }

            if ($claimedBy !== null) {
                $conflictCount++;
                $conflictDetails[] = [
                    'salla_id' => $sallaId,
                    'name' => $data['full_name'],
                    'email' => $normalizedEmail,
                    'phone' => $normalizedPhone,
                    'reason' => 'duplicate_identity_within_file',
                    'claimed_by' => $claimedBy,
                ];

                continue;
            }

            foreach ($planKeys as $planKey) {
                $plannedIdentities[$planKey] = $sallaId;
            }

            // Look across ALL roles, not just customers. A Salla row whose
            // email or mobile belongs to a staff or owner account must never
            // attach a customer identity - and that person's order history -
            // to it. Filtering the lookup to customers hid those accounts
            // instead: the row looked new, and the import then died on the
            // global users.email unique index, which is not role-scoped.
            // The real export contains one - an owner's address on a
            // customer record.
            $userByEmail = null;
            if ($normalizedEmail !== null) {
                /** @var User|null $userByEmail */
                $userByEmail = User::query()
                    ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                    ->first();
            }

            $userByPhone = null;
            if ($normalizedPhone !== null) {
                /** @var User|null $userByPhone */
                $userByPhone = User::query()
                    ->where('phone', $normalizedPhone)
                    ->first();
            }

            // A match on a non-customer account is a conflict, never a link:
            // filing a stranger's orders onto a staff or owner login would
            // expose them in that account's history.
            $staffMatch = null;
            foreach ([$userByEmail, $userByPhone] as $candidate) {
                if ($candidate instanceof User && $candidate->role !== UserRole::Customer) {
                    $staffMatch = $candidate;

                    break;
                }
            }

            if ($staffMatch !== null) {
                $conflictCount++;
                $conflictDetails[] = [
                    'salla_id' => $sallaId,
                    'name' => $data['full_name'],
                    'email' => $normalizedEmail,
                    'phone' => $normalizedPhone,
                    'reason' => 'matches_non_customer_account',
                    'staff_user_id' => $staffMatch->id,
                ];

                continue;
            }

            // 4. Conflict rule: email matches User A and phone matches different User B
            if ($userByEmail !== null && $userByPhone !== null && $userByEmail->id !== $userByPhone->id) {
                $conflictCount++;
                $conflictDetails[] = [
                    'salla_id' => $sallaId,
                    'name' => $data['full_name'],
                    'email' => $normalizedEmail,
                    'phone' => $normalizedPhone,
                    'email_user_id' => $userByEmail->id,
                    'phone_user_id' => $userByPhone->id,
                ];

                continue;
            }

            $matchedUser = $userByEmail ?? $userByPhone;

            // Applies to the real run too, not just the preview. The export
            // contains different Salla customers sharing a mobile or an email;
            // without this the second row links to the account the first row
            // created, merging two real people into one login - and their
            // orders follow the phone, so each would see the other's history.
            if ($matchedUser !== null) {
                // Existing user matched: link without overwriting name, email or phone
                if (! $dryRun) {
                    DB::transaction(function () use ($sallaId, $matchedUser): void {
                        ExternalRef::create([
                            'source' => 'salla',
                            'entity' => 'customer',
                            'external_id' => $sallaId,
                            'internal_id' => $matchedUser->id,
                        ]);
                    });
                }
                $updatedCount++;

                continue;
            }

            // No existing user matched: create new user
            [$firstName, $lastName] = $this->splitFullName($data['full_name']);
            $createdAt = $data['created_at'] !== '' ? $this->parseTimestamp($data['created_at']) : IlluminateCarbon::now();

            if (! $dryRun) {
                DB::transaction(function () use (
                    $sallaId,
                    $firstName,
                    $lastName,
                    $normalizedEmail,
                    $normalizedPhone,
                    $createdAt,
                ): void {
                    $newUser = new User([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $normalizedEmail,
                        'phone' => $normalizedPhone,
                        'password' => null,
                        'preferred_locale' => 'ar',
                        'display_currency' => 'SAR',
                    ]);
                    $newUser->role = UserRole::Customer;
                    $newUser->is_active = true;
                    $newUser->created_at = $createdAt;
                    $newUser->updated_at = $createdAt;

                    if ($normalizedPhone !== null) {
                        $newUser->phone_verified_at = $createdAt;
                    }

                    $newUser->save();

                    ExternalRef::create([
                        'source' => 'salla',
                        'entity' => 'customer',
                        'external_id' => $sallaId,
                        'internal_id' => $newUser->id,
                    ]);
                });
            }

            $createdCount++;
        }

        fclose($handle);

        $reportData = [
            'dry_run' => $dryRun,
            'filename' => basename($path),
            'checksum' => $checksum,
            'total_processed' => $totalProcessed,
            'created' => $createdCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'conflicts' => $conflictCount,
            'conflict_details' => $conflictDetails,
            'batch_id' => null,
        ];

        if (! $dryRun) {
            $batch = ImportBatch::create([
                'source' => 'salla',
                'filename' => basename($path),
                'checksum' => $checksum,
                'status' => 'completed',
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'conflict_count' => $conflictCount,
                'report' => $reportData,
                'dry_run' => false,
            ]);
            $reportData['batch_id'] = (string) $batch->public_id;
        }

        return $reportData;
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
        $idPresent = isset($map['id']) || isset($map['id_customer']) || isset($map['id']);
        $namePresent = isset($map['full_name']) || isset($map['name']) || isset($map['fullname']);
        $mobilePresent = isset($map['mobile']) || isset($map['phone']) || isset($map['mobile_number']);

        if (! $idPresent || ! $namePresent || ! $mobilePresent) {
            throw new InvalidArgumentException('Customer file is missing required headers (ID, Full_Name, Mobile).');
        }
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array{id: string, full_name: string, mobile: string, email: string, created_at: string}
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
            'id' => $get(['id', 'id_customer', 'id']),
            'full_name' => $get(['full_name', 'name', 'fullname', 'اسم العميل']),
            'mobile' => $get(['mobile', 'phone', 'mobile_number', 'رقم الجوال']),
            'email' => $get(['email', 'email_address', 'البريد الإلكتروني']),
            'created_at' => $get(['created_at', 'date_created', 'تاريخ الإنشاء']),
        ];
    }

    /**
     * @return array{string, string}
     */
    private function splitFullName(string $fullName): array
    {
        $trimmed = trim($fullName);
        if ($trimmed === '') {
            return ['عميل', '.'];
        }

        $parts = preg_split('/\s+/u', $trimmed, 2) ?: [];
        $firstName = isset($parts[0]) && trim($parts[0]) !== '' ? trim($parts[0]) : 'عميل';
        $lastName = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : '.';

        return [$firstName, $lastName];
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

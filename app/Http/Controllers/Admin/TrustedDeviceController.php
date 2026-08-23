<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Auth\TrustedDeviceRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Revokes every browser that may currently skip the TOTP challenge for the
 * signed-in account, including the one making the request.
 */
final class TrustedDeviceController extends Controller
{
    public function __construct(
        private readonly TrustedDeviceRegistry $trustedDevices,
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $revoked = $this->trustedDevices->forgetAll($actor);

        $this->recordStaffAudit->execute(
            $actor,
            $actor,
            new StaffAuditEvent(
                action: 'security.trusted_devices_revoked',
                metadata: ['revoked_count' => $revoked],
                ipAddress: $request->ip(),
            ),
        );

        return response()
            ->json(['revoked' => $revoked])
            ->withCookie($this->trustedDevices->forgetCookie());
    }
}

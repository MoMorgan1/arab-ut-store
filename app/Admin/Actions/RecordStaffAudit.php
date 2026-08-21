<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

final class RecordStaffAudit
{
    public function execute(User $actor, ?Model $subject, StaffAuditEvent $event): StaffAuditLog
    {
        if (! $actor->is_active || ! in_array($actor->role, [UserRole::Admin, UserRole::Staff], true)) {
            throw new AuthorizationException('Only active Admin or Staff actors may record staff audits.');
        }

        return StaffAuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'action' => $event->action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $event->metadata,
            'ip_address' => $event->ipAddress,
        ]);
    }
}

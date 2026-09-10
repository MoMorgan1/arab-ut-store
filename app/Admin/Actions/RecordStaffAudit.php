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

        return $this->write($actor, $subject, $event);
    }

    /**
     * Server operators have no user row, so console commands record with no
     * actor. The event still goes through the same metadata guard, and the
     * path refuses to run outside the console so a web request can never
     * write an actor-less audit row.
     */
    public function executeFromConsole(?Model $subject, StaffAuditEvent $event): StaffAuditLog
    {
        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Actor-less staff audits are written by console commands only.');
        }

        return $this->write(null, $subject, $event);
    }

    private function write(?User $actor, ?Model $subject, StaffAuditEvent $event): StaffAuditLog
    {
        return StaffAuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $event->action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $event->metadata,
            'ip_address' => $event->ipAddress,
        ]);
    }
}

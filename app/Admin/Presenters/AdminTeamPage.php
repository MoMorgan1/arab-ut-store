<?php

namespace App\Admin\Presenters;

use App\Enums\UserRole;
use App\Models\User;

final readonly class AdminTeamPage
{
    /**
     * @return array{
     *     members: list<array{
     *         id: string,
     *         name: string,
     *         email: string,
     *         role: string,
     *         isActive: bool,
     *         mfaConfirmed: bool,
     *         createdAt: string
     *     }>,
     *     currentUserId: string
     * }
     */
    public function for(User $actor): array
    {
        $users = User::query()
            ->whereIn('role', [UserRole::Admin, UserRole::Staff])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $members = array_values($users->map(fn (User $user): array => [
            'id' => (string) $user->public_id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $user->role->value,
            'isActive' => (bool) $user->is_active,
            'mfaConfirmed' => $user->two_factor_confirmed_at !== null,
            'createdAt' => $user->created_at?->toIso8601String() ?? '',
        ])->all());

        return [
            'members' => $members,
            'currentUserId' => (string) $actor->public_id,
        ];
    }
}

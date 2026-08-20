<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResolveChatOwner
{
    public const SESSION_KEY = 'arabut_chat_guest_token';

    public const ACTIVE_CONVERSATION_SESSION_KEY = 'arabut_chat_active_conversation';

    public function forRequest(Request $request): ChatOwner
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser instanceof Authenticatable) {
            return ChatOwner::user($this->authenticatedUserId($authenticatedUser));
        }

        $existingOwners = $this->existingGuestCandidatesForRequest($request);

        if ($existingOwners !== []) {
            $sessionPointer = $request->session()->get(self::ACTIVE_CONVERSATION_SESSION_KEY);

            return count($existingOwners) === 1
                ? $existingOwners[0]
                : $this->rekeyGuestConversations(
                    $existingOwners[0],
                    array_slice($existingOwners, 1),
                    is_string($sessionPointer) ? $sessionPointer : null,
                );
        }

        $rawToken = bin2hex(random_bytes(32));
        $request->session()->put(self::SESSION_KEY, $rawToken);

        return $this->ownerForRawToken($rawToken);
    }

    /** @return list<ChatOwner> */
    public function existingGuestCandidatesForRequest(Request $request): array
    {
        $rawToken = $request->session()->get(self::SESSION_KEY);

        if (! is_string($rawToken) || preg_match('/\A[0-9a-f]{64}\z/D', $rawToken) !== 1) {
            return [];
        }

        return $this->ownersForRawToken($rawToken);
    }

    private function ownerForRawToken(string $rawToken): ChatOwner
    {
        $owners = $this->ownersForRawToken($rawToken);
        $currentOwner = $owners[0];
        $previousOwners = array_slice($owners, 1);

        if ($previousOwners === []) {
            return $currentOwner;
        }

        return $this->rekeyGuestConversations($currentOwner, $previousOwners);
    }

    /** @return list<ChatOwner> */
    private function ownersForRawToken(string $rawToken): array
    {
        $currentOwner = ChatOwner::guest(hash_hmac('sha256', $rawToken, $this->applicationKey()));

        return [$currentOwner, ...$this->previousOwners($rawToken, $currentOwner)];
    }

    private function authenticatedUserId(Authenticatable $user): int
    {
        $identifier = $user->getAuthIdentifier();

        if (! is_int($identifier) && (! is_string($identifier) || ! ctype_digit($identifier))) {
            throw new RuntimeException('The authenticated chat owner is unavailable.');
        }

        return (int) $identifier;
    }

    private function applicationKey(): string
    {
        $applicationKey = config('app.key');

        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new RuntimeException('The application key is unavailable.');
        }

        return $applicationKey;
    }

    /** @return list<ChatOwner> */
    private function previousOwners(string $rawToken, ChatOwner $currentOwner): array
    {
        $previousKeys = config('app.previous_keys', []);

        if (! is_array($previousKeys)) {
            return [];
        }

        $owners = [];

        foreach ($previousKeys as $previousKey) {
            if (! is_string($previousKey) || $previousKey === '') {
                continue;
            }

            $owner = ChatOwner::guest(hash_hmac('sha256', $rawToken, $previousKey));

            if ($owner->databaseKey() !== $currentOwner->databaseKey()) {
                $owners[$owner->databaseKey()] = $owner;
            }
        }

        return array_values($owners);
    }

    /** @param list<ChatOwner> $previousOwners */
    private function rekeyGuestConversations(
        ChatOwner $currentOwner,
        array $previousOwners,
        ?string $activePublicId = null,
    ): ChatOwner {
        return DB::transaction(function () use ($currentOwner, $previousOwners, $activePublicId): ChatOwner {
            $candidateKeys = array_values(array_unique(array_filter(array_map(
                fn (ChatOwner $owner): ?string => $owner->guestKey(),
                [$currentOwner, ...$previousOwners],
            ))));
            $currentGuestKey = $currentOwner->guestKey();

            if ($candidateKeys === [] || $currentGuestKey === null) {
                return $currentOwner;
            }

            $conversations = ChatConversation::query()
                ->whereNull('user_id')
                ->whereIn('guest_key', $candidateKeys)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $openConversations = $conversations->filter(
                fn (ChatConversation $conversation): bool => $conversation->status === ChatConversationStatus::Open,
            );
            $winner = $activePublicId !== null && $activePublicId !== ''
                ? $openConversations->firstWhere('public_id', $activePublicId)
                : null;

            if (! $winner instanceof ChatConversation) {
                $winner = $openConversations->reduce(
                    function (?ChatConversation $newest, ChatConversation $candidate): ChatConversation {
                        if (! $newest instanceof ChatConversation) {
                            return $candidate;
                        }

                        $candidateActivity = $candidate->last_message_at?->getTimestamp() ?? PHP_INT_MIN;
                        $newestActivity = $newest->last_message_at?->getTimestamp() ?? PHP_INT_MIN;

                        return $candidateActivity > $newestActivity
                            || ($candidateActivity === $newestActivity && $candidate->id > $newest->id)
                                ? $candidate
                                : $newest;
                    },
                );
            }

            foreach ($openConversations as $openConversation) {
                if ($winner instanceof ChatConversation && $openConversation->id === $winner->id) {
                    continue;
                }

                $openConversation->forceFill([
                    'status' => ChatConversationStatus::Closed,
                    'closed_at' => now(),
                    'close_reason' => ChatConversationCloseReason::InvariantUpgradeDuplicate,
                ])->save();
            }

            ChatConversation::query()
                ->whereIn('id', $conversations->pluck('id'))
                ->where('guest_key', '!=', $currentGuestKey)
                ->update([
                    'guest_key' => $currentGuestKey,
                    'updated_at' => now(),
                ]);

            return $currentOwner;
        }, attempts: 3);
    }
}

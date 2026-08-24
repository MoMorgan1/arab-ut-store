<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminShell;
use App\Enums\AdminPermission;
use App\Enums\Support\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminConversations;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ConversationsController extends Controller
{
    public function __construct(private readonly AdminShell $shell) {}

    public function __invoke(ListAdminConversations $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::ChatView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $filters = $request->normalizedFilters();

        $query = ChatConversation::query()
            ->whereNotNull('user_id')
            ->with(['user', 'liveTicket'])
            ->withCount('messages');

        // A thread where the customer has written since the last staff reply is
        // the only thing that needs Mohamed now, so it sorts above everything
        // else regardless of age. Expressed as an ordering rather than a filter:
        // the rest of the queue has to stay visible underneath it.
        $query->orderByRaw(
            'CASE WHEN EXISTS ('
            .'SELECT 1 FROM support_tickets '
            .'WHERE support_tickets.conversation_id = chat_conversations.id '
            .'AND support_tickets.status = ? '
            .') AND (chat_conversations.last_staff_message_at IS NULL '
            .'OR chat_conversations.last_message_at > chat_conversations.last_staff_message_at) '
            .'THEN 0 ELSE 1 END',
            [SupportTicketStatus::Open->value],
        )->orderByLastActivityDesc();

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['locale'] !== null) {
            $query->where('locale', $filters['locale']);
        }

        if ($filters['ticket_status'] !== null) {
            $ticketStatus = $filters['ticket_status'];
            $query->whereHas('tickets', function (Builder $ticket) use ($ticketStatus): void {
                $ticket->where('status', $ticketStatus);
            });
        }

        if ($filters['q'] !== null) {
            // Operators read these numbers off a screen, so the short forms have
            // to match case-insensitively; the raw ULID stays searchable because
            // it is what a log line or a bug report carries.
            $term = mb_strtoupper($filters['q']);
            $query->where(function (Builder $search) use ($term): void {
                $search->whereRaw('UPPER(chat_conversations.short_id) = ?', [$term])
                    ->orWhereRaw('UPPER(chat_conversations.public_id) = ?', [$term])
                    ->orWhereHas('tickets', function (Builder $ticket) use ($term): void {
                        $ticket->whereRaw('UPPER(support_tickets.ticket_number) = ?', [$term]);
                    });
            });
        }

        $paginator = $query->paginate(
            perPage: $filters['per_page'],
            page: $filters['page'],
        );

        $rows = array_map(function (ChatConversation $conversation): array {
            $ticket = $conversation->liveTicket;
            $lastStaffAt = $conversation->last_staff_message_at;

            return [
                'publicId' => (string) $conversation->public_id,
                'shortId' => (string) $conversation->short_id,
                'ticketNumber' => $ticket === null ? null : (string) $ticket->ticket_number,
                'ticketStatus' => $ticket === null ? null : $ticket->status->value,
                // The dot means "they are waiting on you", which is only ever
                // true while a live ticket exists — an ordinary chat with Nawaf
                // is not something Mohamed owes an answer to.
                'hasUnread' => $ticket !== null && (
                    $lastStaffAt === null
                    || ($conversation->last_message_at !== null
                        && $conversation->last_message_at->greaterThan($lastStaffAt))
                ),
                'status' => $conversation->status->value,
                'locale' => (string) $conversation->locale,
                'ownerType' => $conversation->user_id !== null ? 'customer' : 'guest',
                'customerName' => $conversation->user?->name,
                'messageCount' => (int) $conversation->messages_count,
                'lastMessageAt' => $conversation->last_message_at !== null
                    ? Carbon::parse($conversation->last_message_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'createdAt' => $conversation->created_at !== null
                    ? Carbon::parse($conversation->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        }, $paginator->items());

        return Inertia::render('admin/conversations/index', [
            'auth' => null,
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'rows' => $rows,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => [
                    [
                        'value' => 'open',
                        'label' => (string) trans('admin.conversations.statusOpen', locale: $locale),
                    ],
                    [
                        'value' => 'closed',
                        'label' => (string) trans('admin.conversations.statusClosed', locale: $locale),
                    ],
                ],
                'locales' => [
                    [
                        'value' => 'ar',
                        'label' => (string) trans('admin.conversations.localeAr', locale: $locale),
                    ],
                    [
                        'value' => 'en',
                        'label' => (string) trans('admin.conversations.localeEn', locale: $locale),
                    ],
                ],
                'ticketStatuses' => [
                    [
                        'value' => 'open',
                        'label' => (string) trans('admin.conversations.ticketOpen', locale: $locale),
                    ],
                    [
                        'value' => 'resolved',
                        'label' => (string) trans('admin.conversations.ticketResolved', locale: $locale),
                    ],
                    [
                        'value' => 'closed',
                        'label' => (string) trans('admin.conversations.ticketClosed', locale: $locale),
                    ],
                ],
                'perPageOptions' => [15, 25, 50, 100],
            ],
        ]);
    }
}

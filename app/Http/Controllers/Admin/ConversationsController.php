<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminShell;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminConversations;
use App\Models\ChatConversation;
use App\Models\User;
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
            ->with('user')
            ->withCount('messages')
            ->orderByLastActivityDesc();

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['locale'] !== null) {
            $query->where('locale', $filters['locale']);
        }

        if ($filters['owner'] === 'guest') {
            $query->whereNull('user_id');
        } elseif ($filters['owner'] === 'customer') {
            $query->whereNotNull('user_id');
        }

        if ($filters['q'] !== null) {
            $query->where('public_id', $filters['q']);
        }

        $paginator = $query->paginate(
            perPage: $filters['per_page'],
            page: $filters['page'],
        );

        $rows = array_map(function (ChatConversation $conversation): array {
            return [
                'publicId' => (string) $conversation->public_id,
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
                'owners' => [
                    [
                        'value' => 'customer',
                        'label' => (string) trans('admin.conversations.ownerCustomer', locale: $locale),
                    ],
                    [
                        'value' => 'guest',
                        'label' => (string) trans('admin.conversations.ownerGuest', locale: $locale),
                    ],
                ],
                'perPageOptions' => [15, 25, 50, 100],
            ],
        ]);
    }
}

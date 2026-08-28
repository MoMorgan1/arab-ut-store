<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ReadAdminOverview;
use App\Admin\Queries\ReadQueueHealth;
use App\Enums\AdminPermission;
use App\Models\User;

final readonly class AdminOverviewPage
{
    public function __construct(
        private AdminShell $shell,
        private ReadAdminOverview $overview,
        private ReadQueueHealth $queueHealth,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $actor, string $locale, int $days): array
    {
        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'overview' => $this->overview->for($actor, $days),
            // Operational, not commercial: it says whether the machinery is
            // running, so it follows the settings permission rather than the
            // dashboard one, and is absent entirely for staff without it.
            'queueHealth' => $actor->can(AdminPermission::SettingsView->value)
                ? $this->queueHealth->read()
                : null,
            'rangeOptions' => [
                $this->rangeOption('admin.overview', $locale, 1, $days),
                $this->rangeOption('admin.overview', $locale, 7, $days),
                $this->rangeOption('admin.overview', $locale, 30, $days),
            ],
        ];
    }

    /** @return array{days: int, label: string, url: string, active: bool} */
    private function rangeOption(string $routeName, string $locale, int $optionDays, int $activeDays): array
    {
        return [
            'days' => $optionDays,
            'label' => (string) trans("admin.overview.range{$optionDays}", locale: $locale),
            'url' => route($routeName, ['range' => $optionDays], absolute: false),
            'active' => $optionDays === $activeDays,
        ];
    }
}

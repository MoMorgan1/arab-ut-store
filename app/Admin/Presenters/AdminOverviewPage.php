<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ReadAdminOverview;
use App\Models\User;

final readonly class AdminOverviewPage
{
    public function __construct(
        private AdminShell $shell,
        private ReadAdminOverview $overview,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $actor, string $locale, int $days): array
    {
        $routeName = $locale === 'en' ? 'localized.admin.overview' : 'admin.overview';

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'overview' => $this->overview->for($actor, $days),
            'rangeOptions' => [
                $this->rangeOption($routeName, $locale, 7, $days),
                $this->rangeOption($routeName, $locale, 30, $days),
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

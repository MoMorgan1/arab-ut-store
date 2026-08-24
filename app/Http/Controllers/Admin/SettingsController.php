<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminMfaState;
use App\Admin\Presenters\AdminServicePricingPage;
use App\Admin\Presenters\AdminShell;
use App\Admin\Presenters\AdminTeamPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController extends Controller
{
    public function __construct(
        private readonly AdminShell $shell,
        private readonly AdminMfaState $mfaState,
        private readonly AdminTeamPage $teamPage,
        private readonly AdminServicePricingPage $servicePricingPage,
    ) {}

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $canViewTeam = $actor->can(AdminPermission::StaffView->value) && $actor->hasEnabledTwoFactorAuthentication();
        $team = $canViewTeam ? $this->teamPage->for($actor) : null;

        $canViewPricing = $actor->can(AdminPermission::SettingsView->value) && $actor->hasEnabledTwoFactorAuthentication();
        $servicePricing = $canViewPricing ? $this->servicePricingPage->for($actor) : null;

        $canManagePricing = $actor->can(AdminPermission::SettingsManage->value) && $actor->hasEnabledTwoFactorAuthentication();

        $currentRouteName = (string) $request->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $teamUrls = $canViewTeam ? [
            'grantUrl' => route($prefix.'team.grants.store', absolute: false),
            'roleUrlTemplate' => route($prefix.'team.role.store', ['publicId' => '__ID__'], absolute: false),
            'statusUrlTemplate' => route($prefix.'team.status.store', ['publicId' => '__ID__'], absolute: false),
        ] : null;

        $servicePricingUrls = $canManagePricing ? [
            'updateUrlTemplate' => route($prefix.'settings.service-pricing.store', ['serviceType' => '__SERVICE__'], absolute: false),
            'statusUrlTemplate' => route($prefix.'settings.service-pricing.status.store', ['serviceType' => '__SERVICE__'], absolute: false),
        ] : null;

        return Inertia::render('admin/settings', [
            'auth' => null,
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'mfa' => $this->mfaState->for($actor, $locale),
            'team' => $team,
            'teamUrls' => $teamUrls,
            'servicePricing' => $servicePricing,
            'servicePricingUrls' => $servicePricingUrls,
        ]);
    }
}

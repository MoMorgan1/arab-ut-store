'use no memo';

import { Head, router } from '@inertiajs/react';
import { LogOut, ShieldCheck, Tag, Users } from 'lucide-react';

import AdminSecuritySection from '@/components/admin/settings/admin-security-section';
import AdminServicePricingSection from '@/components/admin/settings/admin-service-pricing-section';
import AdminTeamSection from '@/components/admin/settings/admin-team-section';
import type { AdminSettingsPageProps } from '@/types/admin';

export default function AdminSettingsPage({
    adminIdentity,
    adminUi,
    confirmPasswordUrl,
    direction,
    locale,
    logoutUrl,
    mfa,
    servicePricing,
    servicePricingUrls,
    team,
    teamUrls,
}: AdminSettingsPageProps) {
    const copy = adminUi.settings;

    return (
        <article className="space-y-8" dir={direction}>
            <Head title={copy.headTitle} />

            <header className="flex flex-col gap-3 border-b border-border pb-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="font-display text-2xl font-bold tracking-tight text-foreground md:text-3xl">
                            {copy.title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {copy.description}
                        </p>
                    </div>

                    <nav
                        aria-label={copy.title}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <a
                            className="inline-flex min-h-11 touch-manipulation items-center gap-2 rounded-md border border-border bg-card px-3.5 py-2 text-xs font-semibold text-foreground transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                            href="#security"
                        >
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-4 text-primary"
                            />
                            <span>{copy.securitySection}</span>
                        </a>
                        {team ? (
                            <a
                                className="inline-flex min-h-11 touch-manipulation items-center gap-2 rounded-md border border-border bg-card px-3.5 py-2 text-xs font-semibold text-foreground transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                                href="#team"
                            >
                                <Users
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <span>{copy.teamSection}</span>
                            </a>
                        ) : null}
                        {servicePricing ? (
                            <a
                                className="inline-flex min-h-11 touch-manipulation items-center gap-2 rounded-md border border-border bg-card px-3.5 py-2 text-xs font-semibold text-foreground transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                                href="#service-pricing"
                            >
                                <Tag
                                    aria-hidden="true"
                                    className="size-4 text-primary"
                                />
                                <span>{copy.servicePricingSection}</span>
                            </a>
                        ) : null}
                    </nav>
                </div>
            </header>

            <div className="flex flex-col gap-8">
                <section
                    aria-labelledby="admin-account-identity-title"
                    className="rounded-lg border border-border bg-card p-6 shadow-xs"
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-center gap-4">
                            <span
                                aria-hidden="true"
                                className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-border bg-accent text-base font-bold text-foreground"
                            >
                                {adminIdentity.name
                                    .trim()
                                    .charAt(0)
                                    .toUpperCase() || 'A'}
                            </span>
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <h2
                                    className="font-display text-lg font-semibold tracking-tight [overflow-wrap:anywhere] text-foreground"
                                    id="admin-account-identity-title"
                                >
                                    {adminIdentity.name}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {adminUi.settings.roles[
                                        adminIdentity.role
                                    ] ?? adminIdentity.role}
                                </p>
                            </div>
                        </div>
                        <div className="shrink-0">
                            <button
                                className="inline-flex min-h-11 min-w-[44px] cursor-pointer touch-manipulation items-center justify-center gap-2 rounded-md border border-border bg-card px-4 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                onClick={() => {
                                    router.flushAll();
                                    router.post(logoutUrl);
                                }}
                                type="button"
                            >
                                <LogOut
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-muted-foreground"
                                />
                                <span>{adminUi.common.logout}</span>
                            </button>
                        </div>
                    </div>
                </section>

                <AdminSecuritySection
                    adminUi={adminUi}
                    direction={direction}
                    locale={locale}
                    mfa={mfa}
                />

                {team ? (
                    <AdminTeamSection
                        adminUi={adminUi}
                        confirmPasswordUrl={confirmPasswordUrl}
                        direction={direction}
                        locale={locale}
                        team={team}
                        teamUrls={teamUrls}
                    />
                ) : null}

                {servicePricing ? (
                    <AdminServicePricingSection
                        adminUi={adminUi}
                        confirmPasswordUrl={confirmPasswordUrl}
                        direction={direction}
                        locale={locale}
                        servicePricing={servicePricing}
                        servicePricingUrls={servicePricingUrls}
                    />
                ) : null}
            </div>
        </article>
    );
}

'use no memo';

import { Head } from '@inertiajs/react';
import { ShieldCheck, Tag, Users } from 'lucide-react';

import AdminSecuritySection from '@/components/admin/settings/admin-security-section';
import AdminServicePricingSection from '@/components/admin/settings/admin-service-pricing-section';
import AdminTeamSection from '@/components/admin/settings/admin-team-section';
import type { AdminSettingsPageProps } from '@/types/admin';

export default function AdminSettingsPage({
    adminUi,
    confirmPasswordUrl,
    direction,
    locale,
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

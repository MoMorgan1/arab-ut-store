import React from 'react';

import type { AdminTranslations } from '@/types/admin';

export type AdminPagesHeaderProps = {
    copy: NonNullable<AdminTranslations['pages']>;
};

export default function AdminPagesHeader({ copy }: AdminPagesHeaderProps) {
    return (
        <header className="flex flex-col gap-4 border-b border-border pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="space-y-1">
                <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                    {copy.title}
                </h1>
                <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                    {copy.description}
                </p>
            </div>
        </header>
    );
}

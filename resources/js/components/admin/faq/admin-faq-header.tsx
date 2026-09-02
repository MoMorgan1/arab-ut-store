import { Plus } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';
import type { AdminTranslations } from '@/types/admin';

export type AdminFaqHeaderProps = {
    canManage: boolean;
    copy: NonNullable<AdminTranslations['faq']>;
    onCreateClick: () => void;
};

export default function AdminFaqHeader({
    canManage,
    copy,
    onCreateClick,
}: AdminFaqHeaderProps) {
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
            {canManage ? (
                <div className="flex shrink-0 items-center gap-2">
                    <Button
                        className="inline-flex min-h-11 items-center gap-2 md:min-h-9"
                        onClick={onCreateClick}
                        type="button"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        <span>{copy.newQuestion}</span>
                    </Button>
                </div>
            ) : null}
        </header>
    );
}

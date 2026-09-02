import { ExternalLink, Loader2, Save } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';
import type { AdminTranslations } from '@/types/admin';

export type AdminPageEditorHeaderProps = {
    address: string;
    canManage: boolean;
    copy: NonNullable<AdminTranslations['pages']>;
    isSaving: boolean;
    onSave: () => void;
    pageTitle: string;
    storeUrl: string;
};

export default function AdminPageEditorHeader({
    address,
    canManage,
    copy,
    isSaving,
    onSave,
    pageTitle,
    storeUrl,
}: AdminPageEditorHeaderProps) {
    return (
        <header className="flex flex-col gap-4 border-b border-border pb-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        {pageTitle}
                    </h1>
                    <span className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">
                        {address}
                    </span>
                </div>
                <div>
                    <a
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                        href={storeUrl}
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        <span>{copy.openInStore}</span>
                        <ExternalLink aria-hidden="true" className="size-3" />
                    </a>
                </div>
            </div>

            {canManage ? (
                <div className="flex shrink-0 items-center gap-2">
                    <Button
                        className="inline-flex min-h-11 items-center gap-2 bg-[#D4AF37] font-semibold text-black shadow-sm transition-colors hover:bg-[#c5a028] md:min-h-9 dark:bg-[#D4AF37] dark:text-black dark:hover:bg-[#c5a028]"
                        disabled={isSaving}
                        onClick={onSave}
                        type="button"
                    >
                        {isSaving ? (
                            <>
                                <Loader2
                                    aria-hidden="true"
                                    className="size-4 animate-spin"
                                />
                                <span>{copy.saving}</span>
                            </>
                        ) : (
                            <>
                                <Save aria-hidden="true" className="size-4" />
                                <span>{copy.savePage}</span>
                            </>
                        )}
                    </Button>
                </div>
            ) : null}
        </header>
    );
}

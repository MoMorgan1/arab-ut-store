import { Head, router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, HelpCircle, Plus } from 'lucide-react';
import React, { useEffect, useMemo, useState } from 'react';

import AdminPageEditorBlockRow from '@/components/admin/pages/admin-page-editor-block-row';
import AdminPageEditorHeader from '@/components/admin/pages/admin-page-editor-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    AdminEditorBlock,
    AdminStorePageEditorPageProps,
    AdminStorePageLocaleContent,
} from '@/types/admin';

export default function AdminStorePageEditorPage(
    props: AdminStorePageEditorPageProps,
) {
    const copy = props.adminUi.pages ?? {
        actions: 'Actions',
        addBlock: 'Add block',
        address: 'Address',
        blockContentPlaceholder: 'Write block content here…',
        blockCount: 'Blocks',
        blockType: 'Block type',
        blocksTitle: 'Blocks',
        description:
            'Manage policy pages, terms, and backup code guide content in both languages.',
        edit: 'Edit',
        headingLevel: 'Level',
        headingLevel2: 'Heading 2 (H2)',
        headingLevel3: 'Heading 3 (H3)',
        headingTextPlaceholder: 'Write heading text…',
        headTitle: 'Policy pages',
        helperText:
            'Use **bold** for emphasis and [label](url) for links. Write \\* or \\[ for literal characters.',
        listContentPlaceholder: 'Write one item per line…',
        listOrdered: 'Numbered list',
        moveDown: 'Move down',
        moveUp: 'Move up',
        noticeTone: 'Tone',
        noticeToneInfo: 'Info',
        noticeToneShield: 'Shield',
        noticeToneWarning: 'Warning',
        openInStore: 'Open on the store',
        page: 'Page',
        removeBlock: 'Remove block',
        savedSuccess: 'Page saved successfully.',
        saveError:
            'Unable to save page. Please review the form and resolve any errors.',
        savePage: 'Save page',
        saving: 'Saving…',
        subtitleLabel: 'Subtitle (optional)',
        tabArabic: 'العربية',
        tabEnglish: 'English',
        tableLabel: 'Policy pages list',
        title: 'Policy pages',
        titleLabel: 'Page title',
        typeDivider: 'Divider',
        typeHeading: 'Heading',
        typeList: 'List',
        typeNotice: 'Notice',
        typeParagraph: 'Paragraph',
        unsavedChangesWarning:
            'You have unsaved changes. Are you sure you want to leave?',
        updatedLabel: 'Displayed update date',
        updatedLabelLabel: 'Last updated text (shown on the page)',
    };

    const initialContent = useMemo(
        () => ({
            ar: {
                title: props.content.ar.title ?? '',
                subtitle: props.content.ar.subtitle ?? '',
                updatedLabel: props.content.ar.updatedLabel ?? '',
                blocks: props.content.ar.blocks ?? [],
            },
            en: {
                title: props.content.en.title ?? '',
                subtitle: props.content.en.subtitle ?? '',
                updatedLabel: props.content.en.updatedLabel ?? '',
                blocks: props.content.en.blocks ?? [],
            },
        }),
        [props.content],
    );

    const [content, setContent] = useState(initialContent);
    const [lastSavedContent, setLastSavedContent] = useState(initialContent);
    const [activeTab, setActiveTab] = useState<'ar' | 'en'>(
        props.locale === 'en' ? 'en' : 'ar',
    );
    const [isSaving, setIsSaving] = useState(false);
    const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);
    const [errorAlert, setErrorAlert] = useState<string | null>(null);
    const [blockErrors, setBlockErrors] = useState<Record<string, string>>({});

    const isDirty = useMemo(() => {
        return JSON.stringify(content) !== JSON.stringify(lastSavedContent);
    }, [content, lastSavedContent]);

    // Unsaved changes browser prompt
    useEffect(() => {
        const handleBeforeUnload = (e: BeforeUnloadEvent) => {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [isDirty]);

    // Inertia router navigation prompt
    useEffect(() => {
        const removeListener = router.on('before', (event) => {
            if (isDirty) {
                if (!window.confirm(copy.unsavedChangesWarning)) {
                    event.preventDefault();
                }
            }
        });

        return () => {
            removeListener();
        };
    }, [isDirty, copy.unsavedChangesWarning]);

    const handleFieldChange = (
        locale: 'ar' | 'en',
        field: keyof AdminStorePageLocaleContent,
        value: any,
    ) => {
        setContent((prev) => ({
            ...prev,
            [locale]: {
                ...prev[locale],
                [field]: value,
            },
        }));
    };

    const handleBlockChange = (
        locale: 'ar' | 'en',
        index: number,
        updated: AdminEditorBlock,
    ) => {
        setContent((prev) => {
            const blocks = [...prev[locale].blocks];
            blocks[index] = updated;

            return {
                ...prev,
                [locale]: {
                    ...prev[locale],
                    blocks,
                },
            };
        });
    };

    const handleAddBlock = (locale: 'ar' | 'en') => {
        setContent((prev) => ({
            ...prev,
            [locale]: {
                ...prev[locale],
                blocks: [
                    ...prev[locale].blocks,
                    { type: 'paragraph', text: '' },
                ],
            },
        }));
    };

    const handleMoveBlock = (
        locale: 'ar' | 'en',
        fromIndex: number,
        toIndex: number,
    ) => {
        setContent((prev) => {
            const blocks = [...prev[locale].blocks];
            const [moved] = blocks.splice(fromIndex, 1);
            blocks.splice(toIndex, 0, moved);

            return {
                ...prev,
                [locale]: {
                    ...prev[locale],
                    blocks,
                },
            };
        });
    };

    const handleRemoveBlock = (locale: 'ar' | 'en', index: number) => {
        setContent((prev) => {
            const blocks = [...prev[locale].blocks];
            blocks.splice(index, 1);

            return {
                ...prev,
                [locale]: {
                    ...prev[locale],
                    blocks,
                },
            };
        });
    };

    const handleSave = async () => {
        setFeedbackMessage(null);
        setErrorAlert(null);
        setBlockErrors({});
        setIsSaving(true);

        try {
            const xsrfToken = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] ?? '',
            );

            const payload = {
                ar: {
                    title: content.ar.title,
                    subtitle: content.ar.subtitle || null,
                    updatedLabel: content.ar.updatedLabel,
                    blocks: content.ar.blocks,
                },
                en: {
                    title: content.en.title,
                    subtitle: content.en.subtitle || null,
                    updatedLabel: content.en.updatedLabel,
                    blocks: content.en.blocks,
                },
            };

            const response = await fetch(props.saveUrl, {
                body: JSON.stringify(payload),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken,
                },
                method: 'PUT',
            });

            if (response.ok) {
                setLastSavedContent(content);
                setFeedbackMessage(copy.savedSuccess);

                return;
            }

            const data = await response.json();
            const errors = data.errors ?? {};
            const newBlockErrors: Record<string, string> = {};
            let generalMessage = data.message || copy.saveError;

            if (errors.error) {
                const errText = Array.isArray(errors.error)
                    ? errors.error.join(' ')
                    : String(errors.error);
                generalMessage = errText;

                // Match block number if in format "Block #2 ..."
                const match = errText.match(/Block #(\d+)/i);

                if (match) {
                    const blockNum = parseInt(match[1], 10);

                    if (!isNaN(blockNum) && blockNum > 0) {
                        newBlockErrors[`${activeTab}.${blockNum - 1}`] =
                            errText;
                    }
                }
            }

            // Check field errors like ar.blocks.0.text
            for (const [key, val] of Object.entries(errors)) {
                const message = Array.isArray(val)
                    ? val.join(' ')
                    : String(val);
                const blockKeyMatch = key.match(/^(ar|en)\.blocks\.(\d+)/);

                if (blockKeyMatch) {
                    const loc = blockKeyMatch[1];
                    const idx = blockKeyMatch[2];
                    newBlockErrors[`${loc}.${idx}`] = message;
                }
            }

            setBlockErrors(newBlockErrors);
            setErrorAlert(generalMessage);
        } catch {
            setErrorAlert(copy.saveError);
        } finally {
            setIsSaving(false);
        }
    };

    const currentTabContent = content[activeTab];
    const isTabArabic = activeTab === 'ar';

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head
                title={`${copy.headTitle} - ${content.ar.title || content.en.title}`}
            />

            <AdminPageEditorHeader
                address={props.storeUrl}
                canManage={props.canManage}
                copy={copy}
                isSaving={isSaving}
                onSave={handleSave}
                pageTitle={
                    isTabArabic
                        ? content.ar.title || copy.title
                        : content.en.title || copy.title
                }
                storeUrl={props.storeUrl}
            />

            {feedbackMessage ? (
                <Alert
                    className="border-emerald-500/40 bg-emerald-500/10 text-emerald-900 dark:text-emerald-200"
                    role="status"
                >
                    <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                    <AlertTitle>{copy.savedSuccess}</AlertTitle>
                </Alert>
            ) : null}

            {errorAlert ? (
                <Alert role="alert" variant="destructive">
                    <AlertCircle className="size-4" />
                    <AlertTitle>{copy.saveError}</AlertTitle>
                    <AlertDescription>{errorAlert}</AlertDescription>
                </Alert>
            ) : null}

            {/* Locale Tabs */}
            <div
                className="flex items-center gap-2 border-b border-border pb-1"
                role="tablist"
            >
                <button
                    aria-selected={activeTab === 'ar'}
                    className={cn(
                        'inline-flex min-h-11 items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors md:min-h-9',
                        activeTab === 'ar'
                            ? 'bg-muted font-semibold text-foreground shadow-xs'
                            : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                    )}
                    onClick={() => setActiveTab('ar')}
                    role="tab"
                    type="button"
                >
                    {copy.tabArabic}
                </button>
                <button
                    aria-selected={activeTab === 'en'}
                    className={cn(
                        'inline-flex min-h-11 items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors md:min-h-9',
                        activeTab === 'en'
                            ? 'bg-muted font-semibold text-foreground shadow-xs'
                            : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                    )}
                    onClick={() => setActiveTab('en')}
                    role="tab"
                    type="button"
                >
                    {copy.tabEnglish}
                </button>
            </div>

            {/* Metadata Fields for Current Locale */}
            <div className="space-y-4 rounded-lg border border-border bg-card p-5">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor={`page-title-${activeTab}`}>
                            {copy.titleLabel}
                        </Label>
                        <Input
                            className="h-10 text-sm"
                            dir={isTabArabic ? 'rtl' : 'ltr'}
                            id={`page-title-${activeTab}`}
                            maxLength={120}
                            onChange={(e) =>
                                handleFieldChange(
                                    activeTab,
                                    'title',
                                    e.target.value,
                                )
                            }
                            value={currentTabContent.title}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor={`page-subtitle-${activeTab}`}>
                            {copy.subtitleLabel}
                        </Label>
                        <Input
                            className="h-10 text-sm"
                            dir={isTabArabic ? 'rtl' : 'ltr'}
                            id={`page-subtitle-${activeTab}`}
                            maxLength={120}
                            onChange={(e) =>
                                handleFieldChange(
                                    activeTab,
                                    'subtitle',
                                    e.target.value,
                                )
                            }
                            value={currentTabContent.subtitle ?? ''}
                        />
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor={`page-updated-label-${activeTab}`}>
                            {copy.updatedLabelLabel}
                        </Label>
                        <Input
                            className="h-10 text-sm"
                            dir={isTabArabic ? 'rtl' : 'ltr'}
                            id={`page-updated-label-${activeTab}`}
                            maxLength={60}
                            onChange={(e) =>
                                handleFieldChange(
                                    activeTab,
                                    'updatedLabel',
                                    e.target.value,
                                )
                            }
                            value={currentTabContent.updatedLabel}
                        />
                    </div>
                </div>
            </div>

            {/* Blocks Section */}
            <div className="space-y-4">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <h2 className="text-lg font-bold text-foreground">
                        {copy.blocksTitle} ({currentTabContent.blocks.length})
                    </h2>
                    <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <HelpCircle
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                        <span>{copy.helperText}</span>
                    </p>
                </div>

                {/* Blocks list */}
                <div className="space-y-3">
                    {currentTabContent.blocks.map((block, index) => (
                        <AdminPageEditorBlockRow
                            block={block}
                            copy={copy}
                            error={blockErrors[`${activeTab}.${index}`]}
                            index={index}
                            isFirst={index === 0}
                            isLast={
                                index === currentTabContent.blocks.length - 1
                            }
                            key={`${activeTab}-${index}`}
                            locale={activeTab}
                            onChange={(updated) =>
                                handleBlockChange(activeTab, index, updated)
                            }
                            onMoveDown={() =>
                                handleMoveBlock(activeTab, index, index + 1)
                            }
                            onMoveUp={() =>
                                handleMoveBlock(activeTab, index, index - 1)
                            }
                            onRemove={() => handleRemoveBlock(activeTab, index)}
                        />
                    ))}
                </div>

                {/* Add Block button */}
                <div className="pt-2">
                    <Button
                        className="inline-flex min-h-11 items-center gap-2 md:min-h-9"
                        disabled={
                            !props.canManage ||
                            currentTabContent.blocks.length >= 60
                        }
                        onClick={() => handleAddBlock(activeTab)}
                        type="button"
                        variant="outline"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        <span>{copy.addBlock}</span>
                    </Button>
                </div>
            </div>
        </article>
    );
}

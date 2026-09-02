import { AlertCircle, ArrowDown, ArrowUp, Trash2 } from 'lucide-react';
import React from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { AdminEditorBlock, AdminTranslations } from '@/types/admin';

export type AdminPageEditorBlockRowProps = {
    block: AdminEditorBlock;
    copy: NonNullable<AdminTranslations['pages']>;
    error?: string;
    index: number;
    isFirst: boolean;
    isLast: boolean;
    locale: 'ar' | 'en';
    onChange: (updated: AdminEditorBlock) => void;
    onMoveDown: () => void;
    onMoveUp: () => void;
    onRemove: () => void;
};

export default function AdminPageEditorBlockRow({
    block,
    copy,
    error,
    index,
    isFirst,
    isLast,
    locale,
    onChange,
    onMoveDown,
    onMoveUp,
    onRemove,
}: AdminPageEditorBlockRowProps) {
    const handleTypeChange = (value: string) => {
        const type = value as AdminEditorBlock['type'];
        const updated: AdminEditorBlock = {
            ...block,
            type,
        };

        if (type === 'heading') {
            updated.level = updated.level ?? 2;
        } else if (type === 'notice') {
            updated.tone = updated.tone ?? 'info';
        } else if (type === 'list') {
            updated.ordered = updated.ordered ?? false;
        }

        onChange(updated);
    };

    return (
        <div
            className={cn(
                'relative space-y-3 rounded-lg border bg-card p-4 transition-colors',
                error ? 'border-destructive/60' : 'border-border',
            )}
            data-block-index={index}
        >
            {/* Top row: Index, Type selector, specific controls, and Action buttons */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 pb-3">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="flex size-7 items-center justify-center rounded-md bg-muted font-mono text-xs font-semibold text-muted-foreground">
                        #{index + 1}
                    </span>

                    {/* Block type selector */}
                    <div className="w-36 sm:w-40">
                        <Select
                            onValueChange={handleTypeChange}
                            value={block.type}
                        >
                            <SelectTrigger className="h-9 text-xs">
                                <SelectValue placeholder={copy.blockType} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="paragraph">
                                    {copy.typeParagraph}
                                </SelectItem>
                                <SelectItem value="heading">
                                    {copy.typeHeading}
                                </SelectItem>
                                <SelectItem value="list">
                                    {copy.typeList}
                                </SelectItem>
                                <SelectItem value="notice">
                                    {copy.typeNotice}
                                </SelectItem>
                                <SelectItem value="divider">
                                    {copy.typeDivider}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Heading level */}
                    {block.type === 'heading' ? (
                        <div className="w-36">
                            <Select
                                onValueChange={(val) =>
                                    onChange({
                                        ...block,
                                        level: Number(val) as 2 | 3,
                                    })
                                }
                                value={String(block.level ?? 2)}
                            >
                                <SelectTrigger className="h-9 text-xs">
                                    <SelectValue
                                        placeholder={copy.headingLevel}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="2">
                                        {copy.headingLevel2}
                                    </SelectItem>
                                    <SelectItem value="3">
                                        {copy.headingLevel3}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}

                    {/* Notice tone */}
                    {block.type === 'notice' ? (
                        <div className="w-36">
                            <Select
                                onValueChange={(val) =>
                                    onChange({
                                        ...block,
                                        tone: val as
                                            'info' | 'shield' | 'warning',
                                    })
                                }
                                value={block.tone ?? 'info'}
                            >
                                <SelectTrigger className="h-9 text-xs">
                                    <SelectValue
                                        placeholder={copy.noticeTone}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="info">
                                        {copy.noticeToneInfo}
                                    </SelectItem>
                                    <SelectItem value="shield">
                                        {copy.noticeToneShield}
                                    </SelectItem>
                                    <SelectItem value="warning">
                                        {copy.noticeToneWarning}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}

                    {/* List ordered checkbox */}
                    {block.type === 'list' ? (
                        <label className="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-foreground">
                            <Checkbox
                                checked={Boolean(block.ordered)}
                                onCheckedChange={(checked) =>
                                    onChange({
                                        ...block,
                                        ordered: Boolean(checked),
                                    })
                                }
                            />
                            <span>{copy.listOrdered}</span>
                        </label>
                    ) : null}
                </div>

                {/* Move & Remove action buttons */}
                <div className="flex items-center gap-1">
                    <Button
                        aria-label={copy.moveUp}
                        className="size-9 p-0 md:size-8"
                        disabled={isFirst}
                        onClick={onMoveUp}
                        size="icon"
                        title={copy.moveUp}
                        type="button"
                        variant="ghost"
                    >
                        <ArrowUp aria-hidden="true" className="size-4" />
                    </Button>
                    <Button
                        aria-label={copy.moveDown}
                        className="size-9 p-0 md:size-8"
                        disabled={isLast}
                        onClick={onMoveDown}
                        size="icon"
                        title={copy.moveDown}
                        type="button"
                        variant="ghost"
                    >
                        <ArrowDown aria-hidden="true" className="size-4" />
                    </Button>
                    <Button
                        aria-label={copy.removeBlock}
                        className="size-9 p-0 text-muted-foreground hover:text-destructive md:size-8"
                        onClick={onRemove}
                        size="icon"
                        title={copy.removeBlock}
                        type="button"
                        variant="ghost"
                    >
                        <Trash2 aria-hidden="true" className="size-4" />
                    </Button>
                </div>
            </div>

            {/* Block content */}
            {block.type === 'divider' ? (
                <div className="flex items-center justify-center py-3">
                    <div className="h-px w-full bg-border" />
                </div>
            ) : block.type === 'heading' ? (
                <div className="space-y-1">
                    <Input
                        className="h-10 text-sm"
                        dir={locale === 'en' ? 'ltr' : 'rtl'}
                        onChange={(e) =>
                            onChange({ ...block, text: e.target.value })
                        }
                        placeholder={copy.headingTextPlaceholder}
                        value={block.text ?? ''}
                    />
                </div>
            ) : (
                <div className="space-y-1">
                    <textarea
                        className="flex min-h-[96px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
                        dir={locale === 'en' ? 'ltr' : 'rtl'}
                        onChange={(e) =>
                            onChange({ ...block, text: e.target.value })
                        }
                        placeholder={
                            block.type === 'list'
                                ? copy.listContentPlaceholder
                                : copy.blockContentPlaceholder
                        }
                        rows={block.type === 'list' ? 4 : 3}
                        value={block.text ?? ''}
                    />
                </div>
            )}

            {/* Inline error */}
            {error ? (
                <div
                    className="flex items-center gap-1.5 text-xs text-destructive"
                    role="alert"
                >
                    <AlertCircle aria-hidden="true" className="size-3.5" />
                    <span>{error}</span>
                </div>
            ) : null}
        </div>
    );
}

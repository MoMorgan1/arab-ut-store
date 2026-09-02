'use no memo';

import { useHttp } from '@inertiajs/react';
import React, { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminFaqRow, AdminTranslations } from '@/types/admin';

export type AdminFaqEntryDialogProps = {
    copy: NonNullable<AdminTranslations['faq']>;
    createUrl: string;
    entry: AdminFaqRow | null;
    onOpenChange: (open: boolean) => void;
    onSuccess: (message: string) => void;
    open: boolean;
    updateUrlTemplate: string;
};

type FaqPayload = {
    answer_ar: string;
    answer_en: string;
    question_ar: string;
    question_en: string;
};

type FaqResponse = {
    data: {
        faq: string;
        sort_order?: number;
    };
};

type FieldErrors = {
    answer_ar?: string;
    answer_en?: string;
    general?: string;
    question_ar?: string;
    question_en?: string;
};

export default function AdminFaqEntryDialog({
    copy,
    createUrl,
    entry,
    onOpenChange,
    onSuccess,
    open,
    updateUrlTemplate,
}: AdminFaqEntryDialogProps) {
    const isEditing = entry !== null;
    const [questionAr, setQuestionAr] = useState('');
    const [questionEn, setQuestionEn] = useState('');
    const [answerAr, setAnswerAr] = useState('');
    const [answerEn, setAnswerEn] = useState('');
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

    const [prevOpen, setPrevOpen] = useState(open);
    const [prevEntryId, setPrevEntryId] = useState(entry?.id);

    if (open !== prevOpen || entry?.id !== prevEntryId) {
        setPrevOpen(open);
        setPrevEntryId(entry?.id);

        if (open) {
            if (entry) {
                setQuestionAr(entry.questionAr);
                setQuestionEn(entry.questionEn);
                setAnswerAr(entry.answerAr);
                setAnswerEn(entry.answerEn);
            } else {
                setQuestionAr('');
                setQuestionEn('');
                setAnswerAr('');
                setAnswerEn('');
            }

            setFieldErrors({});
        }
    }

    const targetUrl = isEditing
        ? updateUrlTemplate.replace('__ID__', entry.id)
        : createUrl;
    const method = isEditing ? 'put' : 'post';

    const http = useHttp<FaqPayload, FaqResponse>(method, targetUrl, {
        answer_ar: answerAr,
        answer_en: answerEn,
        question_ar: questionAr,
        question_en: questionEn,
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        const errors: FieldErrors = {};
        const trimmedQAr = questionAr.trim();
        const trimmedQEn = questionEn.trim();
        const trimmedAAr = answerAr.trim();
        const trimmedAEn = answerEn.trim();

        if (!trimmedQAr) {
            errors.question_ar = 'Question (Arabic) is required.';
        } else if (trimmedQAr.length > 200) {
            errors.question_ar =
                'Question (Arabic) cannot exceed 200 characters.';
        }

        if (!trimmedQEn) {
            errors.question_en = 'Question (English) is required.';
        } else if (trimmedQEn.length > 200) {
            errors.question_en =
                'Question (English) cannot exceed 200 characters.';
        }

        if (!trimmedAAr) {
            errors.answer_ar = 'Answer (Arabic) is required.';
        } else if (trimmedAAr.length > 2000) {
            errors.answer_ar = 'Answer (Arabic) cannot exceed 2000 characters.';
        }

        if (!trimmedAEn) {
            errors.answer_en = 'Answer (English) is required.';
        } else if (trimmedAEn.length > 2000) {
            errors.answer_en =
                'Answer (English) cannot exceed 2000 characters.';
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);

            return;
        }

        setFieldErrors({});
        http.setData({
            answer_ar: trimmedAAr,
            answer_en: trimmedAEn,
            question_ar: trimmedQAr,
            question_en: trimmedQEn,
        });

        let handled = false;

        try {
            await http.submit(method, targetUrl, {
                headers: { Accept: 'application/json' },
                onError: (errs) => {
                    handled = true;
                    setFieldErrors({
                        answer_ar: errs.answer_ar,
                        answer_en: errs.answer_en,
                        question_ar: errs.question_ar,
                        question_en: errs.question_en,
                    });
                },
                onHttpException: (response) => {
                    handled = true;

                    if (response.status === 422) {
                        const body = (
                            typeof response.data === 'string'
                                ? JSON.parse(response.data)
                                : response.data
                        ) as {
                            errors?: Record<string, string>;
                            message?: string;
                        };
                        setFieldErrors({
                            answer_ar: body?.errors?.answer_ar,
                            answer_en: body?.errors?.answer_en,
                            general: body?.message,
                            question_ar: body?.errors?.question_ar,
                            question_en: body?.errors?.question_en,
                        });

                        return false;
                    }

                    setFieldErrors({ general: copy.errorTitle });

                    return false;
                },
                onNetworkError: () => {
                    handled = true;
                    setFieldErrors({ general: copy.errorTitle });

                    return false;
                },
                onSuccess: () => {
                    handled = true;
                    onOpenChange(false);
                    onSuccess(
                        isEditing ? copy.updatedMessage : copy.createdMessage,
                    );
                },
            });
        } catch {
            if (!handled) {
                setFieldErrors({ general: copy.errorTitle });
            }
        }
    };

    const title = isEditing ? copy.editDialogTitle : copy.createDialogTitle;
    const description = isEditing
        ? copy.editDialogDescription
        : copy.createDialogDescription;

    return (
        <Dialog onOpenChange={onOpenChange} open={open}>
            <DialogContent className="max-w-lg">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        {fieldErrors.general ? (
                            <p className="text-xs font-medium text-destructive">
                                {fieldErrors.general}
                            </p>
                        ) : null}

                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="faq-question-ar"
                                >
                                    {copy.questionArLabel}
                                </Label>
                                <span className="text-[11px] text-muted-foreground">
                                    {questionAr.length}/200
                                </span>
                            </div>
                            <Input
                                aria-describedby={
                                    fieldErrors.question_ar
                                        ? 'faq-question-ar-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(fieldErrors.question_ar)}
                                dir="rtl"
                                disabled={http.processing}
                                id="faq-question-ar"
                                maxLength={200}
                                onChange={(e) => setQuestionAr(e.target.value)}
                                value={questionAr}
                            />
                            {fieldErrors.question_ar ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="faq-question-ar-error"
                                >
                                    {fieldErrors.question_ar}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="faq-question-en"
                                >
                                    {copy.questionEnLabel}
                                </Label>
                                <span className="text-[11px] text-muted-foreground">
                                    {questionEn.length}/200
                                </span>
                            </div>
                            <Input
                                aria-describedby={
                                    fieldErrors.question_en
                                        ? 'faq-question-en-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(fieldErrors.question_en)}
                                dir="ltr"
                                disabled={http.processing}
                                id="faq-question-en"
                                maxLength={200}
                                onChange={(e) => setQuestionEn(e.target.value)}
                                value={questionEn}
                            />
                            {fieldErrors.question_en ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="faq-question-en-error"
                                >
                                    {fieldErrors.question_en}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="faq-answer-ar"
                                >
                                    {copy.answerArLabel}
                                </Label>
                                <span className="text-[11px] text-muted-foreground">
                                    {answerAr.length}/2000
                                </span>
                            </div>
                            <textarea
                                aria-describedby={
                                    fieldErrors.answer_ar
                                        ? 'faq-answer-ar-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(fieldErrors.answer_ar)}
                                className="flex min-h-[96px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-xs"
                                dir="rtl"
                                disabled={http.processing}
                                id="faq-answer-ar"
                                maxLength={2000}
                                onChange={(e) => setAnswerAr(e.target.value)}
                                rows={4}
                                value={answerAr}
                            />
                            {fieldErrors.answer_ar ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="faq-answer-ar-error"
                                >
                                    {fieldErrors.answer_ar}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label
                                    className="text-xs font-semibold"
                                    htmlFor="faq-answer-en"
                                >
                                    {copy.answerEnLabel}
                                </Label>
                                <span className="text-[11px] text-muted-foreground">
                                    {answerEn.length}/2000
                                </span>
                            </div>
                            <textarea
                                aria-describedby={
                                    fieldErrors.answer_en
                                        ? 'faq-answer-en-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(fieldErrors.answer_en)}
                                className="flex min-h-[96px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-xs"
                                dir="ltr"
                                disabled={http.processing}
                                id="faq-answer-en"
                                maxLength={2000}
                                onChange={(e) => setAnswerEn(e.target.value)}
                                rows={4}
                                value={answerEn}
                            />
                            {fieldErrors.answer_en ? (
                                <p
                                    className="text-xs text-destructive"
                                    id="faq-answer-en-error"
                                >
                                    {fieldErrors.answer_en}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                disabled={http.processing}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                        </DialogClose>
                        <Button disabled={http.processing} type="submit">
                            {http.processing ? (
                                <>
                                    <Spinner className="size-4" />
                                    <span>{copy.savingButton}</span>
                                </>
                            ) : (
                                <span>{copy.saveButton}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

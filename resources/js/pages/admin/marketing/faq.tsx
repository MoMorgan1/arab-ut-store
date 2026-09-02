import { Head, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import React, { useState } from 'react';

import AdminFaqDeleteDialog from '@/components/admin/faq/admin-faq-delete-dialog';
import AdminFaqEntryDialog from '@/components/admin/faq/admin-faq-entry-dialog';
import AdminFaqHeader from '@/components/admin/faq/admin-faq-header';
import AdminFaqMobileCard from '@/components/admin/faq/admin-faq-mobile-card';
import AdminFaqTable from '@/components/admin/faq/admin-faq-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { AdminFaqPageProps, AdminFaqRow } from '@/types/admin';

export default function AdminFaqPage(props: AdminFaqPageProps) {
    const copy = props.adminUi.faq ?? {
        actions: 'Actions',
        answerArLabel: 'Answer (Arabic)',
        answerEnLabel: 'Answer (English)',
        cancelButton: 'Cancel',
        charLimit: 'Up to :max characters',
        confirmDelete: 'Delete question',
        createDialogDescription:
            'Add a question and its answer in both Arabic and English.',
        createDialogTitle: 'New question',
        createdMessage: 'FAQ entry created successfully.',
        delete: 'Delete',
        deleteDialogDescription:
            'Are you sure you want to delete ":question"? This action cannot be undone.',
        deleteDialogTitle: 'Delete question?',
        deletedMessage: 'FAQ entry deleted.',
        deleting: 'Deleting…',
        description:
            'Manage storefront questions and answers, reorder them, and toggle their visibility.',
        edit: 'Edit',
        editDialogDescription:
            'Update the question and its answer in both Arabic and English.',
        editDialogTitle: 'Edit question',
        errorTitle: 'Action failed',
        headTitle: 'FAQ',
        hideFromStore: 'Hide from store',
        moveDown: 'Move down',
        moveFailed: 'Could not move the question. Please try again.',
        moveUp: 'Move up',
        newQuestion: 'New question',
        noEntries: 'No FAQ entries yet.',
        position: '#',
        question: 'Question',
        questionArLabel: 'Question (Arabic)',
        questionEnLabel: 'Question (English)',
        saveButton: 'Save',
        savingButton: 'Saving…',
        showInStore: 'Show in store',
        stateHidden: 'Hidden',
        stateVisible: 'Visible',
        status: 'Status',
        tableLabel: 'FAQ entries list',
        title: 'Frequently asked questions',
        updatedAt: 'Last updated',
        updatedMessage: 'FAQ entry updated successfully.',
        visibilityConflictError:
            'The storefront visibility was modified by another operator. Current state has been refreshed.',
        visibilityHiddenMessage: 'The question has been hidden from the store.',
        visibilityShownMessage: 'The question is now shown in the store.',
        visibilityUpdateFailed:
            'We could not update the question visibility. Please check your connection and try again.',
    };

    const canManage = props.canManage;

    const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);
    const [conflictAlert, setConflictAlert] = useState<string | null>(null);
    const [errorAlert, setErrorAlert] = useState<string | null>(null);

    const [entryDialogOpen, setEntryDialogOpen] = useState(false);
    const [editingEntry, setEditingEntry] = useState<AdminFaqRow | null>(null);

    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deletingEntry, setDeletingEntry] = useState<AdminFaqRow | null>(
        null,
    );

    const [movingId, setMovingId] = useState<string | null>(null);
    const [togglingId, setTogglingId] = useState<string | null>(null);

    const handleCreateClick = () => {
        setFeedbackMessage(null);
        setConflictAlert(null);
        setErrorAlert(null);
        setEditingEntry(null);
        setEntryDialogOpen(true);
    };

    const handleEditClick = (entry: AdminFaqRow) => {
        setFeedbackMessage(null);
        setConflictAlert(null);
        setErrorAlert(null);
        setEditingEntry(entry);
        setEntryDialogOpen(true);
    };

    const handleDeleteClick = (entry: AdminFaqRow) => {
        setFeedbackMessage(null);
        setConflictAlert(null);
        setErrorAlert(null);
        setDeletingEntry(entry);
        setDeleteDialogOpen(true);
    };

    const handleDialogSuccess = (message: string) => {
        setFeedbackMessage(message);
        router.reload({ only: ['entries'] });
    };

    const handleToggleVisibility = async (entry: AdminFaqRow) => {
        setFeedbackMessage(null);
        setConflictAlert(null);
        setErrorAlert(null);
        setTogglingId(entry.id);

        const url = props.visibilityUrlTemplate.replace('__ID__', entry.id);

        try {
            const response = await fetch(url, {
                body: JSON.stringify({
                    expectedVisible: entry.isVisible,
                    visible: !entry.isVisible,
                }),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN':
                        decodeURIComponent(
                            document.cookie
                                .split('; ')
                                .find((row) => row.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] ?? '',
                        ) || '',
                },
                method: 'POST',
            });

            if (response.status === 409) {
                setConflictAlert(copy.visibilityConflictError);
                router.reload({ only: ['entries'] });

                return;
            }

            if (!response.ok) {
                setErrorAlert(copy.visibilityUpdateFailed);

                return;
            }

            setFeedbackMessage(
                !entry.isVisible
                    ? copy.visibilityShownMessage
                    : copy.visibilityHiddenMessage,
            );
            router.reload({ only: ['entries'] });
        } catch {
            setErrorAlert(copy.visibilityUpdateFailed);
        } finally {
            setTogglingId(null);
        }
    };

    const handleMove = async (entry: AdminFaqRow, direction: 'up' | 'down') => {
        setFeedbackMessage(null);
        setConflictAlert(null);
        setErrorAlert(null);
        setMovingId(entry.id);

        const url = props.moveUrlTemplate.replace('__ID__', entry.id);

        try {
            const response = await fetch(url, {
                body: JSON.stringify({ direction }),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN':
                        decodeURIComponent(
                            document.cookie
                                .split('; ')
                                .find((row) => row.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] ?? '',
                        ) || '',
                },
                method: 'POST',
            });

            if (!response.ok) {
                setErrorAlert(copy.moveFailed);

                return;
            }

            router.reload({ only: ['entries'] });
        } catch {
            setErrorAlert(copy.moveFailed);
        } finally {
            setMovingId(null);
        }
    };

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />

            <AdminFaqHeader
                canManage={canManage}
                copy={copy}
                onCreateClick={handleCreateClick}
            />

            {feedbackMessage ? (
                <Alert
                    className="border-emerald-500/40 bg-emerald-500/10 text-emerald-900 dark:text-emerald-200"
                    role="status"
                >
                    <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                    <AlertTitle>{copy.status}</AlertTitle>
                    <AlertDescription>{feedbackMessage}</AlertDescription>
                </Alert>
            ) : null}

            {conflictAlert ? (
                <Alert role="alert" variant="destructive">
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription>{conflictAlert}</AlertDescription>
                </Alert>
            ) : null}

            {errorAlert ? (
                <Alert role="alert" variant="destructive">
                    <AlertTitle>{copy.errorTitle}</AlertTitle>
                    <AlertDescription>{errorAlert}</AlertDescription>
                </Alert>
            ) : null}

            <AdminFaqTable
                canManage={canManage}
                copy={copy}
                entries={props.entries}
                movingId={movingId}
                onDeleteClick={handleDeleteClick}
                onEditClick={handleEditClick}
                onMoveClick={handleMove}
                onToggleVisibility={handleToggleVisibility}
                togglingId={togglingId}
            />

            <AdminFaqMobileCard
                canManage={canManage}
                copy={copy}
                entries={props.entries}
                movingId={movingId}
                onDeleteClick={handleDeleteClick}
                onEditClick={handleEditClick}
                onMoveClick={handleMove}
                onToggleVisibility={handleToggleVisibility}
                togglingId={togglingId}
            />

            <AdminFaqEntryDialog
                copy={copy}
                createUrl={props.createUrl}
                entry={editingEntry}
                onOpenChange={setEntryDialogOpen}
                onSuccess={handleDialogSuccess}
                open={entryDialogOpen}
                updateUrlTemplate={props.updateUrlTemplate}
            />

            <AdminFaqDeleteDialog
                copy={copy}
                deleteUrlTemplate={props.deleteUrlTemplate}
                entry={deletingEntry}
                onOpenChange={setDeleteDialogOpen}
                onSuccess={handleDialogSuccess}
                open={deleteDialogOpen}
            />
        </article>
    );
}

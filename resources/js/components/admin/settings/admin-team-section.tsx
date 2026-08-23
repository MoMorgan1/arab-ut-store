'use no memo';

import { router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    LoaderCircle,
    UserCheck,
    UserX,
    Users,
} from 'lucide-react';
import React, { useRef, useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import AdminPasswordConfirmDialog from '@/components/admin/admin-password-confirm-dialog';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AdminTeamData,
    AdminTeamMember,
    AdminTeamUrls,
    AdminTranslations,
} from '@/types/admin';

export type AdminTeamSectionProps = {
    adminUi: AdminTranslations;
    confirmPasswordUrl?: string;
    direction: 'rtl' | 'ltr';
    locale: 'ar' | 'en';
    team: AdminTeamData;
    teamUrls: AdminTeamUrls | null;
};

type ActionAlert = {
    text: string;
    type: 'success' | 'error';
};

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export default function AdminTeamSection({
    adminUi,
    confirmPasswordUrl,
    locale,
    team,
    teamUrls,
}: AdminTeamSectionProps) {
    const copy = adminUi.settings;
    const [members, setMembers] = useState<AdminTeamMember[]>(team.members);
    const [syncedMembers, setSyncedMembers] = useState(team.members);

    if (team.members !== syncedMembers) {
        setSyncedMembers(team.members);
        setMembers(team.members);
    }

    const [selectedRoles, setSelectedRoles] = useState<
        Record<string, 'admin' | 'staff'>
    >({});
    const [alertState, setAlertState] = useState<ActionAlert | null>(null);

    // Role dialog state
    const [roleDialogOpen, setRoleDialogOpen] = useState(false);
    const [roleDialogMember, setRoleDialogMember] =
        useState<AdminTeamMember | null>(null);
    const [roleDialogTargetRole, setRoleDialogTargetRole] = useState<
        'admin' | 'staff'
    >('staff');
    const [roleSubmitting, setRoleSubmitting] = useState(false);
    const [roleError, setRoleError] = useState<string | null>(null);

    // Status dialog state
    const [statusDialogOpen, setStatusDialogOpen] = useState(false);
    const [statusDialogMember, setStatusDialogMember] =
        useState<AdminTeamMember | null>(null);
    const [statusDialogAction, setStatusDialogAction] = useState<
        'activate' | 'deactivate'
    >('deactivate');
    const [statusSubmitting, setStatusSubmitting] = useState(false);
    const [statusError, setStatusError] = useState<string | null>(null);

    // Password confirm dialog state
    const [passwordConfirmOpen, setPasswordConfirmOpen] = useState(false);
    const pendingAction = useRef<(() => Promise<void>) | null>(null);

    const dateFormatter = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    });

    const openRoleDialog = (
        member: AdminTeamMember,
        targetRole: 'admin' | 'staff',
    ) => {
        setRoleDialogMember(member);
        setRoleDialogTargetRole(targetRole);
        setRoleError(null);
        setRoleDialogOpen(true);
    };

    const openStatusDialog = (
        member: AdminTeamMember,
        action: 'activate' | 'deactivate',
    ) => {
        setStatusDialogMember(member);
        setStatusDialogAction(action);
        setStatusError(null);
        setStatusDialogOpen(true);
    };

    const executeRoleChange = async () => {
        if (!roleDialogMember || !teamUrls) {
            return;
        }

        setRoleSubmitting(true);
        setRoleError(null);

        const url = teamUrls.roleUrlTemplate.replace(
            '__ID__',
            roleDialogMember.id,
        );
        const payload = {
            expected_role: roleDialogMember.role,
            role: roleDialogTargetRole,
        };

        try {
            const response = await fetch(url, {
                body: JSON.stringify(payload),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                method: 'POST',
            });

            if (response.status === 423) {
                setRoleDialogOpen(false);
                pendingAction.current = executeRoleChange;
                setPasswordConfirmOpen(true);

                return;
            }

            if (response.status === 409) {
                setRoleDialogOpen(false);
                setAlertState({
                    text: copy.messages.conflictError,
                    type: 'error',
                });
                router.reload({ only: ['team'] });

                return;
            }

            if (response.status === 403) {
                setRoleError(copy.messages.forbiddenError);

                return;
            }

            if (!response.ok) {
                const data = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                setRoleError(data?.message || copy.messages.genericError);

                return;
            }

            setRoleDialogOpen(false);
            setAlertState({
                text: copy.messages.roleUpdated,
                type: 'success',
            });
            router.reload({ only: ['team'] });
        } catch {
            setRoleError(copy.messages.networkError);
        } finally {
            setRoleSubmitting(false);
        }
    };

    const executeStatusChange = async () => {
        if (!statusDialogMember || !teamUrls) {
            return;
        }

        setStatusSubmitting(true);
        setStatusError(null);

        const url = teamUrls.statusUrlTemplate.replace(
            '__ID__',
            statusDialogMember.id,
        );
        const payload = {
            action: statusDialogAction,
            expected_active: statusDialogMember.isActive,
        };

        try {
            const response = await fetch(url, {
                body: JSON.stringify(payload),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                method: 'POST',
            });

            if (response.status === 423) {
                setStatusDialogOpen(false);
                pendingAction.current = executeStatusChange;
                setPasswordConfirmOpen(true);

                return;
            }

            if (response.status === 409) {
                setStatusDialogOpen(false);
                setAlertState({
                    text: copy.messages.conflictError,
                    type: 'error',
                });
                router.reload({ only: ['team'] });

                return;
            }

            if (response.status === 403) {
                setStatusError(copy.messages.forbiddenError);

                return;
            }

            if (!response.ok) {
                const data = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                setStatusError(data?.message || copy.messages.genericError);

                return;
            }

            setStatusDialogOpen(false);
            setAlertState({
                text: copy.messages.statusUpdated,
                type: 'success',
            });
            router.reload({ only: ['team'] });
        } catch {
            setStatusError(copy.messages.networkError);
        } finally {
            setStatusSubmitting(false);
        }
    };

    return (
        <section
            aria-labelledby="admin-team-title"
            className="rounded-lg border border-border bg-card p-6 shadow-xs"
            id="team"
        >
            <div className="flex flex-col gap-6">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Users
                                aria-hidden="true"
                                className="size-5 text-primary"
                            />
                            <h2
                                className="font-display text-xl font-bold tracking-tight text-foreground"
                                id="admin-team-title"
                            >
                                {copy.teamSection}
                            </h2>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {copy.teamDescription}
                        </p>
                    </div>
                </header>

                {alertState ? (
                    <div
                        className={`flex items-center justify-between gap-4 rounded-md border p-4 text-sm font-medium ${
                            alertState.type === 'success'
                                ? 'border-status-success/30 bg-status-success/10 text-status-success'
                                : 'border-destructive/50 bg-destructive/10 text-destructive'
                        }`}
                        role="alert"
                    >
                        <div className="flex items-center gap-2">
                            {alertState.type === 'success' ? (
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="size-5 shrink-0"
                                />
                            ) : (
                                <AlertCircle
                                    aria-hidden="true"
                                    className="size-5 shrink-0"
                                />
                            )}
                            <span>{alertState.text}</span>
                        </div>
                        <button
                            className="min-h-11 cursor-pointer rounded-md px-3 text-xs font-semibold hover:opacity-80 focus-visible:outline-2 focus-visible:outline-ring"
                            onClick={() => setAlertState(null)}
                            type="button"
                        >
                            {adminUi.common.cancel}
                        </button>
                    </div>
                ) : null}

                {/* Desktop Table View */}
                <div
                    className="hidden overflow-x-auto md:block"
                    data-testid="team-table"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{copy.columns.name}</TableHead>
                                <TableHead>{copy.columns.email}</TableHead>
                                <TableHead>{copy.columns.role}</TableHead>
                                <TableHead>{copy.columns.status}</TableHead>
                                <TableHead>{copy.columns.mfa}</TableHead>
                                <TableHead>{copy.columns.joined}</TableHead>
                                <TableHead className="text-end">
                                    {copy.columns.actions}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => {
                                const isSelf = member.id === team.currentUserId;
                                const currentSelectedRole =
                                    selectedRoles[member.id] ?? member.role;
                                const isRoleModified =
                                    currentSelectedRole !== member.role;

                                return (
                                    <TableRow key={member.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                <span>{member.name}</span>
                                                {isSelf ? (
                                                    <AdminBadge variant="info">
                                                        {copy.selfBadge}
                                                    </AdminBadge>
                                                ) : null}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {member.email}
                                        </TableCell>
                                        <TableCell>
                                            {isSelf || !teamUrls ? (
                                                <AdminBadge
                                                    variant={
                                                        member.role === 'admin'
                                                            ? 'info'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {copy.roles[member.role]}
                                                </AdminBadge>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <select
                                                        aria-label={copy.actions.roleSelectLabel.replace(
                                                            ':name',
                                                            member.name,
                                                        )}
                                                        className="flex min-h-11 rounded-md border border-input bg-transparent px-2.5 py-1 text-xs shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                                                        onChange={(e) =>
                                                            setSelectedRoles(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    [member.id]:
                                                                        e.target
                                                                            .value as
                                                                            | 'admin'
                                                                            | 'staff',
                                                                }),
                                                            )
                                                        }
                                                        value={
                                                            currentSelectedRole
                                                        }
                                                    >
                                                        <option
                                                            className="bg-popover text-popover-foreground"
                                                            value="admin"
                                                        >
                                                            {copy.roles.admin}
                                                        </option>
                                                        <option
                                                            className="bg-popover text-popover-foreground"
                                                            value="staff"
                                                        >
                                                            {copy.roles.staff}
                                                        </option>
                                                    </select>
                                                    <Button
                                                        className="min-h-11 touch-manipulation"
                                                        disabled={
                                                            !isRoleModified
                                                        }
                                                        onClick={() =>
                                                            openRoleDialog(
                                                                member,
                                                                currentSelectedRole,
                                                            )
                                                        }
                                                        size="sm"
                                                        type="button"
                                                        variant="secondary"
                                                    >
                                                        {copy.actions.applyRole}
                                                    </Button>
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <AdminBadge
                                                variant={
                                                    member.isActive
                                                        ? 'success'
                                                        : 'danger'
                                                }
                                            >
                                                {member.isActive
                                                    ? copy.status.active
                                                    : copy.status.inactive}
                                            </AdminBadge>
                                        </TableCell>
                                        <TableCell>
                                            <AdminBadge
                                                variant={
                                                    member.mfaConfirmed
                                                        ? 'success'
                                                        : 'warning'
                                                }
                                            >
                                                {member.mfaConfirmed
                                                    ? copy.mfa.confirmed
                                                    : copy.mfa.pending}
                                            </AdminBadge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground tabular-nums">
                                            {member.createdAt
                                                ? dateFormatter.format(
                                                      new Date(
                                                          member.createdAt,
                                                      ),
                                                  )
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-end">
                                            {isSelf || !teamUrls ? null : (
                                                <Button
                                                    className="min-h-11 touch-manipulation gap-1.5"
                                                    onClick={() =>
                                                        openStatusDialog(
                                                            member,
                                                            member.isActive
                                                                ? 'deactivate'
                                                                : 'activate',
                                                        )
                                                    }
                                                    size="sm"
                                                    type="button"
                                                    variant={
                                                        member.isActive
                                                            ? 'destructive'
                                                            : 'outline'
                                                    }
                                                >
                                                    {member.isActive ? (
                                                        <>
                                                            <UserX
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                            <span>
                                                                {
                                                                    copy.actions
                                                                        .deactivate
                                                                }
                                                            </span>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <UserCheck
                                                                aria-hidden="true"
                                                                className="size-4"
                                                            />
                                                            <span>
                                                                {
                                                                    copy.actions
                                                                        .reactivate
                                                                }
                                                            </span>
                                                        </>
                                                    )}
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                {/* Mobile Cards View */}
                <div
                    className="flex flex-col gap-4 md:hidden"
                    data-testid="team-cards"
                >
                    {members.map((member) => {
                        const isSelf = member.id === team.currentUserId;
                        const currentSelectedRole =
                            selectedRoles[member.id] ?? member.role;
                        const isRoleModified =
                            currentSelectedRole !== member.role;

                        return (
                            <article
                                className="flex flex-col gap-3 rounded-lg border border-border bg-card/50 p-4"
                                key={member.id}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2">
                                            <h3 className="font-semibold text-foreground">
                                                {member.name}
                                            </h3>
                                            {isSelf ? (
                                                <AdminBadge variant="info">
                                                    {copy.selfBadge}
                                                </AdminBadge>
                                            ) : null}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </div>
                                    <AdminBadge
                                        variant={
                                            member.role === 'admin'
                                                ? 'info'
                                                : 'neutral'
                                        }
                                    >
                                        {copy.roles[member.role]}
                                    </AdminBadge>
                                </div>

                                <div className="flex flex-wrap gap-2 pt-1">
                                    <AdminBadge
                                        variant={
                                            member.isActive
                                                ? 'success'
                                                : 'danger'
                                        }
                                    >
                                        {member.isActive
                                            ? copy.status.active
                                            : copy.status.inactive}
                                    </AdminBadge>
                                    <AdminBadge
                                        variant={
                                            member.mfaConfirmed
                                                ? 'success'
                                                : 'warning'
                                        }
                                    >
                                        {member.mfaConfirmed
                                            ? copy.mfa.confirmed
                                            : copy.mfa.pending}
                                    </AdminBadge>
                                    {member.createdAt ? (
                                        <span className="self-center text-xs text-muted-foreground">
                                            {dateFormatter.format(
                                                new Date(member.createdAt),
                                            )}
                                        </span>
                                    ) : null}
                                </div>

                                {isSelf || !teamUrls ? null : (
                                    <div className="flex flex-col gap-2.5 border-t border-border pt-3">
                                        <div className="flex items-center gap-2">
                                            <select
                                                aria-label={copy.actions.roleSelectLabel.replace(
                                                    ':name',
                                                    member.name,
                                                )}
                                                className="flex min-h-11 flex-1 rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                                                onChange={(e) =>
                                                    setSelectedRoles(
                                                        (prev) => ({
                                                            ...prev,
                                                            [member.id]: e
                                                                .target
                                                                .value as
                                                                | 'admin'
                                                                | 'staff',
                                                        }),
                                                    )
                                                }
                                                value={currentSelectedRole}
                                            >
                                                <option
                                                    className="bg-popover text-popover-foreground"
                                                    value="admin"
                                                >
                                                    {copy.roles.admin}
                                                </option>
                                                <option
                                                    className="bg-popover text-popover-foreground"
                                                    value="staff"
                                                >
                                                    {copy.roles.staff}
                                                </option>
                                            </select>
                                            <Button
                                                className="min-h-11 touch-manipulation"
                                                disabled={!isRoleModified}
                                                onClick={() =>
                                                    openRoleDialog(
                                                        member,
                                                        currentSelectedRole,
                                                    )
                                                }
                                                size="sm"
                                                type="button"
                                                variant="secondary"
                                            >
                                                {copy.actions.applyRole}
                                            </Button>
                                        </div>

                                        <Button
                                            className="min-h-11 w-full touch-manipulation gap-1.5"
                                            onClick={() =>
                                                openStatusDialog(
                                                    member,
                                                    member.isActive
                                                        ? 'deactivate'
                                                        : 'activate',
                                                )
                                            }
                                            size="sm"
                                            type="button"
                                            variant={
                                                member.isActive
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                        >
                                            {member.isActive ? (
                                                <>
                                                    <UserX
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                    <span>
                                                        {
                                                            copy.actions
                                                                .deactivate
                                                        }
                                                    </span>
                                                </>
                                            ) : (
                                                <>
                                                    <UserCheck
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                    <span>
                                                        {
                                                            copy.actions
                                                                .reactivate
                                                        }
                                                    </span>
                                                </>
                                            )}
                                        </Button>
                                    </div>
                                )}
                            </article>
                        );
                    })}
                </div>

                <footer className="border-t border-border pt-4">
                    <p className="text-xs text-muted-foreground">
                        {copy.addStaffHint}
                    </p>
                </footer>
            </div>

            {/* Role Change Confirmation Dialog */}
            <Dialog onOpenChange={setRoleDialogOpen} open={roleDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{copy.roleDialog.title}</DialogTitle>
                        <DialogDescription>
                            {roleDialogMember
                                ? copy.roleDialog.description
                                      .replace(':name', roleDialogMember.name)
                                      .replace(
                                          ':from',
                                          copy.roles[roleDialogMember.role],
                                      )
                                      .replace(
                                          ':to',
                                          copy.roles[roleDialogTargetRole],
                                      )
                                : ''}
                        </DialogDescription>
                    </DialogHeader>

                    {roleError ? (
                        <p
                            className="text-xs font-medium text-destructive"
                            role="alert"
                        >
                            {roleError}
                        </p>
                    ) : null}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11"
                                disabled={roleSubmitting}
                                type="button"
                                variant="outline"
                            >
                                {copy.roleDialog.cancel}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 gap-2"
                            disabled={roleSubmitting}
                            onClick={() => void executeRoleChange()}
                            type="button"
                            variant="default"
                        >
                            {roleSubmitting ? (
                                <>
                                    <LoaderCircle
                                        aria-hidden="true"
                                        className="size-4 animate-spin"
                                    />
                                    <span>{copy.actions.applyingRole}</span>
                                </>
                            ) : (
                                <span>{copy.roleDialog.confirm}</span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Status Change (Deactivate / Reactivate) Confirmation Dialog */}
            <Dialog onOpenChange={setStatusDialogOpen} open={statusDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {statusDialogAction === 'deactivate'
                                ? copy.deactivateDialog.title
                                : copy.reactivateDialog.title}
                        </DialogTitle>
                        <DialogDescription>
                            {statusDialogMember
                                ? statusDialogAction === 'deactivate'
                                    ? copy.deactivateDialog.description.replace(
                                          ':name',
                                          statusDialogMember.name,
                                      )
                                    : copy.reactivateDialog.description.replace(
                                          ':name',
                                          statusDialogMember.name,
                                      )
                                : ''}
                        </DialogDescription>
                    </DialogHeader>

                    {statusError ? (
                        <p
                            className="text-xs font-medium text-destructive"
                            role="alert"
                        >
                            {statusError}
                        </p>
                    ) : null}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <DialogClose asChild>
                            <Button
                                className="min-h-11"
                                disabled={statusSubmitting}
                                type="button"
                                variant="outline"
                            >
                                {statusDialogAction === 'deactivate'
                                    ? copy.deactivateDialog.cancel
                                    : copy.reactivateDialog.cancel}
                            </Button>
                        </DialogClose>
                        <Button
                            className="min-h-11 gap-2"
                            disabled={statusSubmitting}
                            onClick={() => void executeStatusChange()}
                            type="button"
                            variant={
                                statusDialogAction === 'deactivate'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            {statusSubmitting ? (
                                <>
                                    <LoaderCircle
                                        aria-hidden="true"
                                        className="size-4 animate-spin"
                                    />
                                    <span>
                                        {statusDialogAction === 'deactivate'
                                            ? copy.actions.deactivating
                                            : copy.actions.reactivating}
                                    </span>
                                </>
                            ) : (
                                <span>
                                    {statusDialogAction === 'deactivate'
                                        ? copy.deactivateDialog.confirm
                                        : copy.reactivateDialog.confirm}
                                </span>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Replay password confirmation dialog */}
            <AdminPasswordConfirmDialog
                confirmPasswordUrl={confirmPasswordUrl}
                description="For security, please enter your password to confirm this team change."
                onConfirmed={() => {
                    if (pendingAction.current) {
                        const action = pendingAction.current;
                        pendingAction.current = null;
                        void action();
                    }
                }}
                onOpenChange={setPasswordConfirmOpen}
                open={passwordConfirmOpen}
                title="Confirm your password"
            />
        </section>
    );
}

import * as Dialog from '@radix-ui/react-dialog';
import { Menu, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    AdminActorIdentity,
    AdminLogoutButton,
    AdminNavigationList,
} from '@/components/admin/admin-sidebar';
import type { AdminNavigationProps } from '@/components/admin/admin-sidebar';

export default function AdminMobileNavigation({
    adminIdentity,
    adminUi,
    current,
    direction,
    logoutUrl,
    navigation,
}: AdminNavigationProps) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const application = document.getElementById('app');

        if (!open || application === null) {
            return;
        }

        application.inert = true;

        return () => {
            application.inert = false;
        };
    }, [open]);

    function changeOpen(nextOpen: boolean) {
        if (!nextOpen) {
            const application = document.getElementById('app');

            if (application !== null) {
                application.inert = false;
            }
        }

        setOpen(nextOpen);
    }

    return (
        <Dialog.Root onOpenChange={changeOpen} open={open}>
            <div className="flex min-h-[calc(3rem+env(safe-area-inset-top))] items-center justify-between gap-4 border-b border-border bg-card px-[max(1rem,env(safe-area-inset-right))] pt-[max(0.5rem,env(safe-area-inset-top))] pl-[max(1rem,env(safe-area-inset-left))] md:hidden">
                <div className="flex items-center gap-3">
                    <img
                        alt=""
                        height="40"
                        src="/images/arabut-logo-header.webp"
                        width="40"
                        className="h-10 w-10 shrink-0 object-contain"
                    />
                    <span
                        className="font-display text-lg font-bold tracking-tight text-foreground"
                        translate="no"
                    >
                        {adminUi.brand}
                    </span>
                </div>
                <Dialog.Trigger asChild>
                    <button
                        aria-label={adminUi.navigation.open}
                        className="inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-md border border-border hover:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                        type="button"
                    >
                        <Menu aria-hidden="true" className="h-5 w-5" />
                    </button>
                </Dialog.Trigger>
            </div>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-40 bg-black/70 opacity-0 transition-opacity duration-200 data-[state=open]:opacity-100 motion-reduce:transition-none motion-reduce:duration-[0.01ms]" />
                <Dialog.Content
                    aria-describedby={undefined}
                    aria-modal="true"
                    className="fixed inset-y-0 start-0 z-50 flex w-[21rem] max-w-[calc(100vw-2.5rem)] transform flex-col gap-6 overflow-y-auto overscroll-contain border-e border-border bg-sidebar p-[max(1rem,env(safe-area-inset-top))] pr-[max(1rem,env(safe-area-inset-right))] pb-[max(1rem,env(safe-area-inset-bottom))] text-sidebar-foreground transition-transform duration-200 data-[state=closed]:-translate-x-full data-[direction=rtl]:data-[state=closed]:translate-x-full data-[state=open]:translate-x-0 motion-reduce:transition-none motion-reduce:duration-[0.01ms]"
                    data-direction={direction}
                >
                    <header className="flex min-h-[44px] items-center justify-between gap-4 border-b border-border pb-4">
                        <Dialog.Title
                            className="font-display text-lg font-bold tracking-tight text-sidebar-foreground"
                            translate="no"
                        >
                            {adminUi.brand}
                        </Dialog.Title>
                        <Dialog.Close asChild>
                            <button
                                aria-label={adminUi.navigation.close}
                                className="inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-md border border-border hover:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                type="button"
                            >
                                <X aria-hidden="true" className="h-5 w-5" />
                            </button>
                        </Dialog.Close>
                    </header>
                    <AdminNavigationList
                        adminUi={adminUi}
                        current={current}
                        navigation={navigation}
                        onNavigate={() => setOpen(false)}
                    />
                    <div className="mt-auto flex flex-col gap-4 border-t border-border pt-4">
                        <AdminActorIdentity
                            direction={direction}
                            identity={adminIdentity}
                        />
                        <AdminLogoutButton
                            label={adminUi.common.logout}
                            logoutUrl={logoutUrl}
                            onLogout={() => setOpen(false)}
                        />
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

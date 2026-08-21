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
            <div className="admin-mobile-bar">
                <div className="admin-mobile-brand">
                    <img
                        alt=""
                        height="40"
                        src="/images/arabut-logo-header.webp"
                        width="40"
                    />
                    <span translate="no">{adminUi.brand}</span>
                </div>
                <Dialog.Trigger asChild>
                    <button
                        aria-label={adminUi.navigation.open}
                        className="admin-mobile-navigation__trigger"
                        type="button"
                    >
                        <Menu aria-hidden="true" />
                    </button>
                </Dialog.Trigger>
            </div>
            <Dialog.Portal>
                <Dialog.Overlay className="admin-mobile-navigation__overlay" />
                <Dialog.Content
                    aria-describedby={undefined}
                    aria-modal="true"
                    className="admin-mobile-navigation__sheet"
                    data-direction={direction}
                >
                    <header className="admin-mobile-navigation__header">
                        <Dialog.Title translate="no">
                            {adminUi.brand}
                        </Dialog.Title>
                        <Dialog.Close asChild>
                            <button
                                aria-label={adminUi.navigation.close}
                                className="admin-mobile-navigation__close"
                                type="button"
                            >
                                <X aria-hidden="true" />
                            </button>
                        </Dialog.Close>
                    </header>
                    <AdminNavigationList
                        adminUi={adminUi}
                        current={current}
                        navigation={navigation}
                        onNavigate={() => setOpen(false)}
                    />
                    <div className="admin-mobile-navigation__session">
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

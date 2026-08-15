import { Head, usePage } from '@inertiajs/react';
import { Mail, MessageCircleMore, ReceiptText } from 'lucide-react';

import AccountSectionError from '@/components/account/account-section-error';
import MyAccountLayout from '@/layouts/my-account-layout';
import type { AccountSupportPageProps } from '@/types/account';

export default function AccountSupport() {
    const inertia = usePage<AccountSupportPageProps>();
    const props = inertia.props;
    const translations = props.accountUi.support;

    return (
        <MyAccountLayout {...props} current="support" currentUrl={inertia.url}>
            <Head title={translations.title} />
            <div className="account-support-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{translations.title}</h2>
                    <span>{translations.description}</span>
                </header>

                {props.support.orderNumber ? (
                    <aside className="account-support-context">
                        <ReceiptText aria-hidden="true" />
                        <span>{translations.order_context}</span>
                        <strong>
                            <bdi>{props.support.orderNumber}</bdi>
                        </strong>
                    </aside>
                ) : null}

                {!props.support.available ? (
                    <AccountSectionError
                        description={translations.unavailable_description}
                        title={translations.unavailable_title}
                    />
                ) : (
                    <div className="account-support-grid">
                        {props.support.whatsappUrl ? (
                            <SupportCard
                                action={translations.whatsapp_action}
                                description={translations.whatsapp_description}
                                href={props.support.whatsappUrl}
                                icon={<MessageCircleMore />}
                                title={translations.whatsapp_title}
                                external
                            />
                        ) : null}
                        {props.support.emailUrl ? (
                            <SupportCard
                                action={translations.email_action}
                                description={translations.email_description}
                                href={props.support.emailUrl}
                                icon={<Mail />}
                                title={translations.email_title}
                            />
                        ) : null}
                    </div>
                )}
            </div>
        </MyAccountLayout>
    );
}

function SupportCard({
    action,
    description,
    external = false,
    href,
    icon,
    title,
}: {
    action: string;
    description: string;
    external?: boolean;
    href: string;
    icon: React.ReactNode;
    title: string;
}) {
    return (
        <article className="account-support-card">
            <span aria-hidden="true">{icon}</span>
            <h3>{title}</h3>
            <p>{description}</p>
            <a
                href={href}
                rel={external ? 'noopener noreferrer' : undefined}
                target={external ? '_blank' : undefined}
            >
                {action}
            </a>
        </article>
    );
}

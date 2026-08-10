import type {
    StoreLocale,
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

type StoreFooterProps = {
    locale: StoreLocale;
    shell: StoreShellConfig;
    translations: StoreShellTranslations;
};

function XIcon() {
    return (
        <svg
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 24 24"
            width="17"
            height="17"
        >
            <path
                d="M18.24 2.25h3.31l-7.23 8.26 8.51 11.24h-6.66l-4.71-6.23-5.4 6.23H2.74l7.73-8.84L1.25 2.25h6.83l4.25 5.62 5.91-5.62Zm-1.16 17.52h1.84L7.08 4.13H5.12l11.96 15.64Z"
                fill="currentColor"
            />
        </svg>
    );
}

function InstagramIcon() {
    return (
        <svg
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 24 24"
            width="17"
            height="17"
        >
            <path
                d="M12 2.16c3.2 0 3.58.02 4.85.07 3.25.15 4.77 1.7 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.64.07-4.85.07-3.2 0-3.58-.01-4.85-.07-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.64-.07-4.85 0-3.2.02-3.58.07-4.85.15-3.23 1.67-4.77 4.92-4.92C8.42 2.18 8.8 2.16 12 2.16ZM12 0C8.74 0 8.33.01 7.05.07 2.7.27.27 2.69.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c4.35-.2 6.78-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95C23.73 2.7 21.31.27 16.95.07 15.67.01 15.26 0 12 0Zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6.41-11.85a1.44 1.44 0 1 0 0 2.89 1.44 1.44 0 0 0 0-2.89Z"
                fill="currentColor"
            />
        </svg>
    );
}

export function StoreFooter({ locale, shell, translations }: StoreFooterProps) {
    const legalLinks = [
        [translations.footer.privacy, shell.privacyUrl],
        [translations.footer.returns, shell.returnsUrl],
        [translations.footer.warranty, shell.warrantyUrl],
        [translations.footer.ea_backup_codes, shell.eaBackupCodesUrl],
        [translations.footer.terms, shell.termsUrl],
    ] as const;
    const year = new Date().getFullYear();

    return (
        <footer className="store-footer" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
            <div className="store-footer__grid">
                <section
                    className="store-footer__brand"
                    aria-labelledby="store-footer-brand"
                >
                    <a
                        className="store-footer__logo-link"
                        href={shell.homeUrl}
                        aria-label={`${translations.brand} — ${translations.header.home}`}
                    >
                        <img
                            src="/images/arabut-logo-header.webp"
                            width="100"
                            height="100"
                            alt=""
                            aria-hidden="true"
                            loading="lazy"
                        />
                    </a>
                    <h2 id="store-footer-brand">{translations.brand}</h2>
                    <p>{translations.footer.description}</p>
                    <div className="store-footer__socials">
                        <a
                            href={shell.socials.x}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="X"
                        >
                            <XIcon />
                        </a>
                        <a
                            href={shell.socials.instagram}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Instagram"
                        >
                            <InstagramIcon />
                        </a>
                    </div>
                </section>

                <nav
                    className="store-footer__links"
                    aria-label={translations.footer.important_links}
                >
                    <h2>{translations.footer.important_links}</h2>
                    <ul>
                        {legalLinks.map(([label, href]) => (
                            <li key={href}>
                                <a href={href}>{label}</a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <section
                    className="store-footer__service"
                    aria-labelledby="store-footer-service"
                >
                    <h2 id="store-footer-service">
                        {translations.footer.customer_service}
                    </h2>
                    <div className="store-footer__contacts">
                        <a
                            href={shell.whatsappUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {translations.footer.whatsapp}
                        </a>
                        <a href={`mailto:${shell.email}`}>{shell.email}</a>
                    </div>
                    <h3>{translations.footer.payment_methods}</h3>
                    <div className="store-footer__payments">
                        {shell.payments.map((payment) => (
                            <span key={payment.name}>
                                <img
                                    src={payment.imageUrl}
                                    alt={payment.name}
                                    width={payment.width}
                                    height={payment.height}
                                    loading="lazy"
                                />
                            </span>
                        ))}
                    </div>
                </section>
            </div>

            <div className="store-footer__bottom">
                <p className="store-footer__legal-line">
                    <span>
                        {translations.footer.copyright.replace(
                            ':year',
                            String(year),
                        )}
                    </span>
                    <span aria-hidden="true"> · </span>
                    <span dir="ltr">{translations.footer.ea_disclaimer}</span>
                    <span aria-hidden="true"> · </span>
                    <a
                        dir="ltr"
                        href="https://www.exchangerate-api.com"
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        {translations.footer.exchange_rate_attribution}
                    </a>
                </p>
            </div>
        </footer>
    );
}

import { Fragment } from 'react';
import type { ReactNode } from 'react';

import type {
    StoreInformationPage,
    StorePageBlock,
    StorePageInlineContent,
} from '@/types/store-shell';

type StoreInformationPageContentProps = {
    homeUrl: string;
    page: StoreInformationPage;
};

function renderInline(content: StorePageInlineContent[]): ReactNode {
    return content.map((part, index) => {
        const key = `${part.text}-${index}`;
        const text = part.strong ? <strong>{part.text}</strong> : part.text;

        if (part.url) {
            // Store-internal links (the tracking opt-out on the privacy
            // page) stay in this tab; external help links open a new one.
            const external = !part.url.startsWith('/');

            return (
                <a
                    href={part.url}
                    key={key}
                    rel={external ? 'noopener noreferrer' : undefined}
                    target={external ? '_blank' : undefined}
                >
                    {text}
                </a>
            );
        }

        return <Fragment key={key}>{text}</Fragment>;
    });
}

function StorePageList({
    block,
}: {
    block: Extract<StorePageBlock, { type: 'list' }>;
}) {
    const List = block.ordered ? 'ol' : 'ul';

    return (
        <List>
            {block.items.map((content, index) => (
                <li key={`${block.type}-${index}`}>{renderInline(content)}</li>
            ))}
        </List>
    );
}

function StorePageNotice({
    block,
}: {
    block: Extract<StorePageBlock, { type: 'notice' }>;
}) {
    return (
        <aside
            className={`store-info-page__notice store-info-page__notice--${block.tone}`}
            role="note"
        >
            <NoticeIcon tone={block.tone} />
            <p>{renderInline(block.content)}</p>
        </aside>
    );
}

function StorePageBlockContent({ block }: { block: StorePageBlock }) {
    if (block.type === 'paragraph') {
        return <p>{renderInline(block.content)}</p>;
    }

    if (block.type === 'list') {
        return <StorePageList block={block} />;
    }

    if (block.type === 'notice') {
        return <StorePageNotice block={block} />;
    }

    if (block.type === 'divider') {
        return <hr />;
    }

    return block.level === 2 ? <h2>{block.text}</h2> : <h3>{block.text}</h3>;
}

function NoticeIcon({ tone }: { tone: 'info' | 'shield' | 'warning' }) {
    const path =
        tone === 'shield'
            ? 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z'
            : 'M10.3 3.9 1.8 18A2 2 0 0 0 3.5 21h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z';

    if (tone === 'info') {
        return <InfoIcon />;
    }

    return (
        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
            <path d={path} stroke="currentColor" strokeWidth="2" />
            {tone === 'warning' ? (
                <path
                    d="M12 9v4m0 4h.01"
                    stroke="currentColor"
                    strokeWidth="2"
                />
            ) : null}
        </svg>
    );
}

function InfoIcon() {
    return (
        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
            <circle
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                strokeWidth="2"
            />
            <path d="M12 8v4m0 4h.01" stroke="currentColor" strokeWidth="2" />
        </svg>
    );
}

function CalendarIcon() {
    return (
        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
            <rect
                height="18"
                rx="2"
                stroke="currentColor"
                strokeWidth="2"
                width="18"
                x="3"
                y="4"
            />
            <path
                d="M16 2v4M8 2v4M3 10h18"
                stroke="currentColor"
                strokeWidth="2"
            />
        </svg>
    );
}

function WhatsAppIcon() {
    return (
        <svg aria-hidden="true" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.79a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.31-1.65a11.88 11.88 0 0 0 5.68 1.45c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.23-6.16-3.48-8.41" />
        </svg>
    );
}

function InformationHero({ homeUrl, page }: StoreInformationPageContentProps) {
    return (
        <section
            aria-labelledby="store-info-page-title"
            className="store-info-page__hero"
        >
            <div aria-hidden="true" className="store-info-page__glow" />
            <div className="store-info-page__container store-info-page__hero-inner">
                <nav
                    aria-label={page.breadcrumb.label}
                    className="store-info-page__breadcrumb"
                >
                    <a href={homeUrl}>{page.breadcrumb.home}</a>
                    <span aria-hidden="true">›</span>
                    <span aria-current="page">{page.breadcrumb.current}</span>
                </nav>
                <h1 id="store-info-page-title">{page.title}</h1>
                {page.subtitle ? <p>{page.subtitle}</p> : null}
                <div className="store-info-page__updated">
                    <CalendarIcon />
                    <span>{page.updated.label}:</span>
                    <time>{page.updated.value}</time>
                </div>
            </div>
        </section>
    );
}

function InformationBody({ blocks }: { blocks: StorePageBlock[] }) {
    return (
        <section
            aria-labelledby="store-info-page-title"
            className="store-info-page__content"
        >
            <div className="store-info-page__container store-info-page__prose">
                {blocks.map((block, index) => (
                    <StorePageBlockContent
                        block={block}
                        key={`${block.type}-${index}`}
                    />
                ))}
            </div>
        </section>
    );
}

function SupportCallout({ support }: Pick<StoreInformationPage, 'support'>) {
    return (
        <aside
            aria-labelledby="store-info-page-support-title"
            className="store-info-page__support"
        >
            <div className="store-info-page__container store-info-page__support-inner">
                <div>
                    <h2 id="store-info-page-support-title">{support.title}</h2>
                    <p>{support.subtitle}</p>
                </div>
                <a href={support.url} rel="noopener noreferrer" target="_blank">
                    <WhatsAppIcon />
                    {support.action}
                </a>
            </div>
        </aside>
    );
}

export default function StoreInformationPageContent({
    homeUrl,
    page,
}: StoreInformationPageContentProps) {
    return (
        <article className="store-info-page">
            <InformationHero homeUrl={homeUrl} page={page} />
            <InformationBody blocks={page.blocks} />
            <SupportCallout support={page.support} />
        </article>
    );
}

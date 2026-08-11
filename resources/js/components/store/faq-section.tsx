import type { FaqEntry, FaqTranslations } from '@/types/store-content';

export function FaqSection({
    entries,
    translations,
}: {
    entries: FaqEntry[];
    translations: FaqTranslations;
}) {
    return (
        <section
            aria-labelledby="store-faq-title"
            className="store-faq"
            id="faq"
        >
            <div className="store-faq__inner">
                <header className="store-section-heading store-faq__heading">
                    <p>{translations.eyebrow}</p>
                    <h2 id="store-faq-title">{translations.title}</h2>
                </header>
                <div className="store-faq__list">
                    {entries.map((entry) => (
                        <details key={entry.question}>
                            <summary>
                                <span>{entry.question}</span>
                                <svg
                                    aria-hidden="true"
                                    className="store-faq__chevron"
                                    fill="none"
                                    height="18"
                                    viewBox="0 0 18 18"
                                    width="18"
                                >
                                    <path
                                        d="m5 7 4 4 4-4"
                                        stroke="currentColor"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="1.75"
                                    />
                                </svg>
                            </summary>
                            <p>{entry.answer}</p>
                        </details>
                    ))}
                </div>
            </div>
        </section>
    );
}

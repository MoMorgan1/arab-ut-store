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
                            <summary>{entry.question}</summary>
                            <p>{entry.answer}</p>
                        </details>
                    ))}
                </div>
            </div>
        </section>
    );
}

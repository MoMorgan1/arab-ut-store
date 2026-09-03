import { Clock, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';

import { formatMinorUnits } from '@/lib/money';
import type {
    ManualServiceCommonTranslations,
    ManualServiceMoney,
} from '@/types/manual-services';

const DOCK_MEDIA = '(max-width: 63.99rem)';

/**
 * How far the options have to scroll under the header before the phone dock
 * appears. The dock is for a visitor who is already choosing, not for the hero.
 */
const DOCK_AFTER_FORM_TOP_PX = 120;

/**
 * The phone dock mirrors the panel's total and button while the real ones are
 * below the fold. It stays away at the top of the page and slides off as soon
 * as the in-flow bar, or anything past it, is on screen. Desktop never shows
 * it: the panel is sticky there.
 */
function useDockVisibility(barRef: RefObject<HTMLDivElement | null>) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const bar = barRef.current;

        if (bar === null || typeof window.matchMedia !== 'function') {
            return;
        }

        const media = window.matchMedia(DOCK_MEDIA);
        const form = bar.closest('form');
        let frame = 0;

        const measure = () => {
            frame = 0;

            if (!media.matches) {
                setVisible(false);

                return;
            }

            const barTop = bar.getBoundingClientRect().top;
            const formTop = form?.getBoundingClientRect().top ?? 0;

            setVisible(
                formTop < DOCK_AFTER_FORM_TOP_PX && barTop > window.innerHeight,
            );
        };

        const schedule = () => {
            if (frame === 0) {
                frame = window.requestAnimationFrame(measure);
            }
        };

        measure();
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        media.addEventListener('change', schedule);

        return () => {
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            media.removeEventListener('change', schedule);

            if (frame !== 0) {
                window.cancelAnimationFrame(frame);
            }
        };
    }, [barRef]);

    return visible;
}

export function ManualServicePanel({
    eta,
    facts,
    image,
    locale,
    price,
    status,
    submitDisabled = false,
    submitLabel,
    title,
    translations,
}: {
    eta: string;
    facts: Array<{ label: string; value: string }>;
    image: { alt: string; url: string };
    locale: 'ar' | 'en';
    price: ManualServiceMoney | null;
    status: 'idle' | 'loading' | 'success' | 'error';
    submitDisabled?: boolean;
    submitLabel?: string;
    title: string;
    translations: ManualServiceCommonTranslations;
}) {
    const barRef = useRef<HTMLDivElement>(null);
    const dockVisible = useDockVisibility(barRef);
    const formattedPrice =
        price === null
            ? '—'
            : formatMinorUnits(price.amountMinor, price.currency, locale);
    const disabled = submitDisabled || status === 'loading';
    const label =
        status === 'loading'
            ? translations.adding
            : (submitLabel ?? translations.add_to_cart);

    return (
        <aside className="manual-service-panel">
            <div className="manual-service-panel__media">
                <img
                    alt={image.alt}
                    height="180"
                    loading="lazy"
                    src={image.url}
                    width="320"
                />
            </div>

            <div className="manual-service-panel__header">
                <span className="manual-service-panel__eyebrow">
                    {translations.panel_title}
                </span>
                <h2 className="manual-service-panel__title">{title}</h2>
            </div>

            <dl className="manual-service-panel__facts">
                {facts.map((fact, index) => (
                    <div
                        className="manual-service-panel__fact"
                        key={`${fact.label}-${index}`}
                    >
                        <dt>{fact.label}</dt>
                        <dd>{fact.value}</dd>
                    </div>
                ))}
            </dl>

            <div className="manual-service-panel__eta">
                <Clock aria-hidden="true" />
                <span>{eta}</span>
            </div>

            <div className="manual-service-panel__bar" ref={barRef}>
                <div className="manual-service-panel__total">
                    <span className="manual-service-panel__total-label">
                        {translations.review_total}
                    </span>
                    <strong
                        aria-live="polite"
                        className="manual-service-panel__total-amount"
                    >
                        {formattedPrice}
                    </strong>
                </div>

                <button
                    className="manual-configurator__submit"
                    disabled={disabled}
                    type="submit"
                >
                    {label}
                </button>

                {status === 'success' ? (
                    <p className="manual-service-panel__status" role="status">
                        {translations.added}
                    </p>
                ) : null}
                {status === 'error' ? (
                    <p className="manual-service-panel__alert" role="alert">
                        {translations.add_error}
                    </p>
                ) : null}
            </div>

            <p className="manual-service-panel__trust">
                <ShieldCheck aria-hidden="true" />
                <span>{translations.review_credentials_ready}</span>
            </p>

            <div
                aria-hidden={dockVisible ? undefined : true}
                className={
                    dockVisible
                        ? 'manual-service-dock is-visible'
                        : 'manual-service-dock'
                }
            >
                <div className="manual-service-dock__total">
                    <span className="manual-service-dock__label">
                        {translations.review_total}
                    </span>
                    <strong className="manual-service-dock__amount">
                        {formattedPrice}
                    </strong>
                </div>
                <button
                    className="manual-configurator__submit manual-service-dock__submit"
                    disabled={disabled}
                    tabIndex={dockVisible ? undefined : -1}
                    type="submit"
                >
                    {label}
                </button>
            </div>
        </aside>
    );
}

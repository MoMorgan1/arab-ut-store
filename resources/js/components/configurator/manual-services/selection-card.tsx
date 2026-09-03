import type { ReactNode } from 'react';

export function SelectionCard({
    badge,
    caption,
    checked,
    children,
    disabled = false,
    iconUrls,
    label,
    name,
    onChange,
    value,
    variant = 'card',
}: {
    badge?: ReactNode;
    caption?: string;
    checked: boolean;
    children: ReactNode;
    disabled?: boolean;
    iconUrls?: string[];
    label?: string;
    name: string;
    onChange: () => void;
    value: string;
    variant?: 'card' | 'segment' | 'platform';
}) {
    if (variant === 'platform') {
        const ariaLabel =
            label ?? (typeof children === 'string' ? children : value);

        return (
            <label className="coins-choice">
                <input
                    aria-label={ariaLabel}
                    checked={checked}
                    className="sr-only"
                    disabled={disabled}
                    name={name}
                    onChange={onChange}
                    type="radio"
                    value={value}
                />
                <span aria-hidden="true" className="coins-choice__mark" />
                {iconUrls !== undefined ? (
                    <span aria-hidden="true" className="coins-choice__icons">
                        {iconUrls.map((iconUrl) => (
                            <img
                                alt=""
                                className="coins-choice__icon"
                                height="42"
                                key={iconUrl}
                                src={iconUrl}
                                width="42"
                            />
                        ))}
                    </span>
                ) : null}
                <span className="coins-choice__body">
                    <strong>{children}</strong>
                    {caption ? <small>{caption}</small> : null}
                </span>
            </label>
        );
    }

    const isSegment = variant === 'segment';

    return (
        <label
            className={
                isSegment ? 'manual-segmented__item' : 'manual-selection-card'
            }
            data-selected={checked}
        >
            <input
                checked={checked}
                disabled={disabled}
                name={name}
                onChange={onChange}
                type="radio"
                value={value}
            />
            <span>{children}</span>
            {badge === undefined ? null : <small>{badge}</small>}
        </label>
    );
}

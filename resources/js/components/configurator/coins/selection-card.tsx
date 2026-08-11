import type { ReactNode } from 'react';

type SelectionCardProps = {
    checked: boolean;
    children: ReactNode;
    iconUrls?: string[];
    label: string;
    name: string;
    onChange: () => void;
    value: string;
};

export function SelectionCard({
    checked,
    children,
    iconUrls,
    label,
    name,
    onChange,
    value,
}: SelectionCardProps) {
    return (
        <label className="coins-choice">
            <input
                aria-label={label}
                checked={checked}
                className="sr-only"
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
            <span className="coins-choice__body">{children}</span>
        </label>
    );
}

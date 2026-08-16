import type { ReactNode } from 'react';

export function SelectionCard({
    badge,
    checked,
    children,
    name,
    onChange,
    value,
}: {
    badge?: ReactNode;
    checked: boolean;
    children: ReactNode;
    name: string;
    onChange: () => void;
    value: string;
}) {
    return (
        <label className="manual-selection-card" data-selected={checked}>
            <input
                checked={checked}
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

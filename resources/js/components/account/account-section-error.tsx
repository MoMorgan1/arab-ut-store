import { AlertTriangle } from 'lucide-react';

export default function AccountSectionError({
    actionLabel,
    description,
    onRetry,
    title,
}: {
    actionLabel?: string;
    description: string;
    onRetry?: () => void;
    title: string;
}) {
    return (
        <section className="account-section-error" role="status">
            <span aria-hidden="true">
                <AlertTriangle />
            </span>
            <h3>{title}</h3>
            <p>{description}</p>
            {actionLabel && onRetry ? (
                <button onClick={onRetry} type="button">
                    {actionLabel}
                </button>
            ) : null}
        </section>
    );
}

export function FieldError({
    error,
    id,
}: {
    error: string | undefined;
    id?: string;
}) {
    if (error === undefined) {
        return null;
    }

    return (
        <p className="coins-field-error" id={id} role="alert">
            <span aria-hidden="true" className="coins-field-error__icon" />
            {error}
        </p>
    );
}

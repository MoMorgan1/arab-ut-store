import { KeyRound, Loader2, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

/**
 * The form behind "update order details".
 *
 * Ported from the tracker's own edit modal (`track/index.php:535`,
 * `assets/js/modals.js`), with two differences the owner decided and the canvas
 * records:
 *
 * - **The email is editable.** The tracker locks it and sends the customer to
 *   WhatsApp. Here they are signed in to their own verified account, and a
 *   mistyped email is the likeliest thing to be wrong, so they fix it
 *   themselves. It works because coins always run before a challenge solve, so
 *   a wrong email surfaces while correcting it still helps.
 * - **Four fields, not nine.** The platform selector, the persona id, the price
 *   limit, the sort mode and the post-update action are ours to decide, not the
 *   customer's. Resuming is its own button on the card.
 *
 * Validation mirrors the server's rule exactly, because a form that accepts
 * what the server refuses is a form that wastes a round trip to say so: a valid
 * email, a password, and three distinct backup codes of six or eight digits.
 */

export type CredentialValues = {
    email: string;
    password: string;
    codes: [string, string, string];
};

export type CredentialStrings = {
    title: string;
    email_label: string;
    password_label: string;
    codes_label: string;
    codes_note: string;
    submit: string;
    cancel: string;
    close: string;
    /** Shown once, above the fields, when anything is wrong. */
    fix_errors: string;
    invalid_email: string;
    password_required: string;
    invalid_code: string;
    duplicate_codes: string;
};

const CODE = /^\d{6}$|^\d{8}$/;

export default function CredentialForm({
    strings,
    busy = false,
    serverError = null,
    onSubmit,
    onClose,
}: {
    strings: CredentialStrings;
    /** The submission is in flight; the supplier call is on the long profile. */
    busy?: boolean;
    /** What the server said, when it refused what the client thought was fine. */
    serverError?: string | null;
    onSubmit: (values: CredentialValues) => void;
    onClose: () => void;
}) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [codes, setCodes] = useState<[string, string, string]>(['', '', '']);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitted, setSubmitted] = useState(false);

    const panel = useRef<HTMLFormElement | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const headingId = useId();
    const summaryId = useId();

    useEffect(() => {
        opener.current = document.activeElement as HTMLElement | null;
        panel.current?.querySelector<HTMLElement>('input')?.focus();

        return () => opener.current?.focus?.();
    }, []);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !busy) {
                onClose();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [busy, onClose]);

    const validate = (): Record<string, string> => {
        const found: Record<string, string> = {};

        // The browser's own email check, not a pattern of our own: every
        // pattern anyone writes for this is wrong about some real address.
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
            found.email = strings.invalid_email;
        }

        if (password === '') {
            found.password = strings.password_required;
        }

        codes.forEach((code, index) => {
            if (!CODE.test(code.trim())) {
                found[`code${index}`] = strings.invalid_code;
            }
        });

        const filled = codes.map((code) => code.trim()).filter((c) => c !== '');

        if (new Set(filled).size !== filled.length) {
            found.codes = strings.duplicate_codes;
        }

        return found;
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setSubmitted(true);

        const found = validate();
        setErrors(found);

        if (Object.keys(found).length > 0) {
            panel.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();

            return;
        }

        onSubmit({
            email: email.trim(),
            password,
            codes: codes.map((code) => code.trim()) as [string, string, string],
        });
    };

    const shown: Record<string, string | undefined> = submitted ? errors : {};
    const codeError = shown.codes ?? shown.code0 ?? shown.code1 ?? shown.code2;

    return (
        <div className="track-modal" role="presentation">
            <form
                aria-describedby={
                    serverError !== null || Object.keys(shown).length > 0
                        ? summaryId
                        : undefined
                }
                aria-labelledby={headingId}
                aria-modal="true"
                className="track-modal__panel"
                noValidate
                onSubmit={submit}
                ref={panel}
                role="dialog"
            >
                <div className="track-modal__head">
                    <h5 className="track-modal__title" id={headingId}>
                        <KeyRound aria-hidden="true" />
                        <span>{strings.title}</span>
                    </h5>
                    <button
                        aria-label={strings.close}
                        className="track-modal__close"
                        disabled={busy}
                        onClick={onClose}
                        type="button"
                    >
                        <X aria-hidden="true" />
                    </button>
                </div>

                {serverError !== null || Object.keys(shown).length > 0 ? (
                    <p
                        className="track-edit__summary"
                        id={summaryId}
                        role="alert"
                    >
                        {serverError ?? strings.fix_errors}
                    </p>
                ) : null}

                <div className="track-edit__group">
                    <label className="track-edit__label" htmlFor="track-email">
                        {strings.email_label}
                    </label>
                    <input
                        aria-invalid={shown.email !== undefined}
                        autoComplete="email"
                        className="track-edit__input"
                        dir="ltr"
                        id="track-email"
                        inputMode="email"
                        onChange={(event) => setEmail(event.target.value)}
                        type="email"
                        value={email}
                    />
                    {shown.email !== undefined ? (
                        <span className="track-edit__error">{shown.email}</span>
                    ) : null}
                </div>

                <div className="track-edit__group">
                    <label className="track-edit__label" htmlFor="track-pass">
                        {strings.password_label}
                    </label>
                    <input
                        aria-invalid={shown.password !== undefined}
                        autoComplete="current-password"
                        className="track-edit__input"
                        dir="ltr"
                        id="track-pass"
                        onChange={(event) => setPassword(event.target.value)}
                        type="password"
                        value={password}
                    />
                    {shown.password !== undefined ? (
                        <span className="track-edit__error">
                            {shown.password}
                        </span>
                    ) : null}
                </div>

                <div className="track-edit__group">
                    <span className="track-edit__label">
                        {strings.codes_label}
                    </span>
                    <div className="track-edit__codes">
                        {codes.map((code, index) => (
                            <input
                                aria-invalid={
                                    shown[`code${index}`] !== undefined
                                }
                                aria-label={`${strings.codes_label} ${index + 1}`}
                                autoComplete="one-time-code"
                                className="track-edit__input"
                                inputMode="numeric"
                                key={index}
                                maxLength={8}
                                onChange={(event) =>
                                    setCodes((current) => {
                                        const next = [...current] as [
                                            string,
                                            string,
                                            string,
                                        ];
                                        next[index] = event.target.value;

                                        return next;
                                    })
                                }
                                type="text"
                                value={code}
                            />
                        ))}
                    </div>
                    <span className="track-edit__note">
                        {strings.codes_note}
                    </span>
                    {/* One line under the row rather than one under each box:
                        the three carry the same sentence, and three copies of it
                        would push the grid apart to say the same thing. */}
                    {codeError !== undefined ? (
                        <span className="track-edit__error">{codeError}</span>
                    ) : null}
                </div>

                <div className="track-edit__actions">
                    <button
                        className="track-btn track-btn--primary"
                        disabled={busy}
                        type="submit"
                    >
                        {busy ? (
                            <Loader2
                                aria-hidden="true"
                                className="track-spin"
                            />
                        ) : null}
                        {strings.submit}
                    </button>
                    <button
                        className="track-btn track-edit__cancel"
                        disabled={busy}
                        onClick={onClose}
                        type="button"
                    >
                        {strings.cancel}
                    </button>
                </div>
            </form>
        </div>
    );
}

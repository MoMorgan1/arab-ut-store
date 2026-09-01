import { ExternalLink, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';

import type {
    ManualCredentialsDraft,
    ManualServiceCommonTranslations,
    ManualServicePlatform,
    PcLauncher,
} from '@/types/manual-services';

import { FieldError } from './field-error';
import type { ManualFormErrors } from './form-utils';

export function CredentialsFields({
    credentials,
    errors = {},
    launcher,
    onBlurField,
    onChange,
    platform,
    registerFieldRef,
    translations,
    tutorials,
}: {
    credentials: ManualCredentialsDraft;
    errors?: ManualFormErrors;
    launcher: PcLauncher | null;
    onBlurField?: (field: string, value: string) => void;
    onChange: (credentials: ManualCredentialsDraft) => void;
    platform: ManualServicePlatform;
    registerFieldRef?: (field: string, node: HTMLInputElement | null) => void;
    translations: ManualServiceCommonTranslations;
    tutorials: { ea: string; playstation: string };
}) {
    const [visible, setVisible] = useState<Record<string, boolean>>({});
    const update = <Key extends keyof ManualCredentialsDraft>(
        key: Key,
        value: ManualCredentialsDraft[Key],
    ) => onChange({ ...credentials, [key]: value });

    return (
        <div className="coins-credentials-form manual-credentials-form">
            <div className="manual-credentials__grid">
                {platform === 'playstation' ? (
                    <>
                        <div className="coins-credential-field">
                            <label htmlFor="manual-playstation-email">
                                {translations.playstation_email}
                            </label>
                            <input
                                aria-describedby={
                                    errors.playstationEmail
                                        ? 'manual-playstation-email-error'
                                        : undefined
                                }
                                aria-invalid={
                                    errors.playstationEmail !== undefined
                                }
                                autoComplete="off"
                                dir="ltr"
                                id="manual-playstation-email"
                                name="playstation-email"
                                onBlur={() =>
                                    onBlurField?.(
                                        'playstationEmail',
                                        credentials.playstationEmail,
                                    )
                                }
                                onChange={(event) =>
                                    update(
                                        'playstationEmail',
                                        event.currentTarget.value,
                                    )
                                }
                                ref={(node) => {
                                    registerFieldRef?.(
                                        'playstationEmail',
                                        node,
                                    );
                                }}
                                required
                                type="email"
                                value={credentials.playstationEmail}
                            />
                            <FieldError
                                error={errors.playstationEmail}
                                id="manual-playstation-email-error"
                            />
                        </div>

                        <div className="coins-credential-field">
                            <label htmlFor="manual-playstation-password">
                                {translations.playstation_password}
                            </label>
                            <div className="coins-password-control">
                                <input
                                    aria-describedby={
                                        errors.playstationPassword
                                            ? 'manual-playstation-password-error'
                                            : undefined
                                    }
                                    aria-invalid={
                                        errors.playstationPassword !== undefined
                                    }
                                    autoComplete="off"
                                    dir="ltr"
                                    id="manual-playstation-password"
                                    name="playstation-password"
                                    onBlur={() =>
                                        onBlurField?.(
                                            'playstationPassword',
                                            credentials.playstationPassword,
                                        )
                                    }
                                    onChange={(event) =>
                                        update(
                                            'playstationPassword',
                                            event.currentTarget.value,
                                        )
                                    }
                                    ref={(node) => {
                                        registerFieldRef?.(
                                            'playstationPassword',
                                            node,
                                        );
                                    }}
                                    required
                                    type={
                                        visible.playstation
                                            ? 'text'
                                            : 'password'
                                    }
                                    value={credentials.playstationPassword}
                                />
                                <button
                                    aria-label={
                                        visible.playstation
                                            ? translations.hide_password
                                            : translations.show_password
                                    }
                                    onClick={() =>
                                        setVisible((current) => ({
                                            ...current,
                                            playstation: !current.playstation,
                                        }))
                                    }
                                    type="button"
                                >
                                    {visible.playstation ? (
                                        <EyeOff aria-hidden="true" />
                                    ) : (
                                        <Eye aria-hidden="true" />
                                    )}
                                </button>
                            </div>
                            <FieldError
                                error={errors.playstationPassword}
                                id="manual-playstation-password-error"
                            />
                        </div>
                    </>
                ) : (
                    <>
                        <div className="coins-credential-field">
                            <label htmlFor="manual-ea-email">
                                {translations.ea_email}
                            </label>
                            <input
                                aria-describedby={
                                    errors.eaEmail
                                        ? 'manual-ea-email-error'
                                        : undefined
                                }
                                aria-invalid={errors.eaEmail !== undefined}
                                autoComplete="off"
                                dir="ltr"
                                id="manual-ea-email"
                                name="ea-email"
                                onBlur={() =>
                                    onBlurField?.(
                                        'eaEmail',
                                        credentials.eaEmail,
                                    )
                                }
                                onChange={(event) =>
                                    update('eaEmail', event.currentTarget.value)
                                }
                                ref={(node) => {
                                    registerFieldRef?.('eaEmail', node);
                                }}
                                required
                                type="email"
                                value={credentials.eaEmail}
                            />
                            <FieldError
                                error={errors.eaEmail}
                                id="manual-ea-email-error"
                            />
                        </div>

                        <div className="coins-credential-field">
                            <label htmlFor="manual-ea-password">
                                {translations.ea_password}
                            </label>
                            <div className="coins-password-control">
                                <input
                                    aria-describedby={
                                        errors.eaPassword
                                            ? 'manual-ea-password-error'
                                            : undefined
                                    }
                                    aria-invalid={
                                        errors.eaPassword !== undefined
                                    }
                                    autoComplete="off"
                                    dir="ltr"
                                    id="manual-ea-password"
                                    name="ea-password"
                                    onBlur={() =>
                                        onBlurField?.(
                                            'eaPassword',
                                            credentials.eaPassword,
                                        )
                                    }
                                    onChange={(event) =>
                                        update(
                                            'eaPassword',
                                            event.currentTarget.value,
                                        )
                                    }
                                    ref={(node) => {
                                        registerFieldRef?.('eaPassword', node);
                                    }}
                                    required
                                    type={visible.ea ? 'text' : 'password'}
                                    value={credentials.eaPassword}
                                />
                                <button
                                    aria-label={
                                        visible.ea
                                            ? translations.hide_password
                                            : translations.show_password
                                    }
                                    onClick={() =>
                                        setVisible((current) => ({
                                            ...current,
                                            ea: !current.ea,
                                        }))
                                    }
                                    type="button"
                                >
                                    {visible.ea ? (
                                        <EyeOff aria-hidden="true" />
                                    ) : (
                                        <Eye aria-hidden="true" />
                                    )}
                                </button>
                            </div>
                            <FieldError
                                error={errors.eaPassword}
                                id="manual-ea-password-error"
                            />
                        </div>
                    </>
                )}
                {platform === 'pc' && launcher === 'steam' ? (
                    <>
                        <div className="coins-credential-field">
                            <label htmlFor="manual-steam-username">
                                {translations.steam_username}
                            </label>
                            <input
                                aria-describedby={
                                    errors.steamUsername
                                        ? 'manual-steam-username-error'
                                        : undefined
                                }
                                aria-invalid={
                                    errors.steamUsername !== undefined
                                }
                                autoComplete="off"
                                dir="ltr"
                                id="manual-steam-username"
                                name="steam-username"
                                onBlur={() =>
                                    onBlurField?.(
                                        'steamUsername',
                                        credentials.steamUsername,
                                    )
                                }
                                onChange={(event) =>
                                    update(
                                        'steamUsername',
                                        event.currentTarget.value,
                                    )
                                }
                                ref={(node) => {
                                    registerFieldRef?.('steamUsername', node);
                                }}
                                required
                                type="text"
                                value={credentials.steamUsername}
                            />
                            <FieldError
                                error={errors.steamUsername}
                                id="manual-steam-username-error"
                            />
                        </div>

                        <div className="coins-credential-field">
                            <label htmlFor="manual-steam-password">
                                {translations.steam_password}
                            </label>
                            <div className="coins-password-control">
                                <input
                                    aria-describedby={
                                        errors.steamPassword
                                            ? 'manual-steam-password-error'
                                            : undefined
                                    }
                                    aria-invalid={
                                        errors.steamPassword !== undefined
                                    }
                                    autoComplete="off"
                                    dir="ltr"
                                    id="manual-steam-password"
                                    name="steam-password"
                                    onBlur={() =>
                                        onBlurField?.(
                                            'steamPassword',
                                            credentials.steamPassword,
                                        )
                                    }
                                    onChange={(event) =>
                                        update(
                                            'steamPassword',
                                            event.currentTarget.value,
                                        )
                                    }
                                    ref={(node) => {
                                        registerFieldRef?.(
                                            'steamPassword',
                                            node,
                                        );
                                    }}
                                    required
                                    type={visible.steam ? 'text' : 'password'}
                                    value={credentials.steamPassword}
                                />
                                <button
                                    aria-label={
                                        visible.steam
                                            ? translations.hide_password
                                            : translations.show_password
                                    }
                                    onClick={() =>
                                        setVisible((current) => ({
                                            ...current,
                                            steam: !current.steam,
                                        }))
                                    }
                                    type="button"
                                >
                                    {visible.steam ? (
                                        <EyeOff aria-hidden="true" />
                                    ) : (
                                        <Eye aria-hidden="true" />
                                    )}
                                </button>
                            </div>
                            <FieldError
                                error={errors.steamPassword}
                                id="manual-steam-password-error"
                            />
                        </div>
                    </>
                ) : null}
            </div>

            <CodeFields
                codes={credentials.eaCodes}
                errors={errors}
                fieldPrefix="eaCode"
                label={translations.ea_codes}
                namePrefix="ea-code"
                numeric
                onBlurField={onBlurField}
                onChange={(codes) => update('eaCodes', codes)}
                registerFieldRef={registerFieldRef}
                translations={translations}
                tutorialHref={tutorials.ea}
                tutorialLabel={translations.ea_tutorial}
            />

            {platform === 'playstation' ? (
                <CodeFields
                    codes={credentials.playstationCodes}
                    errors={errors}
                    fieldPrefix="playstationCode"
                    label={translations.playstation_codes}
                    namePrefix="playstation-code"
                    onBlurField={onBlurField}
                    onChange={(codes) => update('playstationCodes', codes)}
                    registerFieldRef={registerFieldRef}
                    translations={translations}
                    tutorialHref={tutorials.playstation}
                    tutorialLabel={translations.playstation_tutorial}
                />
            ) : null}
        </div>
    );
}

function CodeFields({
    codes,
    errors = {},
    fieldPrefix,
    label,
    namePrefix,
    numeric = false,
    onBlurField,
    onChange,
    registerFieldRef,
    translations,
    tutorialHref,
    tutorialLabel,
}: {
    codes: [string, string, string];
    errors?: ManualFormErrors;
    fieldPrefix: 'eaCode' | 'playstationCode';
    label: string;
    namePrefix: string;
    numeric?: boolean;
    onBlurField?: (field: string, value: string) => void;
    onChange: (codes: [string, string, string]) => void;
    registerFieldRef?: (field: string, node: HTMLInputElement | null) => void;
    translations: ManualServiceCommonTranslations;
    tutorialHref: string;
    tutorialLabel: string;
}) {
    return (
        <fieldset className="coins-backup-codes manual-backup-codes">
            <legend className="sr-only">{label}</legend>
            <div className="manual-backup-codes__header">
                <span aria-hidden="true" className="manual-backup-codes__label">
                    {label}
                </span>
                <a
                    className="manual-backup-codes__tutorial"
                    href={tutorialHref}
                    rel="noopener noreferrer"
                    target="_blank"
                >
                    {tutorialLabel}
                    <ExternalLink aria-hidden="true" />
                </a>
            </div>
            <div className="coins-backup-codes__grid" dir="ltr">
                {codes.map((code, index) => {
                    const fieldKey = `${fieldPrefix}-${index}`;
                    const error = errors[fieldKey];
                    const inputId = `${namePrefix}-${index + 1}`;
                    const errorId = `${inputId}-error`;

                    return (
                        <div
                            className="coins-credential-field coins-backup-code"
                            key={index}
                        >
                            <label className="sr-only" htmlFor={inputId}>
                                {translations.backup_code.replace(
                                    ':number',
                                    String(index + 1),
                                )}
                            </label>
                            <span
                                aria-hidden="true"
                                className="coins-backup-code__number"
                            >
                                {index + 1}
                            </span>
                            <input
                                aria-describedby={error ? errorId : undefined}
                                aria-invalid={error !== undefined}
                                aria-label={translations.backup_code.replace(
                                    ':number',
                                    String(index + 1),
                                )}
                                autoComplete="off"
                                dir="ltr"
                                id={inputId}
                                inputMode={numeric ? 'numeric' : 'text'}
                                maxLength={numeric ? 8 : 6}
                                name={inputId}
                                onBlur={() => onBlurField?.(fieldKey, code)}
                                onChange={(event) => {
                                    const next: [string, string, string] = [
                                        ...codes,
                                    ];
                                    next[index] = numeric
                                        ? event.currentTarget.value
                                              .replace(/[^0-9]/g, '')
                                              .slice(0, 8)
                                        : event.currentTarget.value
                                              .replace(/[^A-Za-z0-9]/g, '')
                                              .toUpperCase()
                                              .slice(0, 6);
                                    onChange(next);
                                }}
                                pattern={
                                    numeric ? '[0-9]{8}' : '[A-Za-z0-9]{6}'
                                }
                                placeholder={numeric ? '12345678' : 'A1B2C3'}
                                ref={(node) => {
                                    registerFieldRef?.(fieldKey, node);
                                }}
                                required
                                value={code}
                            />
                            <FieldError error={error} id={errorId} />
                        </div>
                    );
                })}
            </div>
        </fieldset>
    );
}

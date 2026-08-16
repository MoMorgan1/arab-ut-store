import { Eye, EyeOff, ExternalLink } from 'lucide-react';
import { useState } from 'react';

import type {
    ManualCredentialsDraft,
    ManualServiceCommonTranslations,
    ManualServicePlatform,
    PcLauncher,
} from '@/types/manual-services';

export function CredentialsFields({
    credentials,
    launcher,
    onChange,
    platform,
    translations,
    tutorials,
}: {
    credentials: ManualCredentialsDraft;
    launcher: PcLauncher | null;
    onChange: (credentials: ManualCredentialsDraft) => void;
    platform: ManualServicePlatform;
    translations: ManualServiceCommonTranslations;
    tutorials: { ea: string; playstation: string };
}) {
    const [visible, setVisible] = useState<Record<string, boolean>>({});
    const update = <Key extends keyof ManualCredentialsDraft>(
        key: Key,
        value: ManualCredentialsDraft[Key],
    ) => onChange({ ...credentials, [key]: value });

    return (
        <section
            className="manual-credentials"
            aria-labelledby="manual-credentials-title"
        >
            <header>
                <h2 id="manual-credentials-title">
                    {translations.account_details_title}
                </h2>
                <nav aria-label={translations.tutorials_title}>
                    <a
                        href={tutorials.ea}
                        rel="noopener noreferrer"
                        target="_blank"
                    >
                        {translations.ea_tutorial}
                        <ExternalLink aria-hidden="true" />
                    </a>
                    {platform === 'playstation' ? (
                        <a
                            href={tutorials.playstation}
                            rel="noopener noreferrer"
                            target="_blank"
                        >
                            {translations.playstation_tutorial}
                            <ExternalLink aria-hidden="true" />
                        </a>
                    ) : null}
                </nav>
            </header>
            <div className="manual-credentials__grid">
                {platform === 'playstation' ? (
                    <>
                        <TextField
                            label={translations.playstation_email}
                            onChange={(value) =>
                                update('playstationEmail', value)
                            }
                            type="email"
                            value={credentials.playstationEmail}
                        />
                        <PasswordField
                            label={translations.playstation_password}
                            onChange={(value) =>
                                update('playstationPassword', value)
                            }
                            onToggle={() =>
                                setVisible((current) => ({
                                    ...current,
                                    playstation: !current.playstation,
                                }))
                            }
                            show={visible.playstation === true}
                            translations={translations}
                            value={credentials.playstationPassword}
                        />
                    </>
                ) : (
                    <>
                        <TextField
                            label={translations.ea_email}
                            onChange={(value) => update('eaEmail', value)}
                            type="email"
                            value={credentials.eaEmail}
                        />
                        <PasswordField
                            label={translations.ea_password}
                            onChange={(value) => update('eaPassword', value)}
                            onToggle={() =>
                                setVisible((current) => ({
                                    ...current,
                                    ea: !current.ea,
                                }))
                            }
                            show={visible.ea === true}
                            translations={translations}
                            value={credentials.eaPassword}
                        />
                    </>
                )}
                {platform === 'pc' && launcher === 'steam' ? (
                    <>
                        <TextField
                            label={translations.steam_username}
                            onChange={(value) => update('steamUsername', value)}
                            value={credentials.steamUsername}
                        />
                        <PasswordField
                            label={translations.steam_password}
                            onChange={(value) => update('steamPassword', value)}
                            onToggle={() =>
                                setVisible((current) => ({
                                    ...current,
                                    steam: !current.steam,
                                }))
                            }
                            show={visible.steam === true}
                            translations={translations}
                            value={credentials.steamPassword}
                        />
                    </>
                ) : null}
            </div>
            <CodeFields
                codes={credentials.eaCodes}
                help={translations.ea_codes_help}
                label={translations.ea_codes}
                numeric
                onChange={(codes) => update('eaCodes', codes)}
                translations={translations}
            />
            {platform === 'playstation' ? (
                <CodeFields
                    codes={credentials.playstationCodes}
                    help={translations.playstation_codes_help}
                    label={translations.playstation_codes}
                    onChange={(codes) => update('playstationCodes', codes)}
                    translations={translations}
                />
            ) : null}
        </section>
    );
}

function TextField({
    label,
    onChange,
    type = 'text',
    value,
}: {
    label: string;
    onChange: (value: string) => void;
    type?: 'email' | 'text';
    value: string;
}) {
    return (
        <label>
            <span>{label}</span>
            <input
                autoComplete="off"
                dir="ltr"
                onChange={(event) => onChange(event.currentTarget.value)}
                required
                type={type}
                value={value}
            />
        </label>
    );
}

function PasswordField({
    label,
    onChange,
    onToggle,
    show,
    translations,
    value,
}: {
    label: string;
    onChange: (value: string) => void;
    onToggle: () => void;
    show: boolean;
    translations: ManualServiceCommonTranslations;
    value: string;
}) {
    return (
        <label>
            <span>{label}</span>
            <span className="manual-password-field">
                <input
                    autoComplete="off"
                    dir="ltr"
                    onChange={(event) => onChange(event.currentTarget.value)}
                    required
                    type={show ? 'text' : 'password'}
                    value={value}
                />
                <button
                    aria-label={
                        show
                            ? translations.hide_password
                            : translations.show_password
                    }
                    onClick={onToggle}
                    type="button"
                >
                    {show ? (
                        <EyeOff aria-hidden="true" />
                    ) : (
                        <Eye aria-hidden="true" />
                    )}
                </button>
            </span>
        </label>
    );
}

function CodeFields({
    codes,
    help,
    label,
    numeric = false,
    onChange,
    translations,
}: {
    codes: [string, string, string];
    help: string;
    label: string;
    numeric?: boolean;
    onChange: (codes: [string, string, string]) => void;
    translations: ManualServiceCommonTranslations;
}) {
    return (
        <fieldset className="manual-code-fields">
            <legend>{label}</legend>
            <p>{help}</p>
            <div>
                {codes.map((code, index) => (
                    <label key={index}>
                        <span>
                            {translations.backup_code.replace(
                                ':number',
                                String(index + 1),
                            )}
                        </span>
                        <input
                            autoComplete="off"
                            dir="ltr"
                            inputMode={numeric ? 'numeric' : 'text'}
                            maxLength={numeric ? 8 : 6}
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
                            pattern={numeric ? '[0-9]{8}' : '[A-Za-z0-9]{6}'}
                            required
                            value={code}
                        />
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

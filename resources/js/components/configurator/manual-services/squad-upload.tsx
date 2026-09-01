import { ImagePlus, Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';

import type { ManualServiceCommonTranslations } from '@/types/manual-services';

import { FieldError } from './field-error';

export function SquadUpload({
    error,
    file,
    inputRef,
    onChange,
    translations,
}: {
    error?: string;
    file: File | null;
    inputRef?: (node: HTMLInputElement | null) => void;
    onChange: (file: File | null) => void;
    translations: ManualServiceCommonTranslations;
}) {
    const preview = useMemo(
        () => (file === null ? null : URL.createObjectURL(file)),
        [file],
    );

    useEffect(() => {
        return () => {
            if (preview !== null) {
                URL.revokeObjectURL(preview);
            }
        };
    }, [preview]);

    return (
        <div className="manual-squad-upload">
            <label className="manual-squad-dropzone">
                <input
                    accept="image/jpeg,image/png,image/webp"
                    aria-describedby={error ? 'manual-squad-error' : undefined}
                    aria-invalid={error !== undefined}
                    className="sr-only"
                    name="squad-image"
                    onChange={(event) =>
                        onChange(event.currentTarget.files?.[0] ?? null)
                    }
                    ref={inputRef}
                    type="file"
                />
                <div className="manual-squad-dropzone__icon">
                    <ImagePlus aria-hidden="true" />
                </div>
                <div className="manual-squad-dropzone__text">
                    <strong className="manual-squad-dropzone__title">
                        {translations.squad_image}
                    </strong>
                    <span className="manual-squad-dropzone__help">
                        {translations.squad_image_help}
                    </span>
                </div>
                <span className="manual-squad-dropzone__button">
                    {translations.squad_image_choose}
                </span>
            </label>
            {preview === null ? null : (
                <div className="manual-squad-upload__preview">
                    <img alt={translations.squad_image} src={preview} />
                    <button onClick={() => onChange(null)} type="button">
                        <Trash2 aria-hidden="true" />
                        {translations.squad_image_remove}
                    </button>
                </div>
            )}
            <FieldError error={error} id="manual-squad-error" />
        </div>
    );
}

import { ImagePlus, Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';

import type { ManualServiceCommonTranslations } from '@/types/manual-services';

export function SquadUpload({
    error,
    file,
    onChange,
    translations,
}: {
    error?: string;
    file: File | null;
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
            <label>
                <ImagePlus aria-hidden="true" />
                <span>
                    <strong>{translations.squad_image}</strong>
                    <small>{translations.squad_image_help}</small>
                </span>
                <input
                    accept="image/jpeg,image/png,image/webp"
                    aria-describedby={error ? 'manual-squad-error' : undefined}
                    aria-invalid={error !== undefined}
                    name="squad-image"
                    onChange={(event) =>
                        onChange(event.currentTarget.files?.[0] ?? null)
                    }
                    type="file"
                />
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
            {error === undefined ? null : (
                <p id="manual-squad-error" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

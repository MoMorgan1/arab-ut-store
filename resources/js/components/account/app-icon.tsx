import type { SVGProps } from 'react';

/**
 * The account icon set.
 *
 * One stroke weight (1.7), one cap style and one grid (24px) across the set, so
 * a row of these reads as a family rather than as assorted stock glyphs. The
 * drawer and WhatsApp marks are solid brand shapes and are never stroked.
 *
 * Icons are inlined rather than pulled from an icon package: they are a handful
 * of paths, they must inherit `currentColor` for the gold/neutral states, and
 * the WhatsApp mark has no matching glyph in the outline sets the storefront
 * otherwise uses.
 */
export type AppIconName =
    | 'alert'
    | 'check'
    | 'chevron'
    | 'coin'
    | 'cube'
    | 'ellipsis'
    | 'grid'
    | 'lock'
    | 'logout'
    | 'mail'
    | 'shield'
    | 'user'
    | 'wallet'
    | 'whatsapp';

type AppIconProps = Omit<SVGProps<SVGSVGElement>, 'name'> & {
    name: AppIconName;
};

const shared = {
    'aria-hidden': true,
    focusable: false,
    viewBox: '0 0 24 24',
} as const;

export function AppIcon({ name, ...props }: AppIconProps) {
    const stroked = {
        ...shared,
        fill: 'none',
        stroke: 'currentColor',
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        strokeWidth: 1.7,
    } as const;

    switch (name) {
        case 'whatsapp':
            return (
                <svg {...shared} fill="currentColor" {...props}>
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z" />
                </svg>
            );
        case 'user':
            return (
                <svg {...stroked} {...props}>
                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
            );
        case 'mail':
            return (
                <svg {...stroked} {...props}>
                    <rect x="2.5" y="4.5" width="19" height="15" rx="2.5" />
                    <path d="m3.5 7.5 7.6 5.3a1.6 1.6 0 0 0 1.8 0l7.6-5.3" />
                </svg>
            );
        case 'lock':
            return (
                <svg {...stroked} {...props}>
                    <rect x="3.5" y="10.5" width="17" height="10" rx="2.5" />
                    <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
                    <path d="M12 14.5v2" />
                </svg>
            );
        case 'logout':
            return (
                <svg {...stroked} {...props}>
                    <path d="M9.5 21H5.5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <path d="m16 16.5 4.5-4.5L16 7.5" />
                    <path d="M20.5 12H10" />
                </svg>
            );
        case 'shield':
            return (
                <svg {...stroked} {...props}>
                    <path d="M12 21.5c4-1.6 6.5-4.6 6.5-8.4V6.2L12 3 5.5 6.2v6.9c0 3.8 2.5 6.8 6.5 8.4Z" />
                    <path d="m9 12 2.2 2.2L15.5 10" />
                </svg>
            );
        case 'grid':
            return (
                <svg {...stroked} {...props}>
                    <rect x="3.5" y="3.5" width="7" height="7" rx="1.8" />
                    <rect x="13.5" y="3.5" width="7" height="7" rx="1.8" />
                    <rect x="3.5" y="13.5" width="7" height="7" rx="1.8" />
                    <rect x="13.5" y="13.5" width="7" height="7" rx="1.8" />
                </svg>
            );
        case 'cube':
            return (
                <svg {...stroked} {...props}>
                    <path d="M20.5 8.2v7.6a1.8 1.8 0 0 1-.9 1.6l-6.7 3.8a1.8 1.8 0 0 1-1.8 0l-6.7-3.8a1.8 1.8 0 0 1-.9-1.6V8.2a1.8 1.8 0 0 1 .9-1.6l6.7-3.8a1.8 1.8 0 0 1 1.8 0l6.7 3.8a1.8 1.8 0 0 1 .9 1.6Z" />
                    <path d="m3.8 7.4 8.2 4.6 8.2-4.6M12 21v-9" />
                </svg>
            );
        case 'wallet':
            return (
                <svg {...stroked} {...props}>
                    <path d="M19.5 7.5V6a1.5 1.5 0 0 0-1.5-1.5H5.5A2.5 2.5 0 0 0 3 7v10a2.5 2.5 0 0 0 2.5 2.5H18a1.5 1.5 0 0 0 1.5-1.5v-1.5" />
                    <path d="M21 9.5v5h-3.5a2.5 2.5 0 0 1 0-5H21Z" />
                </svg>
            );
        case 'coin':
            return (
                <svg {...stroked} {...props}>
                    <circle cx="12" cy="12" r="8.5" />
                    <path d="M12 7.5v9" />
                    <path d="M14.8 9.6a3 3 0 0 0-2.8-1.1c-1.4 0-2.5.8-2.5 1.9 0 2.6 5.6 1.2 5.6 3.9 0 1.2-1.2 2-2.7 2a3.2 3.2 0 0 1-2.9-1.2" />
                </svg>
            );
        case 'check':
            return (
                <svg {...stroked} strokeWidth={2.6} {...props}>
                    <path d="m5 12.5 4.5 4.5L19 6.5" />
                </svg>
            );
        case 'alert':
            return (
                <svg {...stroked} strokeWidth={1.9} {...props}>
                    <circle cx="12" cy="12" r="8.75" />
                    <path d="M12 8v4.5" />
                    <path d="M12 16h.01" />
                </svg>
            );
        case 'chevron':
            return (
                <svg {...stroked} {...props}>
                    <path d="m9.5 6 6 6-6 6" />
                </svg>
            );
        case 'ellipsis':
            return (
                <svg {...stroked} strokeWidth={2.4} {...props}>
                    <path d="M6 12h.01" />
                    <path d="M12 12h.01" />
                    <path d="M18 12h.01" />
                </svg>
            );
    }
}

export default AppIcon;

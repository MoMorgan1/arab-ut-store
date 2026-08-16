import { Head } from '@inertiajs/react';
import type { StoreLocale } from '@/types/store-shell';

type StoreSeoHeadProps = {
    title: string;
    description?: string | null;
    locale?: StoreLocale;
    canonicalUrl?: string;
    imageUrl?: string;
    schemaType?: 'OnlineStore' | 'Product' | 'WebPage';
};

const DEFAULT_DESCRIPTIONS: Record<StoreLocale, string> = {
    ar: 'متجر عرب التيميت، فريق متخصص في خدمات FC 27. نوصل لك الكوينز بأمان وضمان كامل وبأسعار منافسة — موثّق برقم العمل الحر FL-621205220.',
    en: 'Arab UT Store specializes in FC 27 services, delivering coins safely with a full guarantee and competitive prices — Officially verified with freelance doc FL-621205220.',
};

export function StoreSeoHead({
    title,
    description,
    locale = 'ar',
    canonicalUrl,
    imageUrl = 'https://store.arab-ut.com/images/arabut-logo-header.webp',
    schemaType = 'OnlineStore',
}: StoreSeoHeadProps) {
    const metaDescription = description || DEFAULT_DESCRIPTIONS[locale];
    const brandName = locale === 'ar' ? 'متجر عرب التيميت' : 'Arab UT Store';
    const formattedTitle = title.includes(brandName)
        ? title
        : `${title} | ${brandName}`;

    const jsonLd = {
        '@context': 'https://schema.org',
        '@type': schemaType,
        name: brandName,
        url: canonicalUrl || 'https://store.arab-ut.com',
        logo: imageUrl,
        description: metaDescription,
        email: 'info@arab-ut.com',
        telephone: '+966537998099',
        identifier: 'FL-621205220',
        sameAs: [
            'https://x.com/fut_fi',
            'https://www.instagram.com/arabutcoins/',
        ],
    };

    return (
        <Head title={title}>
            <meta name="description" content={metaDescription} />
            <meta property="og:title" content={formattedTitle} />
            <meta property="og:description" content={metaDescription} />
            <meta property="og:type" content="website" />
            <meta property="og:site_name" content={brandName} />
            <meta
                property="og:locale"
                content={locale === 'ar' ? 'ar_SA' : 'en_US'}
            />
            <meta property="og:image" content={imageUrl} />
            <meta name="twitter:card" content="summary_large_image" />
            <meta name="twitter:title" content={formattedTitle} />
            <meta name="twitter:description" content={metaDescription} />
            <meta name="twitter:image" content={imageUrl} />
            {canonicalUrl ? <link rel="canonical" href={canonicalUrl} /> : null}
            <script type="application/ld+json">{JSON.stringify(jsonLd)}</script>
        </Head>
    );
}

export default StoreSeoHead;

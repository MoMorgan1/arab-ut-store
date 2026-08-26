import { Head } from '@inertiajs/react';

type StoreSeoHeadProps = {
    title: string;
};

/**
 * Sets the browser tab title during client-side navigation.
 *
 * Description, Open Graph, Twitter Card, JSON-LD, canonical, and hreflang are
 * deliberately NOT rendered here. Crawlers and social scrapers only ever read
 * the initial server response — they never perform client-side navigation — so
 * that metadata lives in `app.blade.php` where it reaches them. Emitting it
 * from both places would duplicate every tag on first paint.
 */
export function StoreSeoHead({ title }: StoreSeoHeadProps) {
    return <Head title={title} />;
}

export default StoreSeoHead;

import { Head } from '@inertiajs/react';
import React from 'react';

import AdminPagesHeader from '@/components/admin/pages/admin-pages-header';
import AdminPagesTable from '@/components/admin/pages/admin-pages-table';
import type { AdminStorePagesPageProps } from '@/types/admin';

export default function AdminStorePagesPage(props: AdminStorePagesPageProps) {
    const copy = props.adminUi.pages ?? {
        actions: 'Actions',
        addBlock: 'Add block',
        address: 'Address',
        blockContentPlaceholder: 'Write block content here…',
        blockCount: 'Blocks',
        blockType: 'Block type',
        blocksTitle: 'Blocks',
        description:
            'Manage policy pages, terms, and backup code guide content in both languages.',
        edit: 'Edit',
        headingLevel: 'Level',
        headingLevel2: 'Heading 2 (H2)',
        headingLevel3: 'Heading 3 (H3)',
        headingTextPlaceholder: 'Write heading text…',
        headTitle: 'Policy pages',
        helperText:
            'Use **bold** for emphasis and [label](url) for links. Write \\* or \\[ for literal characters.',
        listContentPlaceholder: 'Write one item per line…',
        listOrdered: 'Numbered list',
        moveDown: 'Move down',
        moveUp: 'Move up',
        noticeTone: 'Tone',
        noticeToneInfo: 'Info',
        noticeToneShield: 'Shield',
        noticeToneWarning: 'Warning',
        openInStore: 'Open on the store',
        page: 'Page',
        removeBlock: 'Remove block',
        savedSuccess: 'Page saved successfully.',
        saveError:
            'Unable to save page. Please review the form and resolve any errors.',
        savePage: 'Save page',
        saving: 'Saving…',
        subtitleLabel: 'Subtitle (optional)',
        tabArabic: 'العربية',
        tabEnglish: 'English',
        tableLabel: 'Policy pages list',
        title: 'Policy pages',
        titleLabel: 'Page title',
        typeDivider: 'Divider',
        typeHeading: 'Heading',
        typeList: 'List',
        typeNotice: 'Notice',
        typeParagraph: 'Paragraph',
        unsavedChangesWarning:
            'You have unsaved changes. Are you sure you want to leave?',
        updatedLabel: 'Displayed update date',
        updatedLabelLabel: 'Last updated text (shown on the page)',
    };

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head title={copy.headTitle} />

            <AdminPagesHeader copy={copy} />

            <AdminPagesTable copy={copy} pages={props.pages} />
        </article>
    );
}

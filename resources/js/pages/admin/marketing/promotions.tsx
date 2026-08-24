'use no memo'; // TanStack Table's mutable instances are not React Compiler compatible.

import { Head, router, usePage } from '@inertiajs/react';
import {
    Flame,
    Layers,
    Percent,
    Plus,
    Tag,
    Trash2,
    X,
    Zap,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import AdminMobileTabBar from '@/components/admin/admin-mobile-tabbar';
import {
    formatHalalahToSar,
    parseSarToHalalah,
} from '@/components/admin/admin-money';
import AdminSidebar from '@/components/admin/admin-sidebar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    AdminPromotionProductOption,
    AdminPromotionRow,
    AdminPromotionsPageProps,
    AdminPromotionsQueryState,
} from '@/types/admin';

type PromotionFormMechanic = 'percent' | 'fixed' | 'nth_item' | 'bundle';

type PromotionComponentItem = {
    product_id: string;
    quantity: number;
};

type PromotionFormData = {
    mechanic_type: PromotionFormMechanic;
    name_ar: string;
    name_en: string;
    badge_ar: string;
    badge_en: string;
    scope: 'all' | 'category' | 'service';
    category: string;
    service_type: string;
    discount_type: 'percent' | 'fixed';
    value: string;
    buy_quantity: string;
    get_quantity: string;
    max_applications: string;
    discount_target: 'cheapest' | 'most_expensive';
    qualifying_scope: 'same_product' | 'same_category' | 'same_service' | 'any';
    bundle_price: string;
    components: PromotionComponentItem[];
    applies_to_promoted_items: boolean;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
};

const serviceTypes = [
    'coins',
    'sbc',
    'objectives',
    'rivals',
    'fut_champions',
] as const;

const emptyForm: PromotionFormData = {
    mechanic_type: 'percent',
    name_ar: '',
    name_en: '',
    badge_ar: '',
    badge_en: '',
    scope: 'all',
    category: '',
    service_type: '',
    discount_type: 'percent',
    value: '',
    buy_quantity: '1',
    get_quantity: '1',
    max_applications: '',
    discount_target: 'cheapest',
    qualifying_scope: 'any',
    bundle_price: '',
    components: [
        { product_id: '', quantity: 1 },
        { product_id: '', quantity: 1 },
    ],
    applies_to_promoted_items: false,
    starts_at: '',
    ends_at: '',
    is_active: true,
};

function promotionToForm(promotion: AdminPromotionRow): PromotionFormData {
    let mechanicType: PromotionFormMechanic = 'percent';

    if (promotion.mechanic === 'bundle') {
        mechanicType = 'bundle';
    } else if (promotion.mechanic === 'nth_item') {
        mechanicType = 'nth_item';
    } else if (promotion.discountType === 'fixed') {
        mechanicType = 'fixed';
    } else {
        mechanicType = 'percent';
    }

    const comps: PromotionComponentItem[] =
        promotion.components && promotion.components.length > 0
            ? promotion.components.map((c) => ({
                  product_id: c.productId,
                  quantity: c.quantity,
              }))
            : [
                  { product_id: '', quantity: 1 },
                  { product_id: '', quantity: 1 },
              ];

    return {
        mechanic_type: mechanicType,
        name_ar: promotion.nameAr,
        name_en: promotion.nameEn,
        badge_ar: promotion.badgeAr ?? '',
        badge_en: promotion.badgeEn ?? '',
        scope: promotion.scope,
        category:
            promotion.scope === 'category' ? (promotion.categoryId ?? '') : '',
        service_type: promotion.serviceType ?? '',
        discount_type: promotion.discountType,
        value:
            promotion.discountType === 'fixed'
                ? formatHalalahToSar(promotion.value)
                : String(promotion.value),
        buy_quantity:
            promotion.buyQuantity !== null
                ? String(promotion.buyQuantity)
                : '1',
        get_quantity:
            promotion.getQuantity !== null
                ? String(promotion.getQuantity)
                : '1',
        max_applications:
            promotion.maxApplications !== null
                ? String(promotion.maxApplications)
                : '',
        discount_target:
            promotion.discountTarget === 'most_expensive'
                ? 'most_expensive'
                : 'cheapest',
        qualifying_scope: promotion.qualifyingScope ?? 'any',
        bundle_price:
            promotion.bundlePriceHalalah !== null
                ? formatHalalahToSar(promotion.bundlePriceHalalah)
                : '',
        components: comps,
        applies_to_promoted_items: promotion.appliesToPromotedItems,
        starts_at: promotion.startsAt ? promotion.startsAt.slice(0, 10) : '',
        ends_at: promotion.endsAt ? promotion.endsAt.slice(0, 10) : '',
        is_active: promotion.isActive,
    };
}

export default function AdminPromotionsPage() {
    const { props, url } = usePage<AdminPromotionsPageProps>();
    const copy = props.adminUi.promotions;
    const pathname = new URL(url, window.location.origin).pathname;

    const [drawerMode, setDrawerMode] = useState<'create' | 'edit' | null>(
        null,
    );
    const [editingPromotion, setEditingPromotion] =
        useState<AdminPromotionRow | null>(null);
    const [pageMessage, setPageMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    const [togglePromotion, setTogglePromotion] =
        useState<AdminPromotionRow | null>(null);
    const [toggleTargetActive, setToggleTargetActive] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [toggleMessage, setToggleMessage] = useState<{
        type: 'success' | 'error';
        text: string;
    } | null>(null);

    const [search, setSearch] = useState(props.filters.search ?? '');

    const visitPromotions = useCallback(
        (filters: Partial<AdminPromotionsQueryState>) => {
            const merged = { ...props.filters, ...filters };
            const cleanParams: Record<string, string | number> = {};

            for (const [k, v] of Object.entries(merged)) {
                if (v !== null && v !== undefined && v !== '') {
                    cleanParams[k] = v;
                }
            }

            router.get(pathname, cleanParams, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        },
        [pathname, props.filters],
    );

    function openCreate() {
        setPageMessage(null);
        setEditingPromotion(null);
        setDrawerMode('create');
    }

    function openEdit(promotion: AdminPromotionRow) {
        setPageMessage(null);
        setEditingPromotion(promotion);
        setDrawerMode('edit');
    }

    function closeDrawer() {
        setDrawerMode(null);
    }

    function requestToggle(
        promotion: AdminPromotionRow,
        targetActive: boolean,
    ) {
        setTogglePromotion(promotion);
        setToggleTargetActive(targetActive);
        setToggleMessage(null);
    }

    async function confirmToggle() {
        if (!togglePromotion) {
            return;
        }

        setToggling(true);
        setToggleMessage(null);

        // The presenter always supplies this via route(), so there is no
        // fallback: a literal '/admin/...' would post to the wrong locale
        // family for anyone working under /en/admin.
        const statusUrl = props.statusUrlTemplate.replace(
            '__ID__',
            togglePromotion.id,
        );

        try {
            const res = await fetch(statusUrl, {
                body: JSON.stringify({ is_active: toggleTargetActive }),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                method: 'POST',
            });

            if (!res.ok) {
                setToggleMessage({
                    type: 'error',
                    text: copy.messages.genericError,
                });
            } else {
                setTogglePromotion(null);
                setPageMessage({
                    type: 'success',
                    text: copy.messages.toggled,
                });
                router.reload({
                    only: ['promotions', 'pagination', 'counts'],
                });
            }
        } catch {
            setToggleMessage({
                type: 'error',
                text: copy.messages.networkError,
            });
        } finally {
            setToggling(false);
        }
    }

    const currentStatus = props.filters.status ?? 'all';

    const statusTabs = [
        {
            key: 'all' as const,
            label: copy.statusTabs.all,
            count: props.counts.total,
        },
        {
            key: 'active' as const,
            label: copy.statusTabs.active,
            count: props.counts.active,
        },
        {
            key: 'scheduled' as const,
            label: copy.statusTabs.scheduled,
            count: props.counts.scheduled ?? 0,
        },
        {
            key: 'paused' as const,
            label: copy.statusTabs.paused,
            count: props.counts.paused ?? 0,
        },
        {
            key: 'ended' as const,
            label: copy.statusTabs.ended,
            count: props.counts.ended ?? 0,
        },
    ];

    const handleSearchSubmit = (e: FormEvent) => {
        e.preventDefault();
        visitPromotions({ search: search.trim() || null, page: 1 });
    };

    const clearSearch = () => {
        setSearch('');
        visitPromotions({ search: null, page: 1 });
    };

    return (
        <div className="admin-document-layout" dir="ltr">
            <Head title={copy.headTitle} />
            <AdminSidebar
                adminIdentity={props.adminIdentity}
                adminUi={props.adminUi}
                current="marketingPromotions"
                direction={props.direction}
                logoutUrl={props.logoutUrl}
                navigation={props.adminNavigation}
            />
            <main className="admin-main">
                <article className="space-y-6" dir={props.direction}>
                    <header className="flex flex-col gap-4 border-b border-border pb-5 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex flex-col gap-1">
                            <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                                {copy.title}
                            </h1>
                            <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                                {copy.description}
                            </p>
                        </div>
                        {props.permissions.includes('marketing.manage') ? (
                            <Button
                                className="min-h-11 shrink-0 px-4 text-sm font-semibold"
                                onClick={openCreate}
                                type="button"
                            >
                                <Plus
                                    aria-hidden="true"
                                    className="me-2 size-4"
                                />
                                <span>{copy.createButton}</span>
                            </Button>
                        ) : null}
                    </header>

                    {pageMessage ? (
                        <Alert
                            variant={
                                pageMessage.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            <AlertTitle>{pageMessage.text}</AlertTitle>
                        </Alert>
                    ) : null}

                    {/* Status Tabs */}
                    <div className="flex items-center gap-1.5 overflow-x-auto border-b border-border pb-2">
                        {statusTabs.map((tab) => {
                            const isSelected = currentStatus === tab.key;

                            return (
                                <button
                                    key={tab.key}
                                    className={`inline-flex min-h-11 min-w-11 items-center gap-2 rounded-lg px-3.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none ${
                                        isSelected
                                            ? 'border border-primary/30 bg-primary/15 text-primary'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                    }`}
                                    onClick={() =>
                                        visitPromotions({
                                            status:
                                                tab.key === 'all'
                                                    ? null
                                                    : tab.key,
                                            page: 1,
                                        })
                                    }
                                    type="button"
                                >
                                    <span>{tab.label}</span>
                                    <span
                                        className={`inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ${
                                            isSelected
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground'
                                        }`}
                                    >
                                        {tab.count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {/* Search Toolbar */}
                    <div className="flex flex-col gap-2 min-[480px]:flex-row min-[480px]:items-center">
                        <form
                            className="flex min-w-[240px] flex-1 items-center gap-2"
                            onSubmit={handleSearchSubmit}
                            role="search"
                        >
                            <div className="relative min-w-0 flex-1">
                                <Input
                                    aria-label={copy.columns.name}
                                    className="min-h-11 pe-10 text-sm"
                                    maxLength={100}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder={copy.nameEnPlaceholder}
                                    type="search"
                                    value={search}
                                />
                                {search ? (
                                    <button
                                        aria-label={props.adminUi.common.cancel}
                                        className="absolute end-0 top-0 inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                                        onClick={clearSearch}
                                        type="button"
                                    >
                                        <X
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </button>
                                ) : null}
                            </div>
                            <Button
                                className="min-h-11 shrink-0 px-4 text-sm"
                                type="submit"
                                variant="secondary"
                            >
                                {props.adminUi.orders.searchButton}
                            </Button>
                        </form>
                    </div>

                    <PromotionsTable
                        copy={copy}
                        onEdit={openEdit}
                        onToggle={requestToggle}
                        permissions={props.permissions}
                        promotions={props.promotions}
                    />

                    <PromotionsPagination
                        copy={props.adminUi.orders}
                        onPageChange={(page) => visitPromotions({ page })}
                        pagination={props.pagination}
                    />
                </article>
            </main>
            <AdminMobileTabBar
                adminUi={props.adminUi}
                current="marketingPromotions"
                navigation={props.adminNavigation}
            />

            {/* Create / Edit Drawer */}
            <Sheet
                open={drawerMode !== null}
                onOpenChange={(open) => !open && closeDrawer()}
            >
                {drawerMode !== null ? (
                    <PromotionDrawer
                        key={
                            editingPromotion
                                ? editingPromotion.id
                                : 'create_promotion'
                        }
                        categories={props.categories}
                        copy={copy}
                        createUrl={props.createUrl}
                        editingPromotion={editingPromotion}
                        mode={drawerMode}
                        onClose={closeDrawer}
                        onSaved={() => {
                            closeDrawer();
                            setPageMessage({
                                type: 'success',
                                text:
                                    drawerMode === 'edit'
                                        ? copy.messages.updated
                                        : copy.messages.created,
                            });
                            router.reload({
                                only: ['promotions', 'pagination', 'counts'],
                            });
                        }}
                        products={props.products}
                        updateUrlTemplate={props.updateUrlTemplate}
                    />
                ) : null}
            </Sheet>

            {/* Toggle Confirmation Dialog */}
            {togglePromotion ? (
                <Dialog
                    open={togglePromotion !== null}
                    onOpenChange={(open) => !open && setTogglePromotion(null)}
                >
                    <DialogContent dir="ltr">
                        <DialogHeader>
                            <DialogTitle>
                                {toggleTargetActive
                                    ? copy.activateTitle
                                    : copy.deactivateTitle}
                            </DialogTitle>
                            <DialogDescription>
                                {(toggleTargetActive
                                    ? copy.activateDescription
                                    : copy.deactivateDescription
                                ).replace(':name', togglePromotion.nameEn)}
                            </DialogDescription>
                        </DialogHeader>
                        {toggleMessage ? (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {toggleMessage.text}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                        <DialogFooter className="gap-2">
                            <Button
                                className="min-h-11 px-4 text-sm"
                                disabled={toggling}
                                onClick={() => setTogglePromotion(null)}
                                type="button"
                                variant="outline"
                            >
                                {copy.cancelButton}
                            </Button>
                            <Button
                                className="min-h-11 px-4 text-sm font-semibold"
                                disabled={toggling}
                                onClick={confirmToggle}
                                type="button"
                            >
                                {copy.confirmToggle}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </div>
    );
}

function getRemainingCountdown(endsAt: string | null): string | null {
    if (!endsAt) {
        return null;
    }

    const endMs = new Date(endsAt).getTime();
    const nowMs = Date.now();
    const diff = endMs - nowMs;

    if (diff > 0 && diff <= 48 * 3600 * 1000) {
        const hours = Math.floor(diff / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);

        if (hours === 0 && minutes === 0) {
            return '< 1m';
        }

        if (hours === 0) {
            return `${minutes}m`;
        }

        return `${hours}h ${minutes}m`;
    }

    return null;
}

function scopeLabel(
    promotion: AdminPromotionRow,
    copy: AdminPromotionsPageProps['adminUi']['promotions'],
): string {
    if (promotion.scope === 'category') {
        return copy.scopeCategoryBadge.replace(
            ':category',
            promotion.categoryName ?? '—',
        );
    }

    if (promotion.scope === 'service') {
        return copy.scopeServiceBadge.replace(
            ':service',
            promotion.serviceType ?? '—',
        );
    }

    return copy.scopeAllBadge;
}

function mechanicChipLabel(
    mechanic: string,
    discountType: string,
    copy: AdminPromotionsPageProps['adminUi']['promotions'],
): string {
    if (mechanic === 'bundle') {
        return copy.chips.bundle;
    }

    if (mechanic === 'nth_item') {
        return copy.chips.nth_item;
    }

    if (discountType === 'fixed') {
        return copy.chips.fixed;
    }

    return copy.chips.percent;
}

function formatTermsLine(
    promotion: AdminPromotionRow,
    copy: AdminPromotionsPageProps['adminUi']['promotions'],
): string {
    if (promotion.mechanic === 'bundle') {
        const count = promotion.components ? promotion.components.length : 0;
        const priceSar = formatHalalahToSar(promotion.bundlePriceHalalah ?? 0);
        const partsCountText = `${count}`;

        return copy.terms.bundleSummary
            .replace(':count', partsCountText)
            .replace(':price', priceSar)
            .replace(':parts', priceSar);
    }

    if (promotion.mechanic === 'nth_item') {
        const buy = String(promotion.buyQuantity ?? 1);
        const discountStr =
            promotion.discountType === 'percent'
                ? `${promotion.value}% off`
                : `${formatHalalahToSar(promotion.value)} SAR off`;
        const targetStr =
            promotion.discountTarget === 'most_expensive'
                ? copy.discountTargetMostExpensive.toLowerCase()
                : copy.discountTargetCheapest.toLowerCase();

        let scopeQualifier = '';

        if (promotion.qualifyingScope === 'same_product') {
            scopeQualifier = copy.qualifyingScopes.same_product.toLowerCase();
        } else if (promotion.qualifyingScope === 'same_category') {
            scopeQualifier = promotion.categoryName
                ? `${promotion.categoryName} SBC line`
                : 'SBC line';
        } else if (promotion.qualifyingScope === 'same_service') {
            scopeQualifier = promotion.serviceType
                ? `${promotion.serviceType} line`
                : 'category line';
        } else {
            scopeQualifier =
                promotion.scope === 'category' && promotion.categoryName
                    ? `${promotion.categoryName} line`
                    : promotion.scope === 'service' && promotion.serviceType
                      ? `${promotion.serviceType} line`
                      : 'line';
        }

        if (promotion.maxApplications && promotion.maxApplications > 0) {
            return copy.terms.nthItem
                .replace(':buy', buy)
                .replace(':discount', discountStr)
                .replace(':target', targetStr)
                .replace(':scope', scopeQualifier)
                .replace(':max', String(promotion.maxApplications));
        }

        return copy.terms.nthItemUnlimited
            .replace(':buy', buy)
            .replace(':discount', discountStr)
            .replace(':target', targetStr)
            .replace(':scope', scopeQualifier);
    }

    if (promotion.discountType === 'fixed') {
        return copy.terms.fixed.replace(
            ':value',
            formatHalalahToSar(promotion.value),
        );
    }

    return copy.terms.percent.replace(':value', String(promotion.value));
}

function PromotionsTable({
    copy,
    promotions,
    onEdit,
    onToggle,
    permissions,
}: {
    copy: AdminPromotionsPageProps['adminUi']['promotions'];
    promotions: AdminPromotionRow[];
    onEdit: (promotion: AdminPromotionRow) => void;
    onToggle: (promotion: AdminPromotionRow, targetActive: boolean) => void;
    permissions: string[];
}) {
    const canManage = permissions.includes('marketing.manage');

    if (promotions.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                {copy.noPromotions}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-md border border-border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.name}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.mechanic}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.terms}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.scope}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.discount}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.window}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.status}
                        </th>
                        <th className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {copy.columns.actions}
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {promotions.map((promotion) => {
                        const countdown =
                            promotion.isActive && promotion.endsAt
                                ? getRemainingCountdown(promotion.endsAt)
                                : null;

                        return (
                            <tr
                                className="hover:bg-muted/30"
                                key={promotion.id}
                            >
                                <td className="px-4 py-3">
                                    <span className="font-semibold text-foreground">
                                        {promotion.nameEn}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {promotion.nameAr}
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    <Badge variant="outline">
                                        {mechanicChipLabel(
                                            promotion.mechanic,
                                            promotion.discountType,
                                            copy,
                                        )}
                                    </Badge>
                                </td>
                                <td className="max-w-xs px-4 py-3">
                                    <p className="text-xs leading-relaxed font-medium text-foreground">
                                        {formatTermsLine(promotion, copy)}
                                    </p>
                                    {promotion.mechanic === 'bundle' &&
                                    promotion.components &&
                                    promotion.components.length > 0 ? (
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {promotion.components.map((c) => (
                                                <span
                                                    key={c.id}
                                                    className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                                                >
                                                    {c.quantity}x{' '}
                                                    {c.productName}
                                                </span>
                                            ))}
                                        </div>
                                    ) : null}
                                </td>
                                <td className="px-4 py-3">
                                    <Badge variant="outline">
                                        {scopeLabel(promotion, copy)}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3 font-medium tabular-nums">
                                    {promotion.mechanic === 'bundle' ? (
                                        <span>
                                            {formatHalalahToSar(
                                                promotion.bundlePriceHalalah ??
                                                    0,
                                            )}{' '}
                                            SAR
                                        </span>
                                    ) : promotion.discountType === 'percent' ? (
                                        copy.typePercentBadge.replace(
                                            ':value',
                                            String(promotion.value),
                                        )
                                    ) : (
                                        copy.typeFixedBadge.replace(
                                            ':value',
                                            formatHalalahToSar(promotion.value),
                                        )
                                    )}
                                </td>
                                <td className="px-4 py-3 text-muted-foreground">
                                    <div className="flex flex-col gap-1">
                                        <span>
                                            {promotion.startsAt === null &&
                                            promotion.endsAt === null
                                                ? copy.always
                                                : promotion.endsAt === null
                                                  ? copy.from.replace(
                                                        ':date',
                                                        promotion.startsAt?.slice(
                                                            0,
                                                            10,
                                                        ) ?? '',
                                                    )
                                                  : promotion.startsAt === null
                                                    ? copy.until.replace(
                                                          ':date',
                                                          promotion.endsAt.slice(
                                                              0,
                                                              10,
                                                          ),
                                                      )
                                                    : copy.window
                                                          .replace(
                                                              ':from',
                                                              promotion.startsAt.slice(
                                                                  0,
                                                                  10,
                                                              ),
                                                          )
                                                          .replace(
                                                              ':until',
                                                              promotion.endsAt.slice(
                                                                  0,
                                                                  10,
                                                              ),
                                                          )}
                                        </span>
                                        {countdown ? (
                                            <span className="inline-flex w-fit items-center gap-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
                                                <Flame className="size-3" />
                                                <span>
                                                    {copy.endsIn.replace(
                                                        ':time',
                                                        countdown,
                                                    )}
                                                </span>
                                            </span>
                                        ) : null}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <Badge
                                        variant={
                                            promotion.isActive
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {promotion.isActive
                                            ? copy.active
                                            : copy.inactive}
                                    </Badge>
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        {canManage ? (
                                            <>
                                                <Button
                                                    className="min-h-11 min-w-11 px-3 text-xs"
                                                    onClick={() =>
                                                        onEdit(promotion)
                                                    }
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    {copy.editButton}
                                                </Button>
                                                <Button
                                                    className="min-h-11 min-w-11 px-3 text-xs font-medium"
                                                    onClick={() =>
                                                        onToggle(
                                                            promotion,
                                                            !promotion.isActive,
                                                        )
                                                    }
                                                    type="button"
                                                    variant={
                                                        promotion.isActive
                                                            ? 'destructive'
                                                            : 'outline'
                                                    }
                                                >
                                                    {promotion.isActive
                                                        ? copy.deactivateTitle
                                                        : copy.activateTitle}
                                                </Button>
                                            </>
                                        ) : null}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function PromotionDrawer({
    categories,
    copy,
    createUrl,
    editingPromotion,
    mode,
    onClose,
    onSaved,
    products,
    updateUrlTemplate,
}: {
    categories: AdminPromotionsPageProps['categories'];
    copy: AdminPromotionsPageProps['adminUi']['promotions'];
    createUrl: string;
    editingPromotion: AdminPromotionRow | null;
    mode: 'create' | 'edit';
    onClose: () => void;
    onSaved: () => void;
    products: AdminPromotionProductOption[];
    updateUrlTemplate: string;
}) {
    const [formData, setFormData] = useState<PromotionFormData>(() =>
        editingPromotion ? promotionToForm(editingPromotion) : emptyForm,
    );
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);

    const isEdit = mode === 'edit' && editingPromotion !== null;

    // Component product lookup
    const productPriceMap = useMemo(() => {
        const map = new Map<string, number>();

        for (const p of products) {
            map.set(p.id, p.priceHalalah);
        }

        return map;
    }, [products]);

    // Live bundle calculations
    const partsTotalHalalah = useMemo(() => {
        let sum = 0;

        for (const comp of formData.components) {
            if (comp.product_id) {
                const price = productPriceMap.get(comp.product_id) ?? 0;
                sum += price * Math.max(1, comp.quantity);
            }
        }

        return sum;
    }, [formData.components, productPriceMap]);

    const bundlePriceHalalah = useMemo(() => {
        if (!formData.bundle_price) {
            return 0;
        }

        return parseSarToHalalah(formData.bundle_price);
    }, [formData.bundle_price]);

    const bundleSavingHalalah = Math.max(
        0,
        partsTotalHalalah - bundlePriceHalalah,
    );
    const bundleSavingPercent =
        partsTotalHalalah > 0
            ? Math.round((bundleSavingHalalah / partsTotalHalalah) * 100)
            : 0;

    function handleFieldChange(field: keyof PromotionFormData, value: unknown) {
        setFormData((prev) => ({
            ...prev,
            ...(field === 'scope' ? { category: '', service_type: '' } : {}),
            [field]: value,
        }));
        setFormErrors((prev) => {
            const next = { ...prev };
            delete next[field];

            return next;
        });
    }

    function handleMechanicChange(mechanicType: PromotionFormMechanic) {
        setFormData((prev) => {
            let discountType: 'percent' | 'fixed' = 'percent';

            if (mechanicType === 'fixed' || mechanicType === 'bundle') {
                discountType = 'fixed';
            }

            return {
                ...prev,
                mechanic_type: mechanicType,
                discount_type: discountType,
            };
        });
    }

    function handleComponentChange(
        index: number,
        field: 'product_id' | 'quantity',
        value: string | number,
    ) {
        setFormData((prev) => {
            const updated = [...prev.components];
            updated[index] = {
                ...updated[index],
                [field]: value,
            };

            return { ...prev, components: updated };
        });
        setFormErrors((prev) => {
            const next = { ...prev };
            delete next['components'];
            delete next[`components.${index}.product_id`];
            delete next[`components.${index}.quantity`];

            return next;
        });
    }

    function addComponentRow() {
        setFormData((prev) => ({
            ...prev,
            components: [...prev.components, { product_id: '', quantity: 1 }],
        }));
    }

    function removeComponentRow(index: number) {
        setFormData((prev) => {
            if (prev.components.length <= 2) {
                return prev;
            }

            const updated = prev.components.filter((_, i) => i !== index);

            return { ...prev, components: updated };
        });
    }

    async function submitForm() {
        setSaving(true);
        setSaveError(null);
        setFormErrors({});

        const url = isEdit
            ? updateUrlTemplate.replace('__ID__', editingPromotion.id)
            : createUrl;
        const method = isEdit ? 'PUT' : 'POST';

        let mechanicValue = 'item';
        let discountTypeValue = formData.discount_type;
        let valueHalalahOrPercent: number | null = null;
        let buyQuantity: number | null = null;
        let getQuantity: number | null = null;
        let maxApplications: number | null = null;
        let discountTarget: string | null = null;
        let qualifyingScope: string | null = null;
        let bundlePriceHalalahVal: number | null = null;
        let componentsPayload: Array<{ product_id: string; quantity: number }> =
            [];

        if (formData.mechanic_type === 'bundle') {
            mechanicValue = 'bundle';
            discountTypeValue = 'fixed';
            valueHalalahOrPercent = 0;
            bundlePriceHalalahVal = parseSarToHalalah(formData.bundle_price);
            componentsPayload = formData.components
                .filter((c) => c.product_id)
                .map((c) => ({
                    product_id: c.product_id,
                    quantity: Number(c.quantity) || 1,
                }));
        } else if (formData.mechanic_type === 'nth_item') {
            mechanicValue = 'nth_item';
            discountTypeValue = formData.discount_type;
            valueHalalahOrPercent =
                formData.discount_type === 'fixed'
                    ? parseSarToHalalah(formData.value)
                    : Number(formData.value);
            buyQuantity = formData.buy_quantity
                ? Number(formData.buy_quantity)
                : null;
            getQuantity = formData.get_quantity
                ? Number(formData.get_quantity)
                : null;
            maxApplications = formData.max_applications
                ? Number(formData.max_applications)
                : null;
            discountTarget = formData.discount_target;
            qualifyingScope = formData.qualifying_scope;
        } else {
            mechanicValue = 'item';
            discountTypeValue =
                formData.mechanic_type === 'fixed' ? 'fixed' : 'percent';
            valueHalalahOrPercent =
                discountTypeValue === 'fixed'
                    ? parseSarToHalalah(formData.value)
                    : Number(formData.value);
        }

        const payload: Record<string, unknown> = {
            name_ar: formData.name_ar,
            name_en: formData.name_en,
            badge_ar: formData.badge_ar || null,
            badge_en: formData.badge_en || null,
            mechanic: mechanicValue,
            scope: formData.scope,
            category:
                formData.scope === 'category'
                    ? formData.category || null
                    : null,
            service_type:
                formData.scope === 'service'
                    ? formData.service_type || null
                    : null,
            discount_type: discountTypeValue,
            value: valueHalalahOrPercent,
            buy_quantity: buyQuantity,
            get_quantity: getQuantity,
            max_applications: maxApplications,
            discount_target: discountTarget,
            qualifying_scope: qualifyingScope,
            bundle_price_halalah: bundlePriceHalalahVal,
            applies_to_promoted_items: formData.applies_to_promoted_items,
            components:
                mechanicValue === 'bundle' ? componentsPayload : undefined,
            starts_at: formData.starts_at || null,
            ends_at: formData.ends_at || null,
            is_active: formData.is_active,
        };

        try {
            const res = await fetch(url, {
                body: JSON.stringify(payload),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                method,
            });

            if (res.status === 422) {
                const json = (await res.json()) as {
                    errors: Record<string, string[]>;
                };
                const mapped: Record<string, string> = {};

                for (const [k, v] of Object.entries(json.errors)) {
                    mapped[k] = v[0] ?? '';
                }

                setFormErrors(mapped);
                setSaveError(copy.messages.validationError);
            } else if (res.status === 403) {
                setSaveError(copy.messages.forbiddenError);
            } else if (res.status === 409) {
                setSaveError(copy.messages.conflictError);
            } else if (!res.ok) {
                setSaveError(copy.messages.genericError);
            } else {
                onSaved();
            }
        } catch {
            setSaveError(copy.messages.networkError);
        } finally {
            setSaving(false);
        }
    }

    return (
        <SheetContent
            className="flex w-full flex-col overflow-y-auto sm:max-w-xl md:max-w-2xl"
            dir="ltr"
            side="right"
        >
            <SheetHeader className="border-b border-border pb-4">
                <SheetTitle className="text-xl font-bold">
                    {isEdit ? copy.editTitle : copy.createTitle}
                </SheetTitle>
                <SheetDescription className="sr-only">
                    {copy.description}
                </SheetDescription>
            </SheetHeader>

            <div className="flex flex-1 flex-col gap-6 py-4">
                {saveError ? (
                    <Alert variant="destructive">
                        <AlertDescription>{saveError}</AlertDescription>
                    </Alert>
                ) : null}

                {/* 1. Mechanic Picker Cards */}
                <div className="flex flex-col gap-2">
                    <Label className="text-sm font-semibold">
                        {copy.mechanicLabel}
                    </Label>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {/* Percent Card */}
                        <button
                            className={`flex min-h-11 flex-col items-start justify-start rounded-lg border p-3.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none ${
                                formData.mechanic_type === 'percent'
                                    ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
                            }`}
                            onClick={() => handleMechanicChange('percent')}
                            type="button"
                        >
                            <div className="flex items-center gap-2 font-semibold text-foreground">
                                <Percent className="size-4 text-primary" />
                                <span>{copy.mechanics.percent.title}</span>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {copy.mechanics.percent.description}
                            </p>
                        </button>

                        {/* Fixed Card */}
                        <button
                            className={`flex min-h-11 flex-col items-start justify-start rounded-lg border p-3.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none ${
                                formData.mechanic_type === 'fixed'
                                    ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
                            }`}
                            onClick={() => handleMechanicChange('fixed')}
                            type="button"
                        >
                            <div className="flex items-center gap-2 font-semibold text-foreground">
                                <Tag className="size-4 text-primary" />
                                <span>{copy.mechanics.fixed.title}</span>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {copy.mechanics.fixed.description}
                            </p>
                        </button>

                        {/* nth_item Card */}
                        <button
                            className={`flex min-h-11 flex-col items-start justify-start rounded-lg border p-3.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none ${
                                formData.mechanic_type === 'nth_item'
                                    ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
                            }`}
                            onClick={() => handleMechanicChange('nth_item')}
                            type="button"
                        >
                            <div className="flex items-center gap-2 font-semibold text-foreground">
                                <Zap className="size-4 text-primary" />
                                <span>{copy.mechanics.nth_item.title}</span>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {copy.mechanics.nth_item.description}
                            </p>
                        </button>

                        {/* Bundle Card */}
                        <button
                            className={`flex min-h-11 flex-col items-start justify-start rounded-lg border p-3.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none ${
                                formData.mechanic_type === 'bundle'
                                    ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary'
                                    : 'border-border bg-card text-muted-foreground hover:bg-muted/50'
                            }`}
                            onClick={() => handleMechanicChange('bundle')}
                            type="button"
                        >
                            <div className="flex items-center gap-2 font-semibold text-foreground">
                                <Layers className="size-4 text-primary" />
                                <span>{copy.mechanics.bundle.title}</span>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {copy.mechanics.bundle.description}
                            </p>
                        </button>
                    </div>
                </div>

                {/* 2. Names */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-name-en">
                            {copy.nameEnLabel}
                        </Label>
                        <Input
                            id="promotion-name-en"
                            className="min-h-11 text-sm"
                            placeholder={copy.nameEnPlaceholder}
                            value={formData.name_en}
                            onChange={(e) =>
                                handleFieldChange('name_en', e.target.value)
                            }
                        />
                        {formErrors.name_en ? (
                            <p className="text-xs text-destructive">
                                {formErrors.name_en}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-name-ar">
                            {copy.nameArLabel}
                        </Label>
                        <Input
                            id="promotion-name-ar"
                            className="min-h-11 text-sm"
                            dir="rtl"
                            placeholder={copy.nameArPlaceholder}
                            value={formData.name_ar}
                            onChange={(e) =>
                                handleFieldChange('name_ar', e.target.value)
                            }
                        />
                        {formErrors.name_ar ? (
                            <p className="text-xs text-destructive">
                                {formErrors.name_ar}
                            </p>
                        ) : null}
                    </div>
                </div>

                {/* Badges */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-badge-en">
                            {copy.badgeEnLabel}
                        </Label>
                        <Input
                            id="promotion-badge-en"
                            className="min-h-11 text-sm"
                            dir="ltr"
                            maxLength={24}
                            placeholder={copy.badgeEnPlaceholder}
                            value={formData.badge_en}
                            onChange={(e) =>
                                handleFieldChange('badge_en', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {copy.badgeEnHelp}
                        </p>
                        {formErrors.badge_en ? (
                            <p className="text-xs text-destructive">
                                {formErrors.badge_en}
                            </p>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-badge-ar">
                            {copy.badgeArLabel}
                        </Label>
                        <Input
                            id="promotion-badge-ar"
                            className="min-h-11 text-sm"
                            dir="rtl"
                            maxLength={24}
                            placeholder={copy.badgeArPlaceholder}
                            value={formData.badge_ar}
                            onChange={(e) =>
                                handleFieldChange('badge_ar', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {copy.badgeArHelp}
                        </p>
                        {formErrors.badge_ar ? (
                            <p className="text-xs text-destructive">
                                {formErrors.badge_ar}
                            </p>
                        ) : null}
                    </div>
                </div>

                {/* 3. Scope */}
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-scope">
                            {copy.scopeLabel}
                        </Label>
                        <Select
                            value={formData.scope}
                            onValueChange={(v) => handleFieldChange('scope', v)}
                        >
                            <SelectTrigger
                                id="promotion-scope"
                                className="min-h-11 text-sm"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    className="min-h-11 text-sm"
                                    value="all"
                                >
                                    {copy.scopeAll}
                                </SelectItem>
                                <SelectItem
                                    className="min-h-11 text-sm"
                                    value="service"
                                >
                                    {copy.scopeService}
                                </SelectItem>
                                <SelectItem
                                    className="min-h-11 text-sm"
                                    value="category"
                                >
                                    {copy.scopeCategory}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {formErrors.scope ? (
                            <p className="text-xs text-destructive">
                                {formErrors.scope}
                            </p>
                        ) : null}
                    </div>

                    {formData.scope === 'service' ? (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="promotion-service-type">
                                {copy.serviceTypeLabel}
                            </Label>
                            <Select
                                value={formData.service_type}
                                onValueChange={(v) =>
                                    handleFieldChange('service_type', v)
                                }
                            >
                                <SelectTrigger
                                    id="promotion-service-type"
                                    className="min-h-11 text-sm"
                                >
                                    <SelectValue
                                        placeholder={
                                            copy.serviceTypePlaceholder
                                        }
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {serviceTypes.map((st) => (
                                        <SelectItem
                                            key={st}
                                            className="min-h-11 text-sm"
                                            value={st}
                                        >
                                            {st}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {formErrors.service_type ? (
                                <p className="text-xs text-destructive">
                                    {formErrors.service_type}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    {formData.scope === 'category' ? (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="promotion-category">
                                {copy.categoryLabel}
                            </Label>
                            <Select
                                value={formData.category}
                                onValueChange={(v) =>
                                    handleFieldChange('category', v)
                                }
                            >
                                <SelectTrigger
                                    id="promotion-category"
                                    className="min-h-11 text-sm"
                                >
                                    <SelectValue
                                        placeholder={copy.categoryPlaceholder}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((cat) => (
                                        <SelectItem
                                            key={cat.id}
                                            className="min-h-11 text-sm"
                                            value={cat.id}
                                        >
                                            {cat.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {formErrors.category ? (
                                <p className="text-xs text-destructive">
                                    {formErrors.category}
                                </p>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                {/* 4. Conditional Mechanic Sections */}

                {/* --- A. PERCENT OR FIXED (ITEM PROMOTION) --- */}
                {(formData.mechanic_type === 'percent' ||
                    formData.mechanic_type === 'fixed') && (
                    <div className="flex flex-col gap-4 rounded-lg border border-border p-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="promotion-value">
                                {copy.valueLabel}{' '}
                                {formData.mechanic_type === 'percent'
                                    ? '(%)'
                                    : '(SAR)'}
                            </Label>
                            <Input
                                id="promotion-value"
                                className="min-h-11 text-sm"
                                inputMode="decimal"
                                min="0.01"
                                placeholder={
                                    formData.mechanic_type === 'fixed'
                                        ? '15.00'
                                        : '20'
                                }
                                step={
                                    formData.mechanic_type === 'fixed'
                                        ? '0.01'
                                        : '1'
                                }
                                type="number"
                                value={formData.value}
                                onChange={(e) =>
                                    handleFieldChange('value', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {formData.mechanic_type === 'percent'
                                    ? copy.valuePercentHelp
                                    : copy.valueFixedHelp}
                            </p>
                            {formErrors.value ? (
                                <p className="text-xs text-destructive">
                                    {formErrors.value}
                                </p>
                            ) : null}
                        </div>
                    </div>
                )}

                {/* --- B. NTH_ITEM (BUY X GET Y) --- */}
                {formData.mechanic_type === 'nth_item' && (
                    <div className="flex flex-col gap-4 rounded-lg border border-border p-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="promotion-buy-quantity">
                                    {copy.buyQuantityLabel}
                                </Label>
                                <Input
                                    id="promotion-buy-quantity"
                                    className="min-h-11 text-sm"
                                    min="1"
                                    step="1"
                                    type="number"
                                    value={formData.buy_quantity}
                                    onChange={(e) =>
                                        handleFieldChange(
                                            'buy_quantity',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {copy.buyQuantityHelp}
                                </p>
                                {formErrors.buy_quantity ? (
                                    <p className="text-xs text-destructive">
                                        {formErrors.buy_quantity}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="promotion-get-quantity">
                                    {copy.getQuantityLabel}
                                </Label>
                                <Input
                                    id="promotion-get-quantity"
                                    className="min-h-11 text-sm"
                                    min="1"
                                    step="1"
                                    type="number"
                                    value={formData.get_quantity}
                                    onChange={(e) =>
                                        handleFieldChange(
                                            'get_quantity',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {copy.getQuantityHelp}
                                </p>
                                {formErrors.get_quantity ? (
                                    <p className="text-xs text-destructive">
                                        {formErrors.get_quantity}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="promotion-discount-type">
                                    {copy.typeLabel}
                                </Label>
                                <Select
                                    value={formData.discount_type}
                                    onValueChange={(v) =>
                                        handleFieldChange('discount_type', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="promotion-discount-type"
                                        className="min-h-11 text-sm"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            value="percent"
                                        >
                                            {copy.typePercent}
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            value="fixed"
                                        >
                                            {copy.typeFixed}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="promotion-nth-value">
                                    {copy.valueLabel}{' '}
                                    {formData.discount_type === 'percent'
                                        ? '(%)'
                                        : '(SAR)'}
                                </Label>
                                <Input
                                    id="promotion-nth-value"
                                    className="min-h-11 text-sm"
                                    inputMode="decimal"
                                    min="0.01"
                                    placeholder={
                                        formData.discount_type === 'fixed'
                                            ? '10.00'
                                            : '50'
                                    }
                                    step={
                                        formData.discount_type === 'fixed'
                                            ? '0.01'
                                            : '1'
                                    }
                                    type="number"
                                    value={formData.value}
                                    onChange={(e) =>
                                        handleFieldChange(
                                            'value',
                                            e.target.value,
                                        )
                                    }
                                />
                                {formErrors.value ? (
                                    <p className="text-xs text-destructive">
                                        {formErrors.value}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="promotion-qualifying-scope">
                                    {copy.qualifyingScopeLabel}
                                </Label>
                                <Select
                                    value={formData.qualifying_scope}
                                    onValueChange={(v) =>
                                        handleFieldChange('qualifying_scope', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="promotion-qualifying-scope"
                                        className="min-h-11 text-sm"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            value="any"
                                        >
                                            {copy.qualifyingScopes.any}
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            value="same_product"
                                        >
                                            {copy.qualifyingScopes.same_product}
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            value="same_category"
                                        >
                                            {
                                                copy.qualifyingScopes
                                                    .same_category
                                            }
                                        </SelectItem>
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            value="same_service"
                                        >
                                            {copy.qualifyingScopes.same_service}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="promotion-max-applications">
                                    {copy.maxApplicationsLabel}
                                </Label>
                                <Input
                                    id="promotion-max-applications"
                                    className="min-h-11 text-sm"
                                    min="1"
                                    placeholder={
                                        copy.maxApplicationsPlaceholder
                                    }
                                    step="1"
                                    type="number"
                                    value={formData.max_applications}
                                    onChange={(e) =>
                                        handleFieldChange(
                                            'max_applications',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {copy.maxApplicationsHelp}
                                </p>
                                {formErrors.max_applications ? (
                                    <p className="text-xs text-destructive">
                                        {formErrors.max_applications}
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="promotion-discount-target">
                                {copy.discountTargetLabel}
                            </Label>
                            <Select
                                value={formData.discount_target}
                                onValueChange={(v) =>
                                    handleFieldChange('discount_target', v)
                                }
                            >
                                <SelectTrigger
                                    id="promotion-discount-target"
                                    className="min-h-11 text-sm"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        className="min-h-11 text-sm"
                                        value="cheapest"
                                    >
                                        {copy.discountTargetCheapest}
                                    </SelectItem>
                                    <SelectItem
                                        className="min-h-11 text-sm"
                                        value="most_expensive"
                                    >
                                        {copy.discountTargetMostExpensive}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                {copy.discountTargetHelp}
                            </p>
                        </div>
                    </div>
                )}

                {/* --- C. BUNDLE (COMPONENTS + BUNDLE PRICE) --- */}
                {formData.mechanic_type === 'bundle' && (
                    <div className="flex flex-col gap-4 rounded-lg border border-border p-4">
                        <div className="flex items-center justify-between">
                            <Label className="text-sm font-semibold">
                                {copy.componentsLabel}
                            </Label>
                            <Button
                                className="min-h-11 px-3 text-xs"
                                onClick={addComponentRow}
                                type="button"
                                variant="outline"
                            >
                                <Plus className="me-1.5 size-3.5" />
                                <span>{copy.addComponentButton}</span>
                            </Button>
                        </div>

                        {formErrors.components ? (
                            <p className="text-xs text-destructive">
                                {formErrors.components}
                            </p>
                        ) : null}

                        <div className="flex flex-col gap-3">
                            {formData.components.map((comp, idx) => (
                                <div
                                    key={idx}
                                    className="flex items-center gap-2"
                                >
                                    <div className="flex-1">
                                        <Select
                                            value={comp.product_id}
                                            onValueChange={(val) =>
                                                handleComponentChange(
                                                    idx,
                                                    'product_id',
                                                    val,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="min-h-11 text-sm">
                                                <SelectValue
                                                    placeholder={
                                                        copy.selectProductPlaceholder
                                                    }
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {products.map((p) => (
                                                    <SelectItem
                                                        key={p.id}
                                                        className="min-h-11 text-sm"
                                                        value={p.id}
                                                    >
                                                        {p.name} —{' '}
                                                        {formatHalalahToSar(
                                                            p.priceHalalah,
                                                        )}{' '}
                                                        SAR
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {formErrors[
                                            `components.${idx}.product_id`
                                        ] ? (
                                            <p className="mt-1 text-xs text-destructive">
                                                {
                                                    formErrors[
                                                        `components.${idx}.product_id`
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="w-24">
                                        <Input
                                            aria-label={copy.quantityLabel}
                                            className="min-h-11 text-sm"
                                            min="1"
                                            step="1"
                                            type="number"
                                            value={comp.quantity}
                                            onChange={(e) =>
                                                handleComponentChange(
                                                    idx,
                                                    'quantity',
                                                    Math.max(
                                                        1,
                                                        parseInt(
                                                            e.target.value,
                                                            10,
                                                        ) || 1,
                                                    ),
                                                )
                                            }
                                        />
                                        {formErrors[
                                            `components.${idx}.quantity`
                                        ] ? (
                                            <p className="mt-1 text-xs text-destructive">
                                                {
                                                    formErrors[
                                                        `components.${idx}.quantity`
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <Button
                                        aria-label={copy.removeComponentButton}
                                        className="min-h-11 min-w-11 shrink-0 text-muted-foreground hover:text-destructive"
                                        disabled={
                                            formData.components.length <= 2
                                        }
                                        onClick={() => removeComponentRow(idx)}
                                        type="button"
                                        variant="ghost"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </div>

                        {/* Bundle price & live calculated saving */}
                        <div className="flex flex-col gap-1.5 border-t border-border pt-2">
                            <Label htmlFor="promotion-bundle-price">
                                {copy.bundlePriceLabel}
                            </Label>
                            <Input
                                id="promotion-bundle-price"
                                className="min-h-11 text-sm"
                                inputMode="decimal"
                                min="0.01"
                                placeholder={copy.bundlePricePlaceholder}
                                step="0.01"
                                type="number"
                                value={formData.bundle_price}
                                onChange={(e) =>
                                    handleFieldChange(
                                        'bundle_price',
                                        e.target.value,
                                    )
                                }
                            />
                            {formErrors.bundle_price_halalah ? (
                                <p className="text-xs text-destructive">
                                    {formErrors.bundle_price_halalah}
                                </p>
                            ) : null}

                            {/* Live calculation info card */}
                            <div className="mt-2 space-y-1.5 rounded-md bg-muted/60 p-3 text-xs">
                                <div className="flex items-center justify-between font-medium text-foreground">
                                    <span>
                                        {copy.totalPartsPrice.replace(
                                            ':amount',
                                            formatHalalahToSar(
                                                partsTotalHalalah,
                                            ),
                                        )}
                                    </span>
                                    <span
                                        className={
                                            bundleSavingHalalah > 0
                                                ? 'font-bold text-emerald-600 dark:text-emerald-400'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {copy.bundleSaving
                                            .replace(
                                                ':amount',
                                                formatHalalahToSar(
                                                    bundleSavingHalalah,
                                                ),
                                            )
                                            .replace(
                                                ':percent',
                                                String(bundleSavingPercent),
                                            )}
                                    </span>
                                </div>
                                <p className="text-[11px] text-muted-foreground">
                                    {copy.bundleSavingHelp}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* 5. Stacking Switch */}
                <div className="space-y-2 rounded-lg border border-border p-4">
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="promotion-applies-to-promoted"
                            className="min-h-5 min-w-5"
                            checked={formData.applies_to_promoted_items}
                            onCheckedChange={(checked) =>
                                handleFieldChange(
                                    'applies_to_promoted_items',
                                    checked === true,
                                )
                            }
                        />
                        <Label
                            htmlFor="promotion-applies-to-promoted"
                            className="cursor-pointer text-sm font-medium"
                        >
                            {copy.appliesToPromotedLabel}
                        </Label>
                    </div>
                    <p className="ps-8 text-xs text-muted-foreground">
                        {copy.appliesToPromotedHelp}
                    </p>
                </div>

                {/* 6. Schedule (Starts at, Ends at) */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-starts-at">
                            {copy.startsAtLabel}
                        </Label>
                        <Input
                            id="promotion-starts-at"
                            className="min-h-11 text-sm"
                            type="date"
                            value={formData.starts_at}
                            onChange={(e) =>
                                handleFieldChange('starts_at', e.target.value)
                            }
                        />
                        {formErrors.starts_at ? (
                            <p className="text-xs text-destructive">
                                {formErrors.starts_at}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="promotion-ends-at">
                            {copy.endsAtLabel}
                        </Label>
                        <Input
                            id="promotion-ends-at"
                            className="min-h-11 text-sm"
                            type="date"
                            value={formData.ends_at}
                            onChange={(e) =>
                                handleFieldChange('ends_at', e.target.value)
                            }
                        />
                        {formErrors.ends_at ? (
                            <p className="text-xs text-destructive">
                                {formErrors.ends_at}
                            </p>
                        ) : null}
                    </div>
                </div>

                {/* 7. Active Status */}
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="promotion-is-active"
                        className="min-h-5 min-w-5"
                        checked={formData.is_active}
                        onCheckedChange={(checked) =>
                            handleFieldChange('is_active', checked === true)
                        }
                    />
                    <Label
                        htmlFor="promotion-is-active"
                        className="cursor-pointer text-sm font-medium"
                    >
                        {copy.isActiveLabel}
                    </Label>
                </div>
            </div>

            <SheetFooter className="gap-2 border-t border-border pt-4">
                <Button
                    className="min-h-11 px-4 text-sm"
                    disabled={saving}
                    onClick={onClose}
                    type="button"
                    variant="outline"
                >
                    {copy.cancelButton}
                </Button>
                <Button
                    className="min-h-11 px-5 text-sm font-semibold"
                    disabled={saving}
                    onClick={submitForm}
                    type="button"
                >
                    {saving ? copy.savingButton : copy.saveButton}
                </Button>
            </SheetFooter>
        </SheetContent>
    );
}

function PromotionsPagination({
    copy,
    onPageChange,
    pagination,
}: {
    copy: AdminPromotionsPageProps['adminUi']['orders'];
    onPageChange: (page: number) => void;
    pagination: AdminPromotionsPageProps['pagination'];
}) {
    if (pagination.lastPage <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
            <span>
                {copy.page} {pagination.currentPage} {copy.of}{' '}
                {pagination.lastPage}
            </span>
            <div className="flex gap-2">
                <Button
                    className="min-h-11 min-w-11 px-3 text-xs"
                    disabled={pagination.currentPage <= 1}
                    onClick={() => onPageChange(pagination.currentPage - 1)}
                    type="button"
                    variant="outline"
                >
                    {copy.previous}
                </Button>
                <Button
                    className="min-h-11 min-w-11 px-3 text-xs"
                    disabled={pagination.currentPage >= pagination.lastPage}
                    onClick={() => onPageChange(pagination.currentPage + 1)}
                    type="button"
                    variant="outline"
                >
                    {copy.next}
                </Button>
            </div>
        </div>
    );
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Bot,
    CheckCircle2,
    Database,
    Eye,
    EyeOff,
    FileText,
    History,
    Image as ImageIcon,
    Layers,
    Pencil,
    UserCheck,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';

import AdminBadge from '@/components/admin/admin-badge';
import { formatAdminMoney } from '@/components/admin/admin-money';
import AdminProductEditDialog from '@/components/admin/products/admin-product-edit-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type {
    AdminProductDetail,
    AdminProductDetailPageProps,
} from '@/types/admin';

export default function AdminProductDetailPage() {
    const { props, url } = usePage<AdminProductDetailPageProps>();
    const [product, setProduct] = useState<AdminProductDetail>(props.product);
    const [syncedProduct, setSyncedProduct] = useState(props.product);
    const [editDialogOpen, setEditDialogOpen] = useState(false);
    const [feedback, setFeedback] = useState<{
        message: string;
        title: string;
        type: 'success' | 'error' | 'conflict';
    } | null>(null);

    // Re-sync local working copy whenever Inertia delivers fresh props.
    if (props.product !== syncedProduct) {
        setSyncedProduct(props.product);
        setProduct(props.product);
    }

    const copy = props.adminUi.productDetail;
    const orderServices = props.adminUi.orders.services ?? {};

    const pathname = new URL(url, window.location.origin).pathname;
    const isLocalized = pathname.startsWith('/en/admin');
    const productsListUrl = isLocalized
        ? '/en/admin/products'
        : '/admin/products';

    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const canManageCatalog = props.permissions.includes('catalog.manage');
    const isManual = product.authority === 'manual';
    const isVisible = product.isVisible;
    const serviceLabel =
        orderServices[product.serviceType] ?? product.serviceType;

    const handleConflict = () => {
        router.reload({
            only: ['product'],
            onSuccess: () => {
                setFeedback({
                    message: copy.conflictError,
                    title: copy.conflictTitle,
                    type: 'conflict',
                });
            },
        });
    };

    const handleUpdateSuccess = (result: {
        descriptionAr: string | null;
        descriptionEn: string | null;
        isVisible: boolean;
        nameAr: string;
        nameEn: string;
        sortOrder: number;
        updatedAt: string;
    }) => {
        setProduct((prev) => ({
            ...prev,
            descriptionAr: result.descriptionAr,
            descriptionEn: result.descriptionEn,
            isVisible: result.isVisible,
            name: props.locale === 'en' ? result.nameEn : result.nameAr,
            nameAr: result.nameAr,
            nameEn: result.nameEn,
            sortOrder: result.sortOrder,
            updatedAt: result.updatedAt,
        }));
        setFeedback({
            message: copy.productUpdatedMessage,
            title: copy.productUpdated,
            type: 'success',
        });
    };

    return (
        <article className="space-y-8" dir={props.direction}>
            <Head
                title={copy.headTitle.replace(
                    ':name',
                    product.name || product.slug,
                )}
            />

            {/* Back link & Top bar */}
            <div className="flex flex-col gap-4">
                <div className="flex items-center">
                    <Link
                        className="inline-flex min-h-11 items-center gap-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                        href={productsListUrl}
                    >
                        <ArrowLeft
                            aria-hidden="true"
                            className="size-4 rtl:rotate-180"
                        />
                        <span>{copy.backToProducts}</span>
                    </Link>
                </div>

                <div className="flex flex-col justify-between gap-4 border-b border-border pb-6 sm:flex-row sm:items-center">
                    <div className="flex flex-col gap-1.5">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="text-2xl font-bold tracking-tight text-foreground md:text-3xl">
                                <bdi>{product.name}</bdi>
                            </h1>
                            <AdminBadge
                                icon={isManual ? UserCheck : Bot}
                                variant={isManual ? 'info' : 'neutral'}
                            >
                                {isManual
                                    ? copy.authorityManual
                                    : copy.authorityAutomation}
                            </AdminBadge>
                            <AdminBadge
                                icon={isVisible ? Eye : EyeOff}
                                variant={isVisible ? 'success' : 'neutral'}
                            >
                                {isVisible ? copy.visible : copy.hidden}
                            </AdminBadge>
                            {product.isArchived ? (
                                <AdminBadge variant="danger">
                                    {copy.archived}
                                </AdminBadge>
                            ) : null}
                            {!product.isEditable ? (
                                <AdminBadge variant="neutral">
                                    {copy.readOnlyBadge}
                                </AdminBadge>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <span>
                                {copy.slug}:{' '}
                                <strong className="font-mono text-foreground">
                                    {product.slug}
                                </strong>
                            </span>
                            <span>•</span>
                            <span>
                                ID:{' '}
                                <strong className="font-mono text-foreground">
                                    {product.id}
                                </strong>
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        {product.isEditable && canManageCatalog ? (
                            <Button
                                className="min-h-11 text-sm md:text-xs"
                                onClick={() => setEditDialogOpen(true)}
                                type="button"
                            >
                                <Pencil
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                <span>{copy.editButton}</span>
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>

            {/* Feedback Alerts */}
            {feedback ? (
                <Alert
                    variant={
                        feedback.type === 'error'
                            ? 'destructive'
                            : feedback.type === 'conflict'
                              ? 'default'
                              : 'default'
                    }
                >
                    <AlertCircle className="size-4" />
                    <AlertTitle>{feedback.title}</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                        <span>{feedback.message}</span>
                        <Button
                            className="min-h-11 text-xs"
                            onClick={() => setFeedback(null)}
                            type="button"
                            variant="ghost"
                        >
                            {props.adminUi.common.dismiss}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            {/* Read-Only Notice for Automation Products */}
            {!product.isEditable ? (
                <Alert className="border-border/80 bg-muted/40 text-foreground">
                    <Bot className="size-4 text-muted-foreground" />
                    <AlertTitle className="text-xs font-semibold">
                        {copy.authorityAutomation}
                    </AlertTitle>
                    <AlertDescription className="text-xs text-muted-foreground">
                        {copy.readOnlyNotice}
                    </AlertDescription>
                </Alert>
            ) : null}

            {/* Section 1: Product Information Card */}
            <section
                aria-labelledby="product-info-heading"
                className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
            >
                <h2
                    className="mb-4 text-base font-bold text-foreground"
                    id="product-info-heading"
                >
                    {copy.productInformation}
                </h2>

                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.serviceType}
                        </dt>
                        <dd className="text-sm font-semibold text-foreground">
                            {serviceLabel}
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.category}
                        </dt>
                        <dd className="text-sm font-semibold text-foreground">
                            {product.category
                                ? product.category.name
                                : copy.noCategory}
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.source}
                        </dt>
                        <dd className="text-sm font-semibold text-foreground">
                            {product.source
                                ? product.source.name
                                : copy.manualSource}
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.sortOrder}
                        </dt>
                        <dd className="text-sm font-semibold text-foreground tabular-nums">
                            {product.sortOrder}
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.registeredAt}
                        </dt>
                        <dd className="text-xs font-medium text-muted-foreground tabular-nums">
                            {product.createdAt
                                ? dateFormatter.format(
                                      new Date(product.createdAt),
                                  )
                                : '—'}
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.lastUpdated}
                        </dt>
                        <dd className="text-xs font-medium text-muted-foreground tabular-nums">
                            {product.updatedAt
                                ? dateFormatter.format(
                                      new Date(product.updatedAt),
                                  )
                                : '—'}
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.nameArLabel}
                        </dt>
                        <dd className="text-sm font-medium text-foreground">
                            <bdi>{product.nameAr}</bdi>
                        </dd>
                    </div>

                    <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                        <dt className="text-xs text-muted-foreground">
                            {copy.nameEnLabel}
                        </dt>
                        <dd className="text-sm font-medium text-foreground">
                            <bdi>{product.nameEn}</bdi>
                        </dd>
                    </div>
                </dl>
            </section>

            {/* Section 2: Descriptions */}
            <section
                aria-labelledby="product-descriptions-heading"
                className="space-y-4"
            >
                <div className="flex items-center gap-2">
                    <FileText className="size-4 text-primary" />
                    <h2
                        className="text-base font-bold text-foreground"
                        id="product-descriptions-heading"
                    >
                        {copy.descriptionsSection}
                    </h2>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div className="rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs">
                        <h3 className="mb-2 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                            {copy.descriptionArLabel}
                        </h3>
                        {product.descriptionAr ? (
                            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                                <bdi>{product.descriptionAr}</bdi>
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground italic">
                                {copy.noDescriptionAr}
                            </p>
                        )}
                    </div>

                    <div className="rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs">
                        <h3 className="mb-2 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                            {copy.descriptionEnLabel}
                        </h3>
                        {product.descriptionEn ? (
                            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                                <bdi>{product.descriptionEn}</bdi>
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground italic">
                                {copy.noDescriptionEn}
                            </p>
                        )}
                    </div>
                </div>
            </section>

            {/* Section 3: Variants & Pricing */}
            <section
                aria-labelledby="product-variants-heading"
                className="space-y-4"
            >
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Layers className="size-4 text-primary" />
                        <h2
                            className="text-base font-bold text-foreground"
                            id="product-variants-heading"
                        >
                            {copy.variantsSection}
                        </h2>
                    </div>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {product.variants.length}{' '}
                        {copy.variantsCount.toLowerCase()}
                    </span>
                </div>

                <Alert className="border-border/80 bg-muted/20 text-muted-foreground">
                    <AlertTitle className="text-xs font-medium text-foreground">
                        {copy.pricesReadOnlyNotice}
                    </AlertTitle>
                </Alert>

                <div className="overflow-x-auto rounded-lg border border-border bg-card shadow-xs">
                    <table className="w-full text-start text-xs">
                        <thead className="border-b border-border bg-muted/40 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.sku}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.platform}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.market}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.quantity}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.price}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.salePrice}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.priceVersion}
                                </th>
                                <th className="px-4 py-3 text-start font-medium">
                                    {copy.status}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {product.variants.length > 0 ? (
                                product.variants.map((variant) => (
                                    <tr
                                        className="transition-colors hover:bg-muted/30"
                                        key={variant.id}
                                    >
                                        <td className="px-4 py-3 font-mono font-bold text-foreground">
                                            {variant.sku}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-muted-foreground">
                                            {variant.platform}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-muted-foreground">
                                            {variant.market}
                                        </td>
                                        <td className="px-4 py-3 text-foreground tabular-nums">
                                            {variant.quantityK !== null
                                                ? `${variant.quantityK}k`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3 font-bold text-foreground tabular-nums">
                                            {formatAdminMoney(
                                                variant.price,
                                                props.locale,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                            {variant.salePrice ? (
                                                <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                                    {formatAdminMoney(
                                                        variant.salePrice,
                                                        props.locale,
                                                    )}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                            v{variant.priceVersion}
                                        </td>
                                        <td className="px-4 py-3">
                                            <AdminBadge
                                                icon={
                                                    variant.isActive
                                                        ? CheckCircle2
                                                        : XCircle
                                                }
                                                variant={
                                                    variant.isActive
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {variant.isActive
                                                    ? copy.activeFlag
                                                    : copy.inactiveFlag}
                                            </AdminBadge>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        className="px-4 py-6 text-center text-muted-foreground"
                                        colSpan={8}
                                    >
                                        {copy.noVariants}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* Section 4: Media Assets */}
            <section
                aria-labelledby="product-media-heading"
                className="space-y-4"
            >
                <div className="flex items-center gap-2">
                    <ImageIcon className="size-4 text-primary" />
                    <h2
                        className="text-base font-bold text-foreground"
                        id="product-media-heading"
                    >
                        {copy.mediaSection}
                    </h2>
                </div>

                {product.media.length > 0 ? (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {product.media.map((mediaItem) => (
                            <div
                                className="flex flex-col gap-2 rounded-lg border border-border bg-card p-4 text-card-foreground shadow-xs"
                                key={mediaItem.id}
                            >
                                <div className="flex items-center justify-between text-xs text-muted-foreground">
                                    <span>
                                        {copy.disk}:{' '}
                                        <strong className="text-foreground">
                                            {mediaItem.disk}
                                        </strong>
                                    </span>
                                    <span>
                                        {copy.sortOrder}:{' '}
                                        <strong className="text-foreground tabular-nums">
                                            {mediaItem.sortOrder}
                                        </strong>
                                    </span>
                                </div>
                                <div className="rounded-md bg-muted/40 p-2 font-mono text-xs [overflow-wrap:anywhere] text-foreground">
                                    {mediaItem.path}
                                </div>
                                {mediaItem.altAr || mediaItem.altEn ? (
                                    <div className="text-xs text-muted-foreground">
                                        {mediaItem.altAr ? (
                                            <p>
                                                {copy.altAr}: {mediaItem.altAr}
                                            </p>
                                        ) : null}
                                        {mediaItem.altEn ? (
                                            <p>
                                                {copy.altEn}: {mediaItem.altEn}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-lg border border-border bg-card p-6 text-center text-xs text-muted-foreground shadow-xs">
                        {copy.noMedia}
                    </div>
                )}
            </section>

            {/* Section 5: Automation Snapshot History */}
            {product.automation ? (
                <section
                    aria-labelledby="product-automation-heading"
                    className="space-y-4"
                >
                    <div className="flex items-center gap-2">
                        <Database className="size-4 text-primary" />
                        <h2
                            className="text-base font-bold text-foreground"
                            id="product-automation-heading"
                        >
                            {copy.automationSection}
                        </h2>
                    </div>

                    <div className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs">
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {copy.runId}
                                </dt>
                                <dd className="font-mono text-xs font-semibold text-foreground">
                                    {product.automation.runId || '—'}
                                </dd>
                            </div>
                            <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {copy.runStatus}
                                </dt>
                                <dd className="text-xs font-semibold text-foreground">
                                    {product.automation.status || '—'}
                                </dd>
                            </div>
                            <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {copy.outcome}
                                </dt>
                                <dd className="text-xs font-semibold text-foreground">
                                    {product.automation.outcome || '—'}
                                </dd>
                            </div>
                            <div className="flex flex-col gap-1 rounded-md border border-border/50 bg-background/50 p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {copy.syncedAt}
                                </dt>
                                <dd className="text-xs font-medium text-muted-foreground tabular-nums">
                                    {product.automation.syncedAt
                                        ? dateFormatter.format(
                                              new Date(
                                                  product.automation.syncedAt,
                                              ),
                                          )
                                        : '—'}
                                </dd>
                            </div>
                        </dl>
                        {product.automation.error ? (
                            <div className="mt-3 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive">
                                {product.automation.error}
                            </div>
                        ) : null}
                    </div>
                </section>
            ) : null}

            {/* Section 6: Staff Audit Logs */}
            {product.recentAuditLogs ? (
                <section
                    aria-labelledby="product-audit-heading"
                    className="space-y-4"
                >
                    <div className="flex items-center gap-2">
                        <History className="size-4 text-primary" />
                        <h2
                            className="text-base font-bold text-foreground"
                            id="product-audit-heading"
                        >
                            {copy.auditSection}
                        </h2>
                    </div>

                    <div className="overflow-x-auto rounded-lg border border-border bg-card shadow-xs">
                        <table className="w-full text-start text-xs">
                            <thead className="border-b border-border bg-muted/40 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 text-start font-medium">
                                        {copy.date}
                                    </th>
                                    <th className="px-4 py-3 text-start font-medium">
                                        {copy.actor}
                                    </th>
                                    <th className="px-4 py-3 text-start font-medium">
                                        {copy.action}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/60">
                                {product.recentAuditLogs.length > 0 ? (
                                    product.recentAuditLogs.map((log) => (
                                        <tr
                                            className="transition-colors hover:bg-muted/30"
                                            key={log.id}
                                        >
                                            <td className="px-4 py-3 text-muted-foreground tabular-nums">
                                                {log.createdAt
                                                    ? dateFormatter.format(
                                                          new Date(
                                                              log.createdAt,
                                                          ),
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 font-medium text-foreground">
                                                {log.actor
                                                    ? `${log.actor.name} (${log.actor.role})`
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-foreground">
                                                {log.action}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            className="px-4 py-6 text-center text-muted-foreground"
                                            colSpan={3}
                                        >
                                            {copy.noAudit}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            ) : null}

            {/* Edit Product Dialog */}
            {product.isEditable ? (
                <AdminProductEditDialog
                    adminUi={props.adminUi}
                    confirmPasswordUrl={props.confirmPasswordUrl}
                    onConflict={handleConflict}
                    onOpenChange={setEditDialogOpen}
                    onSuccess={handleUpdateSuccess}
                    open={editDialogOpen}
                    product={product}
                    updateUrl={props.updateUrl}
                />
            ) : null}
        </article>
    );
}

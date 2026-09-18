import { useHttp } from '@inertiajs/react';
import { Check, Copy, Link2 } from 'lucide-react';
import React, { useCallback, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AdminTranslations } from '@/types/admin';

export type AdminOrderTrackingLinkProps = {
    trackingLinkUrl: string;
    adminUi: AdminTranslations;
};

function parseResponseData(data: unknown): unknown {
    if (typeof data === 'string') {
        try {
            return JSON.parse(data);
        } catch {
            return data;
        }
    }

    return data;
}

/**
 * The customer's own tracking link, on demand.
 *
 * Pressing it twice returns the same link: the store keeps one live token per
 * order, so a link already sent to a customer is never invalidated by somebody
 * pressing the button again to read it.
 */
export default function AdminOrderTrackingLink({
    trackingLinkUrl,
    adminUi,
}: AdminOrderTrackingLinkProps) {
    const copy = adminUi.orderDetail.trackingLink;

    const [link, setLink] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // The reveal endpoint's idiom: method, url, and the body - which here is
    // empty, because the order in the path is the whole request.
    const http = useHttp<Record<string, never>, { data: { url: string } }>(
        'post',
        trackingLinkUrl,
        {},
    );

    const issue = useCallback(async () => {
        let handled = false;

        try {
            await http.submit('post', trackingLinkUrl, {
                headers: { Accept: 'application/json' },
                onSuccess: (response) => {
                    handled = true;
                    const body = parseResponseData(response.data);
                    const payload =
                        body && typeof body === 'object' && 'data' in body
                            ? (body as { data: { url: string } }).data
                            : null;

                    if (payload?.url) {
                        setLink(payload.url);
                        setError(null);
                    } else {
                        setError(copy.failed);
                    }
                },
                onError: () => {
                    handled = true;
                    setError(copy.failed);
                },
            });
        } catch {
            if (!handled) {
                setError(copy.failed);
            }
        }
    }, [copy.failed, http, trackingLinkUrl]);

    const copyLink = useCallback(async () => {
        if (!link) {
            return;
        }

        try {
            await navigator.clipboard.writeText(link);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // The link is on screen and selectable; a clipboard the browser
            // refuses is not a failure worth a message.
        }
    }, [link]);

    return (
        <div className="space-y-3">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-medium">{copy.title}</p>
                    <p className="text-xs text-muted-foreground">
                        {copy.description}
                    </p>
                </div>
                {link === null && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={issue}
                        disabled={http.processing}
                    >
                        {http.processing ? (
                            <Spinner className="size-4" />
                        ) : (
                            <Link2 className="size-4" />
                        )}
                        {copy.action}
                    </Button>
                )}
            </div>

            {link !== null && (
                <div className="flex items-center gap-2">
                    <code
                        dir="ltr"
                        className="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 text-xs"
                    >
                        {link}
                    </code>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={copyLink}
                        aria-label={copy.copy}
                    >
                        {copied ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                    </Button>
                </div>
            )}

            {error !== null && (
                <p className="text-xs text-destructive">{error}</p>
            )}
        </div>
    );
}

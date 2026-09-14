import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

/**
 * The signed tracking link, on a phone.
 *
 * This is the page a customer actually opens - it arrives in a WhatsApp message
 * - and it is the only surface where the order card is reached without an
 * account, so what it must not show (any money, anything about the account) is
 * as much the subject as what it must.
 *
 * The button height is asserted because it was wrong: 10px of padding around a
 * 13px label came to 39.5px against the 44px this project requires, and nothing
 * short of measuring a rendered page would have caught it.
 */

test('the tracking link page fits a phone and offers its buttons', async ({
    page,
}) => {
    const email = `link-${randomUUID().slice(0, 8)}@browser.test`;
    const orderNumber = `UT-${Math.floor(Math.random() * 9_000_000 + 1_000_000)}`;
    const enc = (v: string) =>
        `base64_decode('${Buffer.from(v).toString('base64')}')`;
    const A = String.fromCharCode(92) + 'App' + String.fromCharCode(92);
    const ns = (rest: string) =>
        A + rest.split('/').join(String.fromCharCode(92));

    const php = [
        `$u = ${ns('Models/User')}::create(['first_name'=>'Link','last_name'=>'Probe','email'=>${enc(email)},'password'=>'x-Password-1!','email_verified_at'=>now(),'preferred_locale'=>'ar']);`,
        `$o = $u->orders()->create(['order_number'=>${enc(orderNumber)},'status'=>${ns('Enums/OrderStatus')}::WaitingForCustomer,'locale'=>'ar','currency'=>'SAR','subtotal_halalah'=>15000,'discount_halalah'=>0,'wallet_halalah'=>0,'payment_halalah'=>15000,'total_halalah'=>15000,'placed_at'=>now()]);`,
        `$i = $o->items()->create(['sku'=>'L1','name_ar'=>'كوينز','name_en'=>'Coins','service_type'=>${ns('Enums/ServiceType')}::Coins,'platform'=>${ns('Enums/Platform')}::PlayStation,'status'=>${ns('Enums/OrderItemStatus')}::InProgress,'quantity'=>1,'unit_price_halalah'=>15000,'subtotal_halalah'=>15000,'discount_halalah'=>0,'total_halalah'=>15000]);`,
        `${ns('Models/FulfillmentJob')}::create(['order_item_id'=>$i->id,'idempotency_key'=>$o->order_number,'status'=>${ns('Enums/FulfillmentStatus')}::WaitingForCustomer,'supplier'=>${ns('Enums/Supplier')}::Fft,'supplier_order_id'=>'link-1','delivery_phase'=>${ns('Enums/DeliveryPhase')}::Coins,'observed_at'=>now()->subMinutes(3),'observed_state'=>'wrongUserPass','observation_supported'=>true,'presentation'=>${ns('Enums/TrackingPresentation')}::NeedsReview,'hold_reason'=>${ns('Enums/OrderHoldReason')}::Credentials,'hold_tone'=>${ns('Enums/HoldTone')}::Action,'allowed_actions'=>['edit_credentials'],'coins_ordered'=>1250000,'coins_delivered'=>0,'next_poll_at'=>null]);`,
        `echo app(${ns('Actions/Orders/IssueOrderTrackingLink')}::class)->execute($o->fresh());`,
    ].join(' ');

    let out = '';

    try {
        out = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
            cwd: process.cwd(),
            stdio: 'pipe',
        }).toString();
    } catch (error) {
        const f = error as { stdout?: Buffer; stderr?: Buffer };

        throw new Error(
            `seed failed:\n${f.stdout?.toString() ?? ''}\n${f.stderr?.toString() ?? ''}`,
        );
    }

    const url = out.split(/\s+/).find((t) => t.includes('/orders/track/'));
    expect(url, `no url in tinker output: ${out}`).toBeTruthy();
    const path = new URL(url as string).pathname;

    try {
        await page.setViewportSize({ width: 390, height: 800 });
        const errors: string[] = [];
        page.on('console', (m) => {
            if (m.type() === 'error') {
                errors.push(m.text());
            }
        });

        await page.goto(path);
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.getByText(orderNumber)).toBeVisible();

        // The hold, and the button it offers.
        const button = page
            .locator('.track-action-box__actions button')
            .first();
        await expect(button).toBeVisible();
        const box = await button.boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);

        // No money anywhere on a capability page.
        const body = (await page.locator('body').innerText()).replace(
            /\s/g,
            '',
        );
        expect(body).not.toContain('150.00');
        expect(body).not.toContain('ر.س');

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - window.innerWidth,
        );
        expect(overflow).toBeLessThanOrEqual(1);
        expect(errors).toEqual([]);
    } finally {
        execFileSync(
            'php',
            [
                'artisan',
                'tinker',
                '--execute',
                `$u=${ns('Models/User')}::where('email',${enc(email)})->first(); if($u){$u->orders()->each(function($o){$o->items()->each(fn($i)=>$i->fulfillmentJob()->delete()); ${ns('Models/OrderTrackingLink')}::where('order_id',$o->id)->delete(); $o->payments()->delete(); $o->items()->delete(); $o->delete();}); $u->delete();}`,
            ],
            { cwd: process.cwd(), stdio: 'pipe' },
        );
    }
});

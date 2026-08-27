@php($start = $locale === 'ar' ? 'right' : 'left')
@php($end = $locale === 'ar' ? 'left' : 'right')
{{-- Physical gaps, computed per direction so one template serves both:
     between thumbnail and name, and between name and line total. --}}
@php($gapAfterThumb = $locale === 'ar' ? 'padding-left: 12px;' : 'padding-right: 12px;')
@php($gapBeforeTotal = $locale === 'ar' ? 'padding-left: 14px;' : 'padding-right: 14px;')
{{-- Latin labels may be tracked; Arabic labels must not be (letter-spacing
     breaks the connected script), so tracking is conditional. --}}
@php($labelTracking = $locale === 'en' ? ' letter-spacing: 0.08em;' : '')
{{-- The leader is a zero-height div pinned 24px down so the dots land on
     the text baseline instead of drifting to the bottom of the row. --}}
@php($leaderStyle = 'padding: 0 10px; vertical-align: top; width: 100%;')
@php($leaderRule = 'border-bottom: 1px dotted #cfc4a8; height: 0; margin-top: 24px;')
<x-mail::message>
# {{ trans('mail.order_paid_heading') }}

{{ trans('mail.order_paid_intro') }}

{{-- Document header: the order identity. Hairlines above and below frame
     it like a receipt slip; the number is the primary fact, the date the
     secondary. align attributes are physical per locale - the one thing
     every client, Outlook included, will honour. --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin: 22px 0 20px;">
<tr>
<td align="{{ $start }}" valign="bottom" width="60%" style="border-bottom: 1px solid #e6ddc9; border-top: 1px solid #e6ddc9; padding: 14px 0 14px 0; {{ $locale === 'ar' ? 'padding-left: 14px;' : 'padding-right: 14px;' }}">
<span style="color: #6b6252; display: block; font-size: 11px; font-weight: 700; margin-bottom: 4px;{{ $labelTracking }}">{{ trans('mail.order_paid_number') }}</span>
<span style="color: #17140d; display: block; font-size: 17px; font-weight: 800; letter-spacing: 0.02em;">{{ $number }}</span>
</td>
<td align="{{ $end }}" valign="bottom" style="border-bottom: 1px solid #e6ddc9; border-top: 1px solid #e6ddc9; padding: 14px 0;">
<span style="color: #6b6252; display: block; font-size: 11px; font-weight: 700; margin-bottom: 4px;{{ $labelTracking }}">{{ trans('mail.order_paid_date') }}</span>
<span style="color: #3d372c; display: block; font-size: 14px; font-weight: 600;">{{ $placedAt }}</span>
</td>
</tr>
</table>

{{-- Line items: a till slip. Thumbnail, name and quantity, line total;
     hairline rules between rows, none after the last. --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse;">
@foreach ($items as $item)
@php($rowLine = $loop->last ? '' : 'border-bottom: 1px solid #f0e8d8;')
<tr>
<td width="62" valign="top" style="padding: 13px 0; {{ $gapAfterThumb }} {{ $rowLine }}">
@if ($item['imageUrl'] !== null)
{{-- The warm swatch background and hairline keep a blocked image a
     tidy rounded tile instead of a collapsed hole. --}}
<img src="{{ $item['imageUrl'] }}" width="48" height="48" alt="" style="background-color: #f4eddd; border: 1px solid #e6ddc9; border-radius: 10px; display: block; height: 48px; width: 48px;">
@else
<div style="background-color: #f4eddd; border: 1px solid #e6ddc9; border-radius: 10px; height: 48px; width: 48px;"></div>
@endif
</td>
<td align="{{ $start }}" valign="top" style="padding: 13px 0; {{ $gapBeforeTotal }} {{ $rowLine }}">
<span style="color: #17140d; display: block; font-size: 15px; font-weight: 700; line-height: 1.5;">{{ $item['name'] }}</span>
<span style="color: #6b6252; display: block; font-size: 12px; line-height: 1.6; margin-top: 2px;">{{ trans('mail.order_paid_quantity') }}: {{ $item['quantity'] }}</span>
</td>
<td align="{{ $end }}" valign="top" style="padding: 13px 0; white-space: nowrap; {{ $rowLine }}">
<span style="color: #17140d; font-size: 15px; font-weight: 700;">{{ $item['total'] }}</span>
</td>
</tr>
@endforeach
</table>

{{-- Summary lines with receipt-style dotted leaders. --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin-top: 18px;">
<tr>
<td align="{{ $start }}" style="color: #3d372c; font-size: 14px; padding: 7px 0; white-space: nowrap;">{{ trans('mail.order_paid_subtotal') }}</td>
<td style="{{ $leaderStyle }}"><div style="{{ $leaderRule }}"></div></td>
<td align="{{ $end }}" style="color: #17140d; font-size: 14px; font-weight: 600; padding: 7px 0; white-space: nowrap;">{{ $subtotal }}</td>
</tr>
@if ($discount !== null)
<tr>
<td align="{{ $start }}" style="color: #6b6252; font-size: 14px; padding: 7px 0; white-space: nowrap;">{{ trans('mail.order_paid_discount') }}</td>
<td style="{{ $leaderStyle }}"><div style="{{ $leaderRule }}"></div></td>
<td align="{{ $end }}" style="color: #1e7a45; font-size: 14px; font-weight: 600; padding: 7px 0; white-space: nowrap;">&minus;{{ $discount }}</td>
</tr>
@endif
@if ($wallet !== null)
<tr>
<td align="{{ $start }}" style="color: #6b6252; font-size: 14px; padding: 7px 0; white-space: nowrap;">{{ trans('mail.order_paid_wallet') }}</td>
<td style="{{ $leaderStyle }}"><div style="{{ $leaderRule }}"></div></td>
<td align="{{ $end }}" style="color: #17140d; font-size: 14px; font-weight: 600; padding: 7px 0; white-space: nowrap;">{{ $wallet }}</td>
</tr>
@endif
</table>

{{-- The total band: the one gilded moment. The amount is large, bold, and
     the dark-gold ink that stays readable on a light card. --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: separate; border-spacing: 0; margin-top: 20px;">
<tr>
<td style="background-color: #f9f3e3; border: 1px solid #e9d8ab; border-radius: 12px; padding: 16px 20px 17px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse;">
<tr>
<td align="{{ $start }}" style="color: #17140d; font-size: 15px; font-weight: 800;">{{ trans('mail.order_paid_total') }}</td>
<td align="{{ $end }}" style="color: #8a6d1f; font-size: 23px; font-weight: 800; white-space: nowrap;">{{ $total }}</td>
</tr>
</table>
@if ($paymentMethod !== null)
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin-top: 8px;">
<tr>
<td align="{{ $start }}" style="color: #6b6252; font-size: 12px; line-height: 1.5;">{{ trans('mail.order_paid_method') }}: {{ $paymentMethod }}</td>
</tr>
</table>
@endif
</td>
</tr>
</table>

<x-mail::button :url="$orderUrl">
{{ trans('mail.order_paid_action') }}
</x-mail::button>

<p style="color: #6b6252; font-size: 13px; line-height: 1.7; margin: 6px 0 0; text-align: start;">{{ trans('mail.order_paid_help') }}</p>
</x-mail::message>

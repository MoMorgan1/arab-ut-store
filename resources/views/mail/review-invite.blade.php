@php($start = $locale === 'ar' ? 'right' : 'left')
{{-- Latin labels may be tracked; Arabic labels must not be (letter-spacing
     breaks the connected script), so tracking is conditional. --}}
@php($labelTracking = $locale === 'en' ? ' letter-spacing: 0.08em;' : '')
<x-mail::message>
# {{ trans('mail.review_invite_heading') }}

{{ trans('mail.review_invite_intro') }}

{{-- One fact only: which order this is about. Framed by the same hairlines
     the receipt uses so the two mails read as one family. --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin: 22px 0 20px;">
<tr>
<td align="{{ $start }}" valign="bottom" style="border-bottom: 1px solid #e6ddc9; border-top: 1px solid #e6ddc9; padding: 14px 0;">
<span style="color: #6b6252; display: block; font-size: 11px; font-weight: 700; margin-bottom: 4px;{{ $labelTracking }}">{{ trans('mail.review_invite_number') }}</span>
<span style="color: #17140d; display: block; font-size: 17px; font-weight: 800; letter-spacing: 0.02em;">{{ $number }}</span>
</td>
</tr>
</table>

<x-mail::button :url="$orderUrl">
{{ trans('mail.review_invite_action') }}
</x-mail::button>
</x-mail::message>

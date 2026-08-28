@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
{{-- A single padded anchor inside one cell: padding lives on the button's
     borders (theme CSS), which Outlook renders identically to every other
     client. No nested tables for the button itself. --}}
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>

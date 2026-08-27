@php($accentSide = app()->getLocale() === 'ar' ? 'right' : 'left')
{{-- The gold accent must lead the reading direction, so the side is
     computed from the locale and applied inline: the theme sets no side
     border, otherwise the inliner would add the wrong second edge in
     Arabic. --}}
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="panel-content" style="border-{{ $accentSide }}: 4px solid #d4a843;">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>

@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
{{-- Width and height are stated so a blocked image still reserves its
     space, and the alt text renders as a gold wordmark on the band. --}}
<img src="{{ rtrim(config('app.url'), '/') }}/images/arabut-logo-header.webp" class="logo" alt="{{ config('app.name') }}" width="52" height="52" style="display: block; border: none; width: 52px; height: 52px;">
</a>
</td>
</tr>

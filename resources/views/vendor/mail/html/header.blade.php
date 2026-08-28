@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
{{-- PNG, not the WebP the storefront uses: several clients and image
     proxies flatten WebP alpha onto black, which turned the crest into a
     black tile on Gmail's inverted band. PNG alpha every client renders.
     Width and height are stated so a blocked image still reserves its
     space, and the alt text renders as a gold wordmark on the band. The
     source is 3x so the crest stays sharp on a retina screen. --}}
<img src="{{ rtrim(config('app.url'), '/') }}/images/mail/arabut-logo-mail.png" class="logo" alt="{{ config('app.name') }}" width="72" height="72" style="display: block; border: none; width: 72px; height: 72px;">
</a>
</td>
</tr>

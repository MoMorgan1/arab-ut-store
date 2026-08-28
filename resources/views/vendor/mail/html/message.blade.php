<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
<p class="footer-brand">Arab UT</p>
<p class="footer-links">
<a href="https://wa.me/966537998099">{{ trans('mail.footer_support') }}</a>
&nbsp;&middot;&nbsp;
<a href="{{ config('app.url') }}">{{ trans('mail.footer_store') }}</a>
</p>
<p class="footer-fine">{{ trans('mail.footer_freelance') }}</p>
<p class="footer-fine">&copy; {{ date('Y') }} {{ config('app.name') }}. {{ trans('mail.footer_rights') }}</p>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

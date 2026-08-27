<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
{{-- Arabic is the store's default language, so the whole shell flips with the
     locale rather than leaving Arabic text in a left-to-right frame. dir is
     also set on the content cell below: some clients drop the html attribute,
     and text-align: start only resolves correctly when the base direction
     travels with the content. --}}
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
{{-- The card is light by design; declaring light keeps clients from
     re-inverting it, and stops iOS from linkifying receipt numbers. --}}
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<meta name="format-detection" content="telephone=no">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.wrapper-cell {
padding: 14px 10px 26px !important;
}

.content-cell {
padding: 26px 20px 28px !important;
}

.header {
padding: 22px 0 20px !important;
}

.footer-cell {
padding: 22px 18px 28px !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="wrapper-cell" align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>

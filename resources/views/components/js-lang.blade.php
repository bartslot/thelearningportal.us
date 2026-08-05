{{-- The player's JavaScript strings, translated server-side and handed to the browser.
     Same shape as the audio manifest above it: one global, published once per page. The list
     lives in App\Support\JsTranslations so `lang:audit` can see it. --}}
@once
    <script>window.__lpLang = @js(\App\Support\JsTranslations::forBrowser());</script>
@endonce

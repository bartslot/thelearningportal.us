{{-- The launch announcement. Same hero, different script: see the `mode` prop in
     components/landing/hero.blade.php. Deliberately its own page rather than a change to the
     landing page, so the product's own front door keeps demonstrating the product. --}}
<x-layouts.landing :title="__('History Portal is now live')">
    <x-landing.header />
    <x-landing.hero mode="launch" />
</x-layouts.landing>

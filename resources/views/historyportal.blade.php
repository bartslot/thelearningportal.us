<x-layouts.landing title="The Learning Portal">
    <x-landing.header />
    <x-landing.hero />
    <x-landing.lessons :lessons="$playableLessons ?? collect()" />
    <x-landing.trending :lessons="$trendingLessons ?? collect()" />
    <x-landing.pricing />
    <x-landing.footer />
</x-layouts.landing>

{{-- Every meta tag a public page needs, in one place.

     Pass the page's route suffix (see App\Support\Seo::PAGES) so canonical and hreflang are built
     from the same source; without `page` the component still renders title/description/OG, it just
     cannot claim a canonical.

     Usage:
       <x-seo page="lessons" :title="__('History lessons')" :description="..." />
       <x-seo page="lesson" :params="['slug' => $slug]" :title="$lesson->title" :graph="$graph" /> --}}
@props([
    'title',
    'description',
    'page' => null,
    'params' => [],
    'image' => null,
    'type' => 'website',
    'graph' => null,
    'noindex' => false,
])

@php
    use App\Support\Seo;

    $canonical = $page ? Seo::url($page, $params) : url()->current();
    $alternates = $page ? Seo::alternates($page, $params) : [];
    $image = $image ?: asset('images/og-default.png');
    $siteName = 'The Learning Portal';
    // The title tag carries the brand once; the OG title does not repeat it.
    $fullTitle = $title === $siteName ? $siteName : $title.' · '.$siteName;
@endphp

<title>{{ $fullTitle }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $canonical }}">

@if ($noindex)
    <meta name="robots" content="noindex, follow">
@else
    <meta name="robots" content="index, follow, max-image-preview:large">
@endif

{{-- One URL per language, so a German search result points at the German page. --}}
@foreach ($alternates as $hreflang => $href)
    <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
@endforeach

<meta property="og:type" content="{{ $type }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $image }}">
<meta property="og:locale" content="{{ str_replace('-', '_', \App\Support\Locales::region(app()->getLocale())) }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">

@if ($graph)
    <script type="application/ld+json">{!! json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif

@props(['movie'])

{{--
    Renders SEO + Open Graph + Twitter meta tags for a movie page.
    Falls back gracefully when seo_* columns are not yet populated.

    Usage (push into the layout <head> stack):
        @push('head')
            <x-movie-seo :movie="$movieModel" />
        @endpush

    Or place directly inside a custom <head> section.
--}}

@php
    /** @var \App\Models\Movie $movie */
    $appName  = config('app.name', 'FLiK');
    $year     = optional($movie->release_date)->format('Y');

    $fallbackTitle = trim(
        ($movie->title ?? '') . ($year ? " ({$year})" : '') . " — {$appName}"
    );
    $fallbackDescription = trim(\Illuminate\Support\Str::limit(
        $movie->overview ?? "Nonton {$movie->title} di {$appName} — Rumah Sinema Indonesia.",
        160,
        ''
    ));

    $title       = $movie->seo_title       ?: $fallbackTitle;
    $description = $movie->seo_description ?: $fallbackDescription;
    $keywords    = $movie->seo_keywords    ?: '';

    $canonical = url('/movie/' . $movie->slug);

    // Prefer a proper poster URL; the model has dedicated accessors.
    $image = $movie->backdrop_url ?: $movie->poster_url;
    if ($image && !\Illuminate\Support\Str::startsWith($image, ['http://', 'https://'])) {
        $image = url($image);
    }

    $locale = str_replace('-', '_', app()->getLocale() ?: 'id_ID');
@endphp

<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
@if($keywords)
    <meta name="keywords" content="{{ $keywords }}">
@endif
<link rel="canonical" href="{{ $canonical }}">

{{-- Open Graph --}}
<meta property="og:type" content="video.movie">
<meta property="og:site_name" content="{{ $appName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:locale" content="{{ $locale }}">
@if($image)
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:image:alt" content="{{ $movie->title }}">
@endif
@if($year)
    <meta property="og:video:release_date" content="{{ $year }}">
@endif

{{-- Twitter Card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@if($image)
    <meta name="twitter:image" content="{{ $image }}">
@endif

<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
    <title>{{ htmlspecialchars($site, ENT_XML1 | ENT_QUOTES, 'UTF-8') }} — Blog</title>
    <link>{{ htmlspecialchars($siteUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</link>
    <atom:link href="{{ htmlspecialchars($feedUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}" rel="self" type="application/rss+xml" />
    <description>Editorial blog {{ htmlspecialchars($site, ENT_XML1 | ENT_QUOTES, 'UTF-8') }} — review, list, dan berita seputar sinema Indonesia &amp; dunia.</description>
    <language>id</language>
    <lastBuildDate>{{ $now->toRssString() }}</lastBuildDate>
    <generator>FLiK Editorial</generator>
@foreach($posts->take(20) as $post)
@php
    $cover = $post->cover_image
        ? (str_starts_with($post->cover_image, 'http') ? $post->cover_image : asset('storage/' . $post->cover_image))
        : null;
    $authorName = $post->author?->name ?? 'Tim FLiK';
    $categoryName = $post->category?->name;
    $description = $post->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($post->body_html ?? ''), 280);
@endphp
    <item>
        <title>{{ htmlspecialchars($post->title, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</title>
        <link>{{ htmlspecialchars($post->url, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</link>
        <guid isPermaLink="true">{{ htmlspecialchars($post->url, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</guid>
        <pubDate>{{ optional($post->published_at)->toRssString() ?? $now->toRssString() }}</pubDate>
        <dc:creator>{{ htmlspecialchars($authorName, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</dc:creator>
@if($categoryName)
        <category>{{ htmlspecialchars($categoryName, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</category>
@endif
        <description>{{ htmlspecialchars($description, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}</description>
@if($cover)
        <enclosure url="{{ htmlspecialchars($cover, ENT_XML1 | ENT_QUOTES, 'UTF-8') }}" type="image/jpeg" length="0" />
@endif
    </item>
@endforeach
</channel>
</rss>

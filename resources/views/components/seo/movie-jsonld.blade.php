@props(['movie'])

@php
    /** @var \App\Models\Movie $movie */

    // Cast members — fetch from relation when present, otherwise empty.
    $actorNames = [];
    if (method_exists($movie, 'castMembers')) {
        $relation = $movie->relationLoaded('castMembers')
            ? $movie->getRelation('castMembers')
            : $movie->castMembers()->limit(15)->get();

        $actorNames = $relation->pluck('name')->filter()->values()->all();
    }

    // Genres — same defensive pattern, supports unloaded relation.
    $genreNames = [];
    if (method_exists($movie, 'genres')) {
        $relation = $movie->relationLoaded('genres')
            ? $movie->getRelation('genres')
            : $movie->genres()->get();

        $genreNames = $relation->pluck('name')->filter()->values()->all();
    }

    // Poster — prefer the absolute URL accessor; fall back to absolute backdrop.
    $image = $movie->poster_url ?? null;
    if ($image && !\Illuminate\Support\Str::startsWith($image, ['http://', 'https://'])) {
        $image = url($image);
    }

    $releaseDate = optional($movie->release_date)->toDateString();

    $voteAverage = $movie->vote_average !== null ? (float) $movie->vote_average : null;
    $voteCount   = $movie->vote_count !== null ? (int) $movie->vote_count : null;

    $payload = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Movie',
        'name'          => $movie->title,
        'alternateName' => $movie->original_title ?: null,
        'description'   => $movie->overview ?: null,
        'image'         => $image ?: null,
        'datePublished' => $releaseDate,
        'url'           => url('/movie/' . $movie->slug),
    ];

    if ($voteAverage !== null && $voteCount !== null && $voteCount > 0) {
        $payload['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $voteAverage,
            'ratingCount' => $voteCount,
            'bestRating'  => 10,
            'worstRating' => 0,
        ];
    }

    if (!empty($genreNames)) {
        $payload['genre'] = $genreNames;
    }

    if (!empty($actorNames)) {
        $payload['actor'] = array_map(
            static fn (string $name): array => ['@type' => 'Person', 'name' => $name],
            $actorNames,
        );
    }

    // Strip nulls / empty arrays for a tight JSON-LD blob.
    $payload = array_filter(
        $payload,
        static fn ($value) => $value !== null && $value !== '' && $value !== [],
    );

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
@endphp

<script type="application/ld+json">{!! $json !!}</script>

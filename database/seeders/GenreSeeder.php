<?php

namespace Database\Seeders;

use App\Models\Cast;
use App\Models\Genre;
use App\Models\Movie;
use Illuminate\Database\Seeder;

class GenreSeeder extends Seeder
{
    public function run()
    {
        $genres = [
            ['name' => 'Action', 'slug' => 'action'],
            ['name' => 'Adventure', 'slug' => 'adventure'],
            ['name' => 'Animation', 'slug' => 'animation'],
            ['name' => 'Comedy', 'slug' => 'comedy'],
            ['name' => 'Crime', 'slug' => 'crime'],
            ['name' => 'Documentary', 'slug' => 'documentary'],
            ['name' => 'Drama', 'slug' => 'drama'],
            ['name' => 'Family', 'slug' => 'family'],
            ['name' => 'Fantasy', 'slug' => 'fantasy'],
            ['name' => 'History', 'slug' => 'history'],
            ['name' => 'Horror', 'slug' => 'horror'],
            ['name' => 'Music', 'slug' => 'music'],
            ['name' => 'Mystery', 'slug' => 'mystery'],
            ['name' => 'Romance', 'slug' => 'romance'],
            ['name' => 'Science Fiction', 'slug' => 'science-fiction'],
            ['name' => 'Thriller', 'slug' => 'thriller'],
            ['name' => 'War', 'slug' => 'war'],
            ['name' => 'Western', 'slug' => 'western'],
        ];

        foreach ($genres as $genre) {
            Genre::updateOrCreate(['slug' => $genre['slug']], $genre);
        }
    }
}

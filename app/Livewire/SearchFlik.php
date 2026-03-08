<?php

namespace App\Livewire;

use App\Models\Movie;
use Livewire\Component;

class SearchFlik extends Component
{
    public ?string $search = '';

    public function render()
    {
        $results = collect();

        if (strlen($this->search) >= 3) {
            $results = Movie::where('title', 'like', '%' . $this->search . '%')
                ->orWhere('original_title', 'like', '%' . $this->search . '%')
                ->limit(7)
                ->get()
                ->map(function ($movie) {
                    return [
                        'id' => $movie->id,
                        'title' => $movie->title,
                        'poster_url' => $movie->poster_url,
                    ];
                });
        }

        return view('livewire.search-flik', [
            'results' => $results,
        ]);
    }
}

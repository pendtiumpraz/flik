<?php

namespace App\Http\Livewire;

use App\Models\Movie;
use Livewire\Component;

/**
 * @psalm-suppress UndefinedClass
 */
class SearchVelflix extends Component
{
    public ?string $searchVelflix = '';

    /**
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function render()
    {
        $searchVelflixResults = collect();

        // @phpstan-ignore-next-line
        if (strlen($this->searchVelflix >= 3)) {
            $searchVelflixResults = Movie::where('title', 'like', '%' . $this->searchVelflix . '%')
                ->orWhere('original_title', 'like', '%' . $this->searchVelflix . '%')
                ->limit(7)
                ->get()
                ->map(function ($movie) {
                    return [
                        'id' => $movie->id,
                        'title' => $movie->title,
                    ];
                });
        }

        return view('livewire.search-velflix', [
            // @phpstan-ignore-next-line
            'searchVelflixResults' => $searchVelflixResults,
        ]);
    }
}

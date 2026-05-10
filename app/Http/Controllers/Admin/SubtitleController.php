<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Services\Ai\Subtitle\LanguageCatalog;
use App\Services\Ai\Subtitle\SubtitleGenerator;
use App\Services\Ai\Subtitle\SubtitleTranslator;
use Illuminate\Http\Request;

class SubtitleController extends Controller
{
    /**
     * Show subtitle manager for a movie.
     */
    public function index(Movie $movie)
    {
        $subtitles = $movie->subtitles()->orderBy('language_code')->get();
        $existingCodes = $subtitles->pluck('language_code')->toArray();
        $grouped = LanguageCatalog::grouped();
        $groups = LanguageCatalog::GROUPS;

        return view('admin.subtitles.index', compact(
            'movie', 'subtitles', 'existingCodes', 'grouped', 'groups'
        ));
    }

    /**
     * Generate base subtitle (Indonesia) from movie audio.
     */
    public function generate(Request $request, Movie $movie, SubtitleGenerator $generator)
    {
        $sourceLang = $request->input('language', 'id');

        try {
            $subtitle = $generator->generate($movie, $sourceLang);
            return back()->with('success', "Subtitle {$subtitle->label} berhasil di-generate ({$subtitle->cue_count} cues).");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal generate subtitle: ' . $e->getMessage());
        }
    }

    /**
     * Translate an existing subtitle to a target language.
     */
    public function translate(Request $request, Movie $movie, SubtitleTranslator $translator)
    {
        $data = $request->validate([
            'source_subtitle_id' => 'required|exists:movie_subtitles,id',
            'target_language' => 'required|string|max:30',
        ]);

        if (!LanguageCatalog::exists($data['target_language'])) {
            return back()->with('error', 'Bahasa target tidak dikenal: ' . $data['target_language']);
        }

        $source = MovieSubtitle::where('movie_id', $movie->id)
            ->findOrFail($data['source_subtitle_id']);

        try {
            $subtitle = $translator->translate($source, $data['target_language']);
            return back()->with('success', "Subtitle {$subtitle->label} berhasil di-translate dari {$source->native_name}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal translate subtitle: ' . $e->getMessage());
        }
    }

    /**
     * Delete a subtitle.
     */
    public function destroy(Movie $movie, MovieSubtitle $subtitle)
    {
        if ($subtitle->movie_id !== $movie->id) abort(404);

        try {
            \Storage::disk($subtitle->disk)->delete($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            // ignore — file may not exist
        }

        $label = $subtitle->label;
        $subtitle->delete();

        return back()->with('success', "Subtitle {$label} dihapus.");
    }

    /**
     * Set as default subtitle.
     */
    public function setDefault(Movie $movie, MovieSubtitle $subtitle)
    {
        if ($subtitle->movie_id !== $movie->id) abort(404);

        // Clear other defaults
        MovieSubtitle::where('movie_id', $movie->id)->update(['is_default' => false]);
        $subtitle->update(['is_default' => true]);

        return back()->with('success', "Default subtitle: {$subtitle->label}");
    }
}

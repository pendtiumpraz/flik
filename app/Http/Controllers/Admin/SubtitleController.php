<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Models\MovieSubtitle;
use App\Services\Ai\Subtitle\DialectTranslator;
use App\Services\Ai\Subtitle\LanguageCatalog;
use App\Services\Ai\Subtitle\ProfanityFilter;
use App\Services\Ai\Subtitle\SpeakerIdentifier;
use App\Services\Ai\Subtitle\SubtitleGenerator;
use App\Services\Ai\Subtitle\SubtitleTranslator;
use App\Services\Ai\Subtitle\WebVttHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

    /**
     * F2 — Translate an existing (Indonesian) subtitle to a regional dialect.
     */
    public function translateDialect(Request $request, Movie $movie, DialectTranslator $dialects)
    {
        $data = $request->validate([
            'source_subtitle_id' => 'required|exists:movie_subtitles,id',
            'dialect'            => 'required|string|in:' . implode(',', array_keys(DialectTranslator::supportedDialects())),
        ]);

        $source = MovieSubtitle::where('movie_id', $movie->id)
            ->findOrFail($data['source_subtitle_id']);

        try {
            $subtitle = $dialects->translateDialect($source, $data['dialect']);
            return back()->with('success', "Subtitle dialek {$subtitle->label} berhasil dibuat dari {$source->native_name}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal translate ke dialek: ' . $e->getMessage());
        }
    }

    /**
     * F6 — Produce a kid-safe (profanity-filtered) variant of an existing subtitle.
     */
    public function kidSafeFilter(Request $request, Movie $movie, ProfanityFilter $filter)
    {
        $data = $request->validate([
            'source_subtitle_id' => 'required|exists:movie_subtitles,id',
        ]);

        $source = MovieSubtitle::where('movie_id', $movie->id)
            ->findOrFail($data['source_subtitle_id']);

        try {
            $subtitle = $filter->filterToKidSafe($source);
            return back()->with('success', "Subtitle kid-safe {$subtitle->label} berhasil dibuat dari {$source->native_name}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal filter kid-safe: ' . $e->getMessage());
        }
    }

    /**
     * L2 — Add speaker name tags to an existing subtitle using the movie's cast list.
     */
    public function addSpeakerTags(Request $request, Movie $movie, SpeakerIdentifier $tagger)
    {
        $data = $request->validate([
            'source_subtitle_id' => 'required|exists:movie_subtitles,id',
        ]);

        $source = MovieSubtitle::where('movie_id', $movie->id)
            ->findOrFail($data['source_subtitle_id']);

        try {
            $subtitle = $tagger->addSpeakerTags($source, $movie);
            return back()->with('success', "Subtitle dengan speaker-tags {$subtitle->label} berhasil dibuat dari {$source->native_name}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal tambah speaker tags: ' . $e->getMessage());
        }
    }

    /**
     * Upload an existing subtitle file (.srt or .vtt). SRT is auto-converted
     * to WebVTT (the format the players consume). Creates/overwrites the row
     * for that language.
     */
    public function upload(Request $request, Movie $movie, WebVttHelper $vtt)
    {
        $data = $request->validate([
            'subtitle_file' => 'required|file|max:5120', // 5MB
            'language'      => 'required|string|max:30',
        ]);

        if (! LanguageCatalog::exists($data['language'])) {
            return back()->with('error', 'Bahasa tidak dikenal: ' . $data['language']);
        }

        $file = $request->file('subtitle_file');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, ['srt', 'vtt'], true)) {
            return back()->with('error', 'Format harus .srt atau .vtt.');
        }

        try {
            $raw = (string) file_get_contents($file->getRealPath());

            // Normalise everything through parse()+build() so the stored file
            // is always clean WebVTT regardless of the source format.
            $source = $ext === 'srt' ? $vtt->srtToVtt($raw) : $raw;
            $cues = $vtt->parse($source);
            if ($cues === []) {
                return back()->with('error', 'File subtitle kosong / tidak valid (0 cue terbaca).');
            }
            $vttContent = $vtt->build($cues);

            $lang = $data['language'];
            $disk = 'public';
            $path = "subtitles/{$movie->slug}/{$lang}.vtt";
            Storage::disk($disk)->put($path, $vttContent);

            MovieSubtitle::updateOrCreate(
                ['movie_id' => $movie->id, 'language_code' => $lang, 'variant' => null],
                [
                    'label'             => LanguageCatalog::nativeName($lang),
                    'webvtt_path'       => $path,
                    'disk'              => $disk,
                    'is_auto_generated' => false,
                    'is_translated'     => false,
                    'status'            => 'ready',
                    'is_active'         => true,
                    'cue_count'         => count($cues),
                ]
            );

            return back()->with('success', "Subtitle {$lang} berhasil diupload (".count($cues)." cue, format {$ext} → vtt).");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal upload subtitle: ' . $e->getMessage());
        }
    }

    /**
     * Download a subtitle as .vtt (default) or .srt (?format=srt). SRT is
     * generated on the fly from the stored WebVTT.
     */
    public function download(Request $request, Movie $movie, MovieSubtitle $subtitle, WebVttHelper $vtt)
    {
        if ($subtitle->movie_id !== $movie->id) {
            abort(404);
        }

        try {
            $content = (string) Storage::disk($subtitle->disk)->get($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            abort(404);
        }

        $format = $request->query('format') === 'srt' ? 'srt' : 'vtt';

        if ($format === 'srt') {
            $body = $vtt->vttToSrt($content);
            $mime = 'application/x-subrip';
        } else {
            $body = $content;
            $mime = 'text/vtt';
        }

        $filename = "{$movie->slug}-{$subtitle->language_code}.{$format}";

        return response($body, 200, [
            'Content-Type'        => $mime.'; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

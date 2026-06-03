<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\MovieSubtitle;
use App\Services\Ai\Subtitle\LanguageCatalog;
use App\Services\Ai\Subtitle\WebVttHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Episode-scoped subtitle manager (upload / download / delete / set-default).
 *
 * Reuses the movie_subtitles table via its nullable episode_id column. AI
 * generate / translate are movie-only for now; episodes accept hand-authored
 * .srt / .vtt uploads (SRT auto-converted to WebVTT).
 */
class EpisodeSubtitleController extends Controller
{
    public function index(Episode $episode)
    {
        $episode->load('movie', 'season');
        $subtitles = $episode->subtitles()->get();
        $grouped = LanguageCatalog::grouped();
        $groups = LanguageCatalog::GROUPS;

        return view('admin.episodes.subtitles', compact('episode', 'subtitles', 'grouped', 'groups'));
    }

    public function upload(Request $request, Episode $episode, WebVttHelper $vtt)
    {
        $data = $request->validate([
            'subtitle_file' => 'required|file|max:5120',
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
            $source = $ext === 'srt' ? $vtt->srtToVtt($raw) : $raw;
            $cues = $vtt->parse($source);
            if ($cues === []) {
                return back()->with('error', 'File subtitle kosong / tidak valid (0 cue terbaca).');
            }
            $vttContent = $vtt->build($cues);

            $lang = $data['language'];
            $disk = 'public';
            $path = "subtitles/episodes/{$episode->id}/{$lang}.vtt";
            Storage::disk($disk)->put($path, $vttContent);

            MovieSubtitle::updateOrCreate(
                [
                    'movie_id'      => $episode->movie_id,
                    'episode_id'    => $episode->id,
                    'language_code' => $lang,
                    'variant'       => null,
                ],
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

            return back()->with('success', "Subtitle {$lang} berhasil diupload (".count($cues)." cue, {$ext} → vtt).");
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal upload subtitle: ' . $e->getMessage());
        }
    }

    public function download(Request $request, Episode $episode, MovieSubtitle $subtitle, WebVttHelper $vtt)
    {
        if ($subtitle->episode_id !== $episode->id) {
            abort(404);
        }

        try {
            $content = (string) Storage::disk($subtitle->disk)->get($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            abort(404);
        }

        $format = $request->query('format') === 'srt' ? 'srt' : 'vtt';
        $body = $format === 'srt' ? $vtt->vttToSrt($content) : $content;
        $mime = $format === 'srt' ? 'application/x-subrip' : 'text/vtt';
        $filename = "episode-{$episode->id}-{$subtitle->language_code}.{$format}";

        return response($body, 200, [
            'Content-Type'        => $mime.'; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function destroy(Episode $episode, MovieSubtitle $subtitle)
    {
        if ($subtitle->episode_id !== $episode->id) {
            abort(404);
        }

        try {
            Storage::disk($subtitle->disk)->delete($subtitle->webvtt_path);
        } catch (\Throwable $e) {
            // ignore — file may not exist
        }

        $label = $subtitle->label;
        $subtitle->delete();

        return back()->with('success', "Subtitle {$label} dihapus.");
    }

    public function setDefault(Episode $episode, MovieSubtitle $subtitle)
    {
        if ($subtitle->episode_id !== $episode->id) {
            abort(404);
        }

        MovieSubtitle::where('episode_id', $episode->id)->update(['is_default' => false]);
        $subtitle->update(['is_default' => true]);

        return back()->with('success', "Default subtitle: {$subtitle->label}");
    }
}

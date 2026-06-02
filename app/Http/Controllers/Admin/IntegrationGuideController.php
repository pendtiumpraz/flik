<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * /admin/integration-guide — in-app render of docs/integration-setup.md.
 *
 * Splits the Markdown guide into per-service sections (one per top-level
 * "##" heading) so an operator can PICK which integration to connect and
 * read its step-by-step tutorial right there — instead of opening the raw
 * file in a code editor. A "Buka Infrastructure" button links to the page
 * where the keys are actually entered.
 *
 * Source of truth stays the Markdown file; this controller never duplicates
 * the content, it just renders + sections it.
 */
class IntegrationGuideController extends Controller
{
    public function index(): View
    {
        $path = base_path('docs/integration-setup.md');

        $intro = '';
        $sections = [];

        if (is_file($path)) {
            [$intro, $sections] = $this->splitSections((string) file_get_contents($path));
        }

        return view('admin.integration-guide.index', [
            'intro'    => $intro,
            'sections' => $sections,
            'missing'  => ! is_file($path),
        ]);
    }

    /**
     * Split the Markdown into the leading intro + an ordered list of
     * top-level (##) sections. The document H1 (#) is dropped — the admin
     * page already carries its own title.
     *
     * @return array{0:string, 1:array<int, array{id:string, title:string, html:string}>}
     */
    private function splitSections(string $md): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];

        $introLines = [];
        /** @var array<int, array{title:string, body:array<int,string>}> $raw */
        $raw = [];
        $cur = null;

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                if ($cur !== null) {
                    $raw[] = $cur;
                }
                $cur = ['title' => $m[1], 'body' => []];
            } elseif (preg_match('/^#\s+/', $line)) {
                continue; // drop the document H1
            } elseif ($cur === null) {
                $introLines[] = $line;
            } else {
                $cur['body'][] = $line;
            }
        }
        if ($cur !== null) {
            $raw[] = $cur;
        }

        $sections = [];
        foreach ($raw as $i => $s) {
            $sections[] = [
                'id'    => 'sec'.$i,
                'title' => $s['title'],
                'html'  => $this->toHtml(implode("\n", $s['body'])),
            ];
        }

        return [$this->toHtml(implode("\n", $introLines)), $sections];
    }

    /**
     * Convert Markdown → HTML, falling back to an escaped <pre> block if the
     * CommonMark converter is unavailable so the page never 500s.
     */
    private function toHtml(string $md): string
    {
        $md = trim($md);
        if ($md === '') {
            return '';
        }

        try {
            return Str::markdown($md);
        } catch (\Throwable $e) {
            return '<pre>'.e($md).'</pre>';
        }
    }
}

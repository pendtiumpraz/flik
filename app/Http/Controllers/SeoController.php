<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Public SEO endpoints — sitemap.xml + robots.txt.
 *
 * Both routes are mounted OUTSIDE the auth middleware group so crawlers
 * (Googlebot, Bingbot, etc.) can fetch them without a session.
 *
 * sitemap is cached for 1h to keep DB pressure low on bot bursts.
 */
class SeoController extends Controller
{
    /**
     * Cache TTL for the sitemap, in seconds.
     */
    private const SITEMAP_CACHE_TTL = 3600;

    /**
     * Render a dynamic sitemap.xml covering home, catalog, every movie
     * detail page (lastmod = movies.updated_at), and key static pages.
     *
     * Content-Type is application/xml so search engines parse it properly.
     */
    public function sitemap(): Response
    {
        $xml = Cache::remember('seo.sitemap.v1', self::SITEMAP_CACHE_TTL, function (): string {
            return $this->buildSitemap();
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Render robots.txt with the canonical Sitemap directive.
     * Disallows admin / api / onboarding paths from indexing.
     */
    public function robots(): Response
    {
        $sitemapUrl = url('/sitemap.xml');

        $content = <<<TXT
        User-agent: *
        Allow: /
        Disallow: /admin
        Disallow: /api
        Disallow: /onboarding
        Sitemap: {$sitemapUrl}
        TXT;

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Assemble the sitemap XML string. Kept separate so the cache layer
     * stays a clean one-liner.
     */
    private function buildSitemap(): string
    {
        $now = now()->toIso8601String();

        $urls = [
            [
                'loc' => url('/'),
                'lastmod' => $now,
                'changefreq' => 'daily',
                'priority' => '1.0',
            ],
            [
                'loc' => url('/movies'),
                'lastmod' => $now,
                'changefreq' => 'daily',
                'priority' => '0.9',
            ],
            [
                'loc' => url('/plans'),
                'lastmod' => $now,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ],
            [
                'loc' => url('/onboarding'),
                'lastmod' => $now,
                'changefreq' => 'monthly',
                'priority' => '0.4',
            ],
            [
                'loc' => url('/discover/mood'),
                'lastmod' => $now,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
            [
                'loc' => url('/compare'),
                'lastmod' => $now,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ],
        ];

        Movie::query()
            ->select(['slug', 'updated_at'])
            ->whereNotNull('slug')
            ->orderByDesc('updated_at')
            ->chunkById(500, function ($movies) use (&$urls): void {
                foreach ($movies as $movie) {
                    $urls[] = [
                        'loc' => url('/movie/' . $movie->slug),
                        'lastmod' => optional($movie->updated_at)->toIso8601String() ?? now()->toIso8601String(),
                        'changefreq' => 'weekly',
                        'priority' => '0.8',
                    ];
                }
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "    <url>\n";
            $xml .= '        <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            $xml .= '        <lastmod>' . $url['lastmod'] . "</lastmod>\n";
            $xml .= '        <changefreq>' . $url['changefreq'] . "</changefreq>\n";
            $xml .= '        <priority>' . $url['priority'] . "</priority>\n";
            $xml .= "    </url>\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }
}

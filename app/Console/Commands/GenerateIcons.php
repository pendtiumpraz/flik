<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * flik:pwa:generate-icons
 * --------------------------------------------------------------------------
 * Renders every PWA icon referenced by public/manifest.json + the iOS
 * apple-touch-icon (180px) from a single source logo, using PHP GD so no
 * external tooling (ImageMagick, sharp, node) is required on dev/CI.
 *
 * Audit context: docs/audit/19-mobile-pwa-i18n.md PWA-4 — the manifest
 * declares 8 regular + 2 maskable sizes but only the README existed in
 * public/icons/, so every browser was falling back to /favicon.png and
 * iOS launches showed a blank dark splash. This command fixes that gap.
 *
 * Sizes generated (square PNGs to public/icons/):
 *   icon-72.png, icon-96.png, icon-128.png, icon-144.png, icon-152.png,
 *   icon-192.png, icon-384.png, icon-512.png
 *   icon-maskable-192.png, icon-maskable-512.png  (40% padding safe zone)
 *   apple-touch-icon.png  (180x180)
 *
 * Source resolution:
 *   1. --source override (any PNG / JPG / GIF / WEBP path)
 *   2. public/img/logo.png
 *   3. public/img/flik-logo.png
 *   4. Inline GD placeholder — solid bg with a centred gold "F" via
 *      imagettftext when a TTF font is available, or a plain gold square
 *      when no TTF is present (so the manifest stops 404ing either way).
 *
 * Behaviour when GD is missing entirely: emits the file list + dimensions
 * the operator should produce manually and returns FAILURE (mirrors the
 * sibling GenerateSplashScreens command).
 *
 * Re-runs are idempotent — every file is overwritten on each invocation
 * (no `--force` flag; nothing here is precious).
 */
class GenerateIcons extends Command
{
    protected $signature = 'flik:pwa:generate-icons
                            {--source=public/img/logo.png : Path to source logo (PNG/JPG/GIF/WEBP)}
                            {--bg-color=#0a0a0a : Background hex color}';

    protected $description = 'Generate the PWA icon set (8 regular + 2 maskable + 180px apple-touch) via PHP GD.';

    /**
     * Regular icon sizes (purpose=any in manifest.json).
     *
     * @var array<int, int>
     */
    private const SIZES = [72, 96, 128, 144, 152, 192, 384, 512];

    /**
     * Maskable icon sizes (purpose=maskable in manifest.json).
     * Maskable icons need a 40% safe zone so Android adaptive shapes
     * (circle / square / squircle / teardrop) don't clip the artwork.
     *
     * @var array<int, int>
     */
    private const MASKABLE_SIZES = [192, 512];

    /** Apple-touch-icon size (referenced from layout.blade.php). */
    private const APPLE_TOUCH = 180;

    public function handle(): int
    {
        $outDir = public_path('icons');
        if (! is_dir($outDir) && ! @mkdir($outDir, 0755, true)) {
            $this->error("Failed to create output directory: {$outDir}");
            return self::FAILURE;
        }

        if (! extension_loaded('gd')) {
            $this->warn('ext-gd is not loaded — cannot render icons.');
            $this->line('Produce these PNGs manually and drop them into public/icons/:');
            foreach (self::SIZES as $s) {
                $this->line("  - icon-{$s}.png  ({$s}x{$s})");
            }
            foreach (self::MASKABLE_SIZES as $s) {
                $this->line("  - icon-maskable-{$s}.png  ({$s}x{$s})  (40% safe zone)");
            }
            $this->line('  - apple-touch-icon.png  (180x180)');
            $this->line('');
            $this->line('Install ext-gd (`apt install php-gd` / `pecl install gd`) and re-run.');
            return self::FAILURE;
        }

        $sourceOpt = (string) $this->option('source');
        $bgColor   = (string) $this->option('bg-color');

        $logoPath = $this->resolveLogoPath($sourceOpt);
        [$br, $bg, $bb] = $this->hexToRgb($bgColor);

        $this->info('Generating PWA icons…');
        $this->line('  Output:  ' . $outDir);
        $this->line('  Source:  ' . ($logoPath ?? '(none — synthesised placeholder)'));
        $this->line('  BG:      ' . $bgColor);
        $this->line('');

        // Pre-load the source logo into a GD resource ONCE so each target
        // size resamples from the same in-memory copy. The placeholder path
        // synthesises on demand per size to keep the "F" crisp.
        $srcGd = $logoPath ? $this->loadGdImage($logoPath) : null;

        $generated = 0;

        // ── Regular icons (purpose=any) ─────────────────────────────────
        foreach (self::SIZES as $size) {
            $target = "{$outDir}/icon-{$size}.png";
            try {
                $this->renderSquare(
                    target: $target,
                    size: $size,
                    src: $srcGd,
                    bgRgb: [$br, $bg, $bb],
                    paddingRatio: 0.0,
                    placeholderLetter: $srcGd === null ? 'F' : null,
                );
                $this->info("  ✓ icon-{$size}.png  ({$size}×{$size})");
                $generated++;
            } catch (\Throwable $e) {
                $this->error("  ✗ icon-{$size}.png  — " . $e->getMessage());
            }
        }

        // ── Maskable icons (40% safe-zone padding) ──────────────────────
        // Android adaptive icons crop to ~80% of the canvas; 40% padding
        // (i.e. logo occupies the centre 60%) clears every documented mask
        // shape (circle / squircle / teardrop / pill). See
        // https://web.dev/articles/maskable-icon.
        foreach (self::MASKABLE_SIZES as $size) {
            $target = "{$outDir}/icon-maskable-{$size}.png";
            try {
                $this->renderSquare(
                    target: $target,
                    size: $size,
                    src: $srcGd,
                    bgRgb: [$br, $bg, $bb],
                    paddingRatio: 0.20, // 20% per side → 60% safe-zone logo
                    placeholderLetter: $srcGd === null ? 'F' : null,
                );
                $this->info("  ✓ icon-maskable-{$size}.png  ({$size}×{$size}, maskable)");
                $generated++;
            } catch (\Throwable $e) {
                $this->error("  ✗ icon-maskable-{$size}.png  — " . $e->getMessage());
            }
        }

        // ── Apple touch icon ────────────────────────────────────────────
        $appleTarget = "{$outDir}/apple-touch-icon.png";
        try {
            $this->renderSquare(
                target: $appleTarget,
                size: self::APPLE_TOUCH,
                src: $srcGd,
                bgRgb: [$br, $bg, $bb],
                paddingRatio: 0.0,
                placeholderLetter: $srcGd === null ? 'F' : null,
            );
            $this->info('  ✓ apple-touch-icon.png  (180×180)');
            $generated++;
        } catch (\Throwable $e) {
            $this->error('  ✗ apple-touch-icon.png  — ' . $e->getMessage());
        }

        if ($srcGd) {
            imagedestroy($srcGd);
        }

        $this->line('');
        $this->info("Done. Generated {$generated} icon files.");
        $this->line('Manifest icons map to public/manifest.json and the layout iOS link tags.');
        $this->line('Tip: drop a higher-resolution logo at public/img/logo.png and re-run.');

        return self::SUCCESS;
    }

    /**
     * Pick a source logo. Returns null when nothing is on disk so the
     * caller falls back to the synthesised "F" placeholder — that keeps
     * the manifest icons present (just unbranded) instead of 404ing.
     */
    private function resolveLogoPath(string $override): ?string
    {
        $candidates = array_filter([
            $override,
            'public/img/logo.png',
            'public/img/flik-logo.png',
        ]);

        foreach ($candidates as $rel) {
            // Accept absolute paths from --source, otherwise resolve from base_path().
            $abs = (function () use ($rel): string {
                if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $rel)) {
                    return $rel;
                }
                return base_path($rel);
            })();

            if (is_file($abs) && is_readable($abs)) {
                return $abs;
            }
        }

        return null;
    }

    /**
     * Convert "#RRGGBB" → [r,g,b]. Falls back to dark grey on parse error.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [10, 10, 10];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Core renderer. Creates a square canvas, paints the bg, optionally
     * resamples a source logo into the safe-zone centre, and writes PNG.
     *
     * @param  \GdImage|null  $src                Pre-loaded source GD image, or null for placeholder
     * @param  array{0:int,1:int,2:int}  $bgRgb  Background colour
     * @param  float  $paddingRatio              0.0 = fill canvas, 0.2 = 20% padding each side
     * @param  string|null  $placeholderLetter   Letter to render when $src is null (typically "F")
     */
    private function renderSquare(
        string $target,
        int $size,
        $src,
        array $bgRgb,
        float $paddingRatio,
        ?string $placeholderLetter,
    ): void {
        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            throw new \RuntimeException('imagecreatetruecolor() failed');
        }
        imagealphablending($canvas, true);
        $bg = imagecolorallocate($canvas, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);

        // Inner safe-zone box (after padding).
        $pad = (int) round($size * $paddingRatio);
        $innerSize = max(1, $size - ($pad * 2));

        if ($src !== null) {
            $srcW = imagesx($src);
            $srcH = imagesy($src);
            $ratio = min($innerSize / max(1, $srcW), $innerSize / max(1, $srcH));
            $dstW  = max(1, (int) round($srcW * $ratio));
            $dstH  = max(1, (int) round($srcH * $ratio));
            $dstX  = (int) round(($size - $dstW) / 2);
            $dstY  = (int) round(($size - $dstH) / 2);

            imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        } elseif ($placeholderLetter !== null) {
            // No source logo → render a gold "F" centred in the safe zone.
            // Prefer a system TTF when one's available (imagettftext is
            // sharp at any scale); otherwise fall back to a simple
            // gold-filled rounded rectangle so the icon still has a visible
            // glyph instead of a blank dark square.
            $this->drawPlaceholder($canvas, $size, $pad, $placeholderLetter);
        }

        if (! imagepng($canvas, $target, 6)) {
            imagedestroy($canvas);
            throw new \RuntimeException("imagepng() failed for {$target}");
        }
        imagedestroy($canvas);
    }

    /**
     * Draw a centred letter onto the canvas. Tries a few common TTF font
     * paths first, then falls back to a solid gold square so the icon is
     * never blank.
     *
     * @param  \GdImage  $canvas
     */
    private function drawPlaceholder($canvas, int $size, int $pad, string $letter): void
    {
        $gold = imagecolorallocate($canvas, 197, 165, 90); // #C5A55A — brand gold
        $inner = max(1, $size - ($pad * 2));

        // Candidate font paths (Linux + macOS + Windows). We don't care
        // which one matches — first hit wins. Empty list (or all missing)
        // → fall back to a solid rectangle so the icon still has shape.
        $fontCandidates = array_filter([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
        ], 'is_file');

        $font = $fontCandidates[array_key_first($fontCandidates) ?? -1] ?? null;

        if ($font !== null && function_exists('imagettftext')) {
            $fontSize = (int) round($inner * 0.55);
            // Compute the rendered bounding box so we can centre precisely.
            $bbox = @imagettfbbox($fontSize, 0, $font, $letter);
            if (is_array($bbox)) {
                $textW = $bbox[2] - $bbox[0];
                $textH = $bbox[1] - $bbox[7];
                $x = (int) round(($size - $textW) / 2 - $bbox[0]);
                $y = (int) round(($size + $textH) / 2);
                @imagettftext($canvas, $fontSize, 0, $x, $y, $gold, $font, $letter);
                return;
            }
        }

        // Last-resort fallback — a centred gold rounded square. Not
        // pretty, but the icon is visible & branded (gold on dark).
        $boxPad = (int) round($inner * 0.20);
        imagefilledrectangle(
            $canvas,
            $pad + $boxPad,
            $pad + $boxPad,
            $size - $pad - $boxPad,
            $size - $pad - $boxPad,
            $gold
        );
    }

    /**
     * Load a logo into a GD resource. Supports the four formats GD ships
     * with on every Laravel-supported platform; returns null on anything
     * else (caller falls back to placeholder).
     *
     * @return \GdImage|null
     */
    private function loadGdImage(string $path)
    {
        $info = @getimagesize($path);
        if (! is_array($info)) {
            return null;
        }
        return match ($info[2]) {
            IMAGETYPE_PNG  => imagecreatefrompng($path) ?: null,
            IMAGETYPE_JPEG => imagecreatefromjpeg($path) ?: null,
            IMAGETYPE_GIF  => imagecreatefromgif($path) ?: null,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? (imagecreatefromwebp($path) ?: null) : null,
            default        => null,
        };
    }
}

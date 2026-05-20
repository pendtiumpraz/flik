<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * flik:pwa:generate-splash
 * --------------------------------------------------------------------------
 * Renders iOS apple-touch-startup-image PNGs at every common device size so
 * the PWA launch doesn't flash a blank white screen before the app boots.
 *
 * Strategy: take a logo (defaults to public/icons/icon-512.png, falling back
 * to public/img/flik-logo.png), centre it on a #0a0a0a background, write a
 * PNG at each target dimension. Output → public/icons/splash-*.png.
 *
 * Image backend preference: Intervention Image when installed (uses GD/Imagick
 * under the hood) → plain GD when ext-gd is present → graceful error message
 * with the exact list of files + dimensions the user needs to produce by hand
 * if neither is available.
 */
class GenerateSplashScreens extends Command
{
    protected $signature = 'flik:pwa:generate-splash
                            {--logo= : Path to source logo (default: public/icons/icon-512.png or public/img/flik-logo.png)}
                            {--bg=#0a0a0a : Background hex color}
                            {--force : Overwrite existing splash files}';

    protected $description = 'Generate iOS PWA splash screens (apple-touch-startup-image) for common device sizes.';

    /**
     * Device matrix — keep in sync with the <link rel="apple-touch-startup-image">
     * tags in resources/views/components/layout.blade.php. Sizes use the
     * RENDERED viewport pixels (device-width × device-height × DPR) so the
     * PNGs match Apple's documented requirements.
     *
     * @var array<string, array{0:int,1:int}>
     */
    private const DEVICES = [
        'splash-iphone-se'         => [750, 1334],   // 375 × 667 @2x
        'splash-iphone-14'         => [1170, 2532],  // 390 × 844 @3x
        'splash-iphone-14-pro'     => [1179, 2556],  // 393 × 852 @3x
        'splash-iphone-14-pro-max' => [1290, 2796],  // 430 × 932 @3x
        'splash-ipad-mini'         => [1488, 2266],  // 744 × 1133 @2x
        'splash-ipad-pro-11'       => [1668, 2388],  // 834 × 1194 @2x
        'splash-ipad-pro-12'       => [2048, 2732],  // 1024 × 1366 @2x
    ];

    public function handle(): int
    {
        $outDir = public_path('icons');
        if (! is_dir($outDir) && ! @mkdir($outDir, 0755, true)) {
            $this->error("Failed to create output directory: {$outDir}");
            return self::FAILURE;
        }

        $logoPath = $this->resolveLogoPath();
        $bg       = (string) $this->option('bg');
        $force    = (bool) $this->option('force');

        // Detect image backend ─────────────────────────────────────────────
        $hasIntervention = class_exists(\Intervention\Image\ImageManagerStatic::class)
            || class_exists(\Intervention\Image\ImageManager::class);
        $hasGd = extension_loaded('gd');

        if (! $hasIntervention && ! $hasGd) {
            $this->warn('Neither Intervention Image nor ext-gd is available.');
            $this->line('Generate these PNGs manually and drop them into public/icons/:');
            foreach (self::DEVICES as $name => [$w, $h]) {
                $this->line("  - {$name}.png  ({$w}x{$h})");
            }
            $this->line('');
            $this->line('Tip: install ext-gd or `composer require intervention/image` and re-run.');
            return self::FAILURE;
        }

        if ($logoPath && ! is_readable($logoPath)) {
            $this->warn("Logo path is not readable: {$logoPath}");
            $this->line('Splash screens will render with a solid background only.');
            $logoPath = null;
        }

        $this->info('Generating splash screens…');
        $this->line('  Output: ' . $outDir);
        $this->line('  Logo:   ' . ($logoPath ?? '(none — solid background)'));
        $this->line('  BG:     ' . $bg);
        $this->line('');

        [$br, $bgGr, $bb] = $this->hexToRgb($bg);

        $generated = 0;
        $skipped   = 0;

        foreach (self::DEVICES as $name => [$w, $h]) {
            $target = "{$outDir}/{$name}.png";
            if (! $force && is_file($target)) {
                $this->line("  • {$name}.png — already exists, skipping (use --force to overwrite)");
                $skipped++;
                continue;
            }

            try {
                if ($hasIntervention) {
                    $this->renderWithIntervention($target, $w, $h, $logoPath, $bg);
                } else {
                    $this->renderWithGd($target, $w, $h, $logoPath, $br, $bgGr, $bb);
                }
                $this->info("  ✓ {$name}.png  ({$w}×{$h})");
                $generated++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$name}.png  — " . $e->getMessage());
            }
        }

        $this->line('');
        $this->info("Done. Generated: {$generated}, skipped: {$skipped}.");
        $this->line('Splash screens are wired in resources/views/components/layout.blade.php');
        $this->line('(<link rel="apple-touch-startup-image">). No further wiring required.');

        return self::SUCCESS;
    }

    /**
     * Pick a source logo from the conventional locations.
     */
    private function resolveLogoPath(): ?string
    {
        $override = $this->option('logo');
        if ($override) {
            return (string) $override;
        }
        foreach (['icons/icon-512.png', 'icons/icon-192.png', 'img/flik-logo.png'] as $rel) {
            $path = public_path($rel);
            if (is_file($path)) {
                return $path;
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
     * Render via Intervention Image (works with both v2 and v3 APIs).
     */
    private function renderWithIntervention(string $target, int $w, int $h, ?string $logoPath, string $bg): void
    {
        if (class_exists(\Intervention\Image\ImageManager::class)) {
            // v3 path
            $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
            $canvas  = $manager->create($w, $h)->fill($bg);
            if ($logoPath) {
                $logoSize = (int) round(min($w, $h) * 0.35);
                $logo     = $manager->read($logoPath)->scale(width: $logoSize);
                $canvas->place($logo, 'center');
            }
            $canvas->toPng()->save($target);
            return;
        }

        // v2 fallback
        $img = \Intervention\Image\ImageManagerStatic::canvas($w, $h, $bg);
        if ($logoPath) {
            $logoSize = (int) round(min($w, $h) * 0.35);
            $logo     = \Intervention\Image\ImageManagerStatic::make($logoPath)->resize($logoSize, $logoSize, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            });
            $img->insert($logo, 'center');
        }
        $img->save($target);
    }

    /**
     * Plain GD renderer for installs without Intervention. Loads PNG/JPG/GIF
     * logos transparently; falls back to a solid background if the logo
     * format isn't recognised.
     */
    private function renderWithGd(string $target, int $w, int $h, ?string $logoPath, int $br, int $bgGr, int $bb): void
    {
        $canvas = imagecreatetruecolor($w, $h);
        if ($canvas === false) {
            throw new \RuntimeException('imagecreatetruecolor() failed');
        }
        imagealphablending($canvas, true);
        $bgColor = imagecolorallocate($canvas, $br, $bgGr, $bb);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $bgColor);

        if ($logoPath && is_file($logoPath)) {
            $logo = $this->loadGdImage($logoPath);
            if ($logo !== null) {
                $srcW = imagesx($logo);
                $srcH = imagesy($logo);

                $logoSize = (int) round(min($w, $h) * 0.35);
                $ratio    = min($logoSize / $srcW, $logoSize / $srcH);
                $dstW     = (int) round($srcW * $ratio);
                $dstH     = (int) round($srcH * $ratio);
                $dstX     = (int) round(($w - $dstW) / 2);
                $dstY     = (int) round(($h - $dstH) / 2);

                imagecopyresampled($canvas, $logo, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
                imagedestroy($logo);
            }
        }

        if (! imagepng($canvas, $target, 6)) {
            imagedestroy($canvas);
            throw new \RuntimeException("imagepng() failed for {$target}");
        }
        imagedestroy($canvas);
    }

    /**
     * Load a logo into a GD resource from PNG/JPG/GIF. Returns null on
     * unsupported formats so the caller can fall back to a solid splash.
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

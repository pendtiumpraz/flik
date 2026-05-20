FLiK PWA Icon Assets
====================

This directory holds the PNGs referenced by:
  - public/manifest.json (icons[] + shortcuts[] + screenshots[])
  - resources/views/components/layout.blade.php (apple-touch-icon + splash)

Required files (drop them here):

Regular icons (any-purpose):
  - icon-72.png    72x72
  - icon-96.png    96x96
  - icon-128.png   128x128
  - icon-144.png   144x144
  - icon-152.png   152x152
  - icon-192.png   192x192
  - icon-384.png   384x384
  - icon-512.png   512x512

Maskable icons (Android adaptive shape — keep logo within the inner 80%
safe zone so the OS can mask to a circle/squircle without clipping):
  - icon-maskable-192.png  192x192
  - icon-maskable-512.png  512x512

Apple touch icon:
  - apple-touch-icon.png   180x180

iOS splash screens (auto-generated via `php artisan flik:pwa:generate-splash`):
  - splash-iphone-se.png         750x1334
  - splash-iphone-14.png         1170x2532
  - splash-iphone-14-pro.png     1179x2556
  - splash-iphone-14-pro-max.png 1290x2796
  - splash-ipad-mini.png         1488x2266
  - splash-ipad-pro-11.png       1668x2388
  - splash-ipad-pro-12.png       2048x2732

Generation tips
---------------

The simplest path is to use the included artisan command:

  php artisan flik:pwa:generate-splash --force

It will:
  1. Look for a source logo in this order:
       public/icons/icon-512.png
       public/icons/icon-192.png
       public/img/flik-logo.png
  2. Render each splash size on a #0a0a0a background.

For the regular icons, any image processor (Figma export, ImageMagick,
RealFaviconGenerator.net) works. Source from a 1024x1024 master to avoid
upscaling artifacts.

If files are missing
--------------------
The site still works — the missing icons just won't render in the install
UI or as the home-screen icon. Browsers gracefully fall back to the
favicon. Splash screens degrade to a blank dark launch screen.

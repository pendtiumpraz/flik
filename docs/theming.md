# Theming

FLiK is dark-by-default. The dark palette uses gold `#C5A55A` highlights on
a near-black `#0a0a0a` background. A future light theme would invert the
neutrals while keeping the gold accent.

## Dark mode wiring (current)

The `<html>` element receives `class="dark"` (via Alpine in
`resources/views/components/layout.blade.php`):

```html
<html x-data="{ darkMode: localStorage.getItem('theme') !== 'light' }"
      :class="darkMode ? 'dark' : 'light'"
      x-init="$watch('darkMode', val => localStorage.setItem('theme', val ? 'dark' : 'light'))">
```

Tailwind picks up `dark:` variants from that root class. The default state
is `dark = true`, so a fresh visitor always sees the gold-on-black theme.

## Where colors live today

Most of the chrome uses inline `style="..."` with literal hex values:

- `#0a0a0a` — page background
- `#141414` / `#1a1a1a` — surface / card background
- `#C5A55A` / `#E8D5A3` — gold gradient endpoints (CTAs, active states)
- `text-gray-300/400/500` — Tailwind neutrals (these need light variants)

## Future light-mode (not yet implemented)

When the time comes, lift the literal hex values to CSS variables on
`:root` and override them under `.light`. Recommended naming:

```css
:root, .dark {
    --flik-bg:        #0a0a0a;
    --flik-surface:   #141414;
    --flik-surface-2: #1a1a1a;
    --flik-text:      #ffffff;
    --flik-text-soft: #a3a3a3;
    --flik-gold:      #C5A55A;
    --flik-gold-soft: #E8D5A3;
    --flik-border:    rgba(197,165,90,0.15);
}
.light {
    --flik-bg:        #ffffff;
    --flik-surface:   #f7f7f7;
    --flik-surface-2: #ececec;
    --flik-text:      #0a0a0a;
    --flik-text-soft: #4b4b4b;
    /* gold stays the same — it's the brand accent */
    --flik-border:    rgba(197,165,90,0.30);
}
```

Then swap `style="background: #0a0a0a"` → `style="background: var(--flik-bg)"`
in chrome (header, footer, layout, cards). Tailwind colors should pick up
the existing `dark:` modifier when the root class flips.

## PWA theme color

The `theme-color` meta lives in the head twice — one per color scheme:

```html
<meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: light)">
```

When a real light theme ships, change the second value to the chosen light
chrome color (likely the same `#0a0a0a` or pure white — TBD by design).
The PWA `manifest.json` `theme_color` should stay `#0a0a0a` because the
PWA shell continues to render the dark UI even when the OS prefers light.

## Apple touch icon + splash

iOS doesn't honour the manifest `theme_color`. The splash screens
(`apple-touch-startup-image`) are generated with `#0a0a0a` background by
`php artisan flik:pwa:generate-splash`. Re-run with `--bg=#ffffff` if a
light splash is ever needed.

## Open TODOs

- Migrate inline hex colors in `layout.blade.php`, `header.blade.php`, and
  `components/*` to CSS variables.
- Add `prefers-color-scheme: light` to the Alpine root-state initializer so
  first-time visitors with OS-level light preference default to light.
- Tailwind `darkMode: 'class'` is already implicit via Vite preset — verify
  before shipping.

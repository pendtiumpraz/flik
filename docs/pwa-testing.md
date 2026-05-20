# PWA Testing Checklist

How to verify the FLiK PWA shell on real devices and in dev tools.

## 1. Chrome / Edge DevTools

1. Open the site (recommended over HTTPS — `http://localhost` is also treated
   as a secure origin for SW + install).
2. Open **DevTools → Application**.
3. **Manifest** panel — every field should be populated and the icon preview
   should show no broken images. Required for "Installable":
   - `name`, `short_name`
   - `start_url`, `scope`
   - `display: standalone`
   - `icons` array with at least one 192x192 and one 512x512 PNG
   - `theme_color`, `background_color`
4. **Service Workers** panel — `sw.js` should show **activated and running**.
   - Test offline by ticking the **Offline** checkbox and reloading a page
     you've already visited → the cached version should still render.
   - Force-refresh and visit a page you haven't visited → `/offline.html`
     should render with the gold-themed offline page.
5. **Storage** panel — the `flik-static-v1` and `flik-runtime-v3` caches
   should be populated. Use **Clear storage** to test cold starts.

## 2. Lighthouse PWA audit

In DevTools → **Lighthouse**, run a **Mobile / Progressive Web App** audit.
Target: **all green checks**. Common pitfalls and where to fix them:

| Audit                                  | Where to fix                                         |
|----------------------------------------|------------------------------------------------------|
| Web app manifest meets install criteria | `public/manifest.json`                              |
| Service worker controls page / `start_url` | `public/sw.js` install + activate handlers       |
| Themed omnibox                         | `<meta name="theme-color">` in `layout.blade.php`    |
| Splash screen configured               | `apple-touch-startup-image` `<link>`s + 512 icon     |
| Maskable icon                          | `manifest.json` → `purpose: "maskable"` entry        |
| Apple touch icon                       | `<link rel="apple-touch-icon" sizes="180x180">`      |
| Offline page available                 | `public/offline.html`                                |

## 3. Android Chrome — install banner

1. Visit the site on Android Chrome (mobile data or Wi-Fi, HTTPS).
2. Browse for 30+ seconds and ensure the install banner appears.
3. Confirm the install — the icon should appear in the launcher with the
   right name + masked shape (rounded square / squircle).
4. Launch from the launcher — no browser chrome should be visible.
5. Verify `appinstalled` was tracked: `SELECT * FROM pwa_installs ORDER BY id DESC LIMIT 1;`

## 4. iOS Safari — Add to Home Screen

iOS does NOT support `beforeinstallprompt`. Verify the fallback path:

1. Visit the site in Safari on iOS (not Chrome / Firefox iOS — they share
   the same WebKit engine but the share sheet flow is slightly different).
2. After 30 s the install banner should show the **iOS instructions**
   variant ("Tap Share → Add to Home Screen").
3. Follow the instructions — tap the **Share** button in the toolbar, scroll
   to **Add to Home Screen**, tap **Add**.
4. Launch from the home screen — verify:
   - No Safari chrome (black status bar, no URL bar).
   - The configured splash screen (from `apple-touch-startup-image`) shows
     during launch instead of a white flash.
   - The mobile bottom-nav respects the home-indicator safe area.

## 5. Mobile bottom-nav

- Resize a desktop window below the `lg` breakpoint (1024 px) — the bar
  should appear at the bottom.
- Above `lg` — the bar should be hidden.
- Tapping a tab navigates and the active tab gains the gold tint + dot
  indicator.
- The notifications tab shows a badge when `unreadNotificationCount() > 0`.

## 6. Install prompt throttling

- Visit the site, dismiss the install banner.
- Reload — the banner should NOT reappear (14-day cooldown).
- Clear `localStorage.pwa_install_dismissed_at` in DevTools and reload —
  the banner should reappear after the 30 s warm-up.

## 7. Service-worker push compatibility (regression check)

The push handler in `sw.js` MUST keep working — `swarm 1.5 / DEV #5`.
- Trigger a test push from the admin: `php artisan flik:push:test --user=1`
- Verify the notification appears.
- Click it — the SW should focus the right tab and navigate to `action_url`.

## 8. Splash screen generation

```bash
php artisan flik:pwa:generate-splash --force
ls -la public/icons/splash-*.png
```

Manually inspect each PNG to confirm the logo is centered and the
background is `#0a0a0a`.

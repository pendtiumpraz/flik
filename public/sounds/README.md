# Notification sounds

This directory holds short UI sound effects served by the admin notification
bell (`resources/views/components/admin/notification-bell.blade.php`).

## Expected files

- `notification-chime.mp3` — soft chime played when a new admin notification
  arrives. Suggested format: 16-bit MP3, ~200ms, < 10 KB, peak around -6 dBFS.

## Fallback behaviour

If `notification-chime.mp3` is missing the bell falls back to a small
WebAudio-generated 880 Hz sine wave (~120 ms) defined in
`resources/js/admin-notifications.js`. No 404 is raised — the audio element
fails to play silently and the JS catches the rejected promise.

Drop a real chime in here whenever a final asset is approved; no further code
changes are required.

## Muting

Users can mute the chime via the speaker icon in the bell dropdown. The
preference is persisted in `localStorage` under the key `flik.admin.notif.muted`.

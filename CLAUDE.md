# Oxworks Smart TV Digital Signage Player

## Project Overview
A lightweight, zero-database PHP + HTML/CSS/JS digital signage player and management dashboard hosted on a Plesk subdomain (`https://media.oxworks.com.au`). Loops mixed media (MP4/WebM/OGG videos and JPG/PNG/WebP/GIF images) full-screen on a Smart TV web browser without native media controls or loading bars.

## Hosting & Environment Constraints
- **Server:** Plesk (Apache, usually behind nginx) with PHP 8.x.
- **Database:** None. Flat-file `media/settings.json`; deploy by dropping files into the subdomain root.
- **Client device:** Smart TV built-in browser (limited CPU/RAM, strict autoplay policies, aggressive sleep timers, often an old Chromium). Avoid very new JS APIs without a fallback.
- **No build step:** vanilla PHP/HTML/CSS/JS, uploadable via Plesk File Manager. CDN scripts are allowed but must load async and be optional (the page must work if the CDN is blocked).

## Directory Structure
```text
media.oxworks.com.au/
├── index.php    # Full-screen TV player + JSON playlist API (?fetch=1)
├── admin.php    # Password-protected upload / reorder / rename / settings dashboard
├── common.php   # Shared helpers: settings load/save, media listing, name sanitising (404s if opened directly)
├── media/       # Uploaded media + settings.json + auto-generated .htaccess (no script execution, no listing)
├── README.md
└── CLAUDE.md
```

## settings.json
```json
{
  "fade_duration": 1000,
  "mute_videos": true,
  "order": ["01_slide.jpg", "02_video.mp4"],
  "items": { "01_slide.jpg": 8000 }
}
```
- `loadSettings()` tolerates a missing, empty, corrupted or legacy (flat `{"file": ms}`) file and clamps values.
- `saveSettings()` writes atomically (temp file + rename) so the TV never reads half a file.
- Files on disk that aren't in `order` are appended alphabetically. Entries in `order`/`items` with no file are ignored or pruned.

## Player (`index.php`) — how it works
- Dual-layer A/B buffer (`img-0/1`, `vid-0/1`). The incoming layer fades in **on top** (rising `z-index`) while the outgoing one stays fully opaque underneath, so there's no dip to black. The outgoing layer is hidden, and videos unloaded (`pause()` / `removeAttribute('src')` / `load()`), only after the fade, and **only if that element hasn't been reclaimed in the meantime** (`claim()` cancels pending cleanup). Don't remove this guard: without it a reused video element gets unloaded mid-play and the screen freezes.
- Every item gets a `token`. Stale event handlers and timers compare against it, and `finish()` guarantees exactly one hand-off per item. All control flow goes through `later()` / `every()` / `finish()`; never call `playNext()` directly from an event.
- Watchdogs: 20s load timeout per item, 15s no-progress stall detector on playing videos.
- Videos hand off `min(fade, duration/3)` before the end.
- Polls `?fetch=1` (no-store) at the end of every loop. An empty playlist fades to black. A fetch failure keeps the last playlist.
- Reloads itself at the end of a loop when `index.php`/`common.php` change (version = file mtimes), and after 24h uptime.
- If `mute_videos` is false and unmuted autoplay is blocked: plays muted, shows the "Click screen to enable audio" toast (auto-hides after 10s, hides on click), and unmutes on click.
- Screen Wake Lock is re-requested on visibility change and on click.

## Admin (`admin.php`) — how it works
- Session login with `hash_equals`, `session_regenerate_id`, HttpOnly/SameSite=Lax/Secure cookie, per-IP lockout (8 fails / 15 min, state in the system temp dir).
- Every state-changing action is a **POST with a CSRF token** that **redirects afterwards** (PRG), so a refresh never resubmits. Actions: `upload`, `save`, `delete`.
- Upload: extension whitelist + `finfo` MIME check, sanitised names, never overwrites (`name-2.ext`), per-file error messages, detects requests over `post_max_size` (which PHP otherwise drops silently). Client-side size pre-check too.
- Rename: only the **base name** is editable. The extension is fixed, so files can't be renamed to `.php` or lose their extension. Two-step temp staging (`.tmp_<hex>_<name>`) lets swaps work. Leftovers older than 5 minutes are recovered on the next admin load. Durations are remapped in a single pass.
- Save merges with current state: rows that vanished (stale tab) are ignored, and files uploaded meanwhile keep their place at the end.
- Reordering: ▲/▼ buttons (always work) plus SortableJS (jsDelivr, loaded async) for mouse and touch drag. Unsaved-changes badge and `beforeunload` warning.
- Phone layout: rows become cards under 640px.

## Testing notes
- Local: `php -S 127.0.0.1:8765` in the project root, then drive it with Playwright. Headless Chromium can't play H.264, so use VP8/VP9 WebM fixtures (`ffmpeg -f lavfi -i testsrc=duration=3:size=640x360 -c:v libvpx clip.webm`).
- Regression cases worth keeping: video → 500ms image → video with a 1000ms fade (old freeze bug), a hung video request (watchdog), a file deleted mid-loop, an empty library, a corrupted settings.json, an A↔B rename swap, and refreshing after a save.

## Possible future work
- Scheduling (date/time windows per item).
- Optional `object-fit: cover` toggle in settings.
- Per-TV playlists via `?screen=` parameter.

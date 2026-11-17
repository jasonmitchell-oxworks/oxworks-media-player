<?php
require __DIR__ . '/common.php';
ensureMediaDir();

// Bump when index.php/common.php change so open TVs reload themselves after a deploy.
$appVersion = max(@filemtime(__FILE__) ?: 0, @filemtime(__DIR__ . '/common.php') ?: 0);

// JSON playlist API, polled by the player at the end of every loop.
if (isset($_GET['fetch'])) {
    $settings = loadSettings();
    $playlist = [];

    foreach (orderedMediaFiles($settings) as $file) {
        if (isVideoFile($file)) {
            $playlist[] = ['type' => 'video', 'src' => mediaUrl($file)];
        } else {
            $playlist[] = [
                'type'     => 'image',
                'src'      => mediaUrl($file),
                'duration' => $settings['items'][$file] ?? DEFAULT_IMAGE_MS,
            ];
        }
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode([
        'version'       => $appVersion,
        'fade_duration' => $settings['fade_duration'],
        'mute_videos'   => $settings['mute_videos'],
        'playlist'      => $playlist,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oxworks Media Player</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, html {
            width: 100%;
            height: 100%;
            background-color: #000;
            overflow: hidden;
            cursor: none;
        }
        #player-container {
            position: relative;
            width: 100vw;
            height: 100vh;
            background-color: #000;
        }
        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: contain; /* Change to 'cover' to fill screen without black bars */
            opacity: 0;
            z-index: 1;
            transition: opacity 1s ease-in-out;
            pointer-events: none;
        }
        .slide.active { opacity: 1; }

        #audio-toast {
            position: fixed;
            right: 3vw;
            bottom: 4vh;
            z-index: 2147483647;
            padding: 0.8em 1.2em;
            border-radius: 0.6em;
            background: rgba(0, 0, 0, 0.72);
            color: #fff;
            font: 600 clamp(14px, 1.6vw, 28px)/1.3 system-ui, sans-serif;
            opacity: 0;
            transform: translateY(0.5em);
            transition: opacity 0.4s ease, transform 0.4s ease;
            pointer-events: none;
        }
        #audio-toast.show { opacity: 1; transform: none; }
    </style>
</head>
<body>

<div id="player-container">
    <img id="img-0" class="slide" alt="">
    <img id="img-1" class="slide" alt="">
    <video id="vid-0" class="slide" muted playsinline preload="auto"></video>
    <video id="vid-1" class="slide" muted playsinline preload="auto"></video>
</div>
<div id="audio-toast" role="status">🔊 Click screen to enable audio</div>

<script>
(function () {
    'use strict';

    const APP_VERSION        = <?= (int)$appVersion ?>;
    const LOAD_TIMEOUT_MS    = 20000;            // give up on an item that never loads
    const STALL_TIMEOUT_MS   = 15000;            // give up on a video that stops progressing
    const EMPTY_RETRY_MS     = 5000;
    const MAX_UPTIME_MS      = 24 * 60 * 60 * 1000; // full reload once a day to shed any browser leaks
    const TOAST_HIDE_MS      = 10000;

    const bootTime = Date.now();
    let fadeDurationMs = 1000;
    let muteVideos = true;
    let playlist = [];
    let currentIndex = 0;
    let activeLayer = 0;
    let currentElement = null;
    let zCounter = 10;
    let token = 0;                 // identifies the item currently in charge; stale callbacks compare against it
    let reloadPending = false;

    const allSlides = document.querySelectorAll('.slide');
    const toast = document.getElementById('audio-toast');
    const layers = [
        { img: document.getElementById('img-0'), vid: document.getElementById('vid-0') },
        { img: document.getElementById('img-1'), vid: document.getElementById('vid-1') }
    ];

    // ---------- helpers ----------

    function applyFadeDuration(ms) {
        fadeDurationMs = Math.max(0, ms);
        allSlides.forEach(el => {
            el.style.transition = 'opacity ' + fadeDurationMs + 'ms ease-in-out';
        });
    }

    // Run fn after ms, but only if the same item is still in charge.
    function later(myToken, fn, ms) {
        return setTimeout(() => { if (myToken === token) fn(); }, ms);
    }

    // setInterval that stops itself once a newer item takes over.
    function every(myToken, fn, ms) {
        const id = setInterval(() => {
            if (myToken !== token) { clearInterval(id); return; }
            fn();
        }, ms);
        return id;
    }

    function bind(el, handlers) {
        unbind(el);
        el._handlers = handlers;
        Object.keys(handlers).forEach(ev => el.addEventListener(ev, handlers[ev]));
    }

    function unbind(el) {
        if (!el._handlers) return;
        Object.keys(el._handlers).forEach(ev => el.removeEventListener(ev, el._handlers[ev]));
        el._handlers = null;
    }

    function unloadVideo(vid) {
        unbind(vid);
        vid.pause();
        vid.removeAttribute('src');
        vid.load(); // releases the hardware decoder on TV browsers
    }

    // Claim an element for a new item: cancel any pending fade-out/unload aimed at it.
    function claim(el) {
        clearTimeout(el._cleanupTimer);
        el._cleanupTimer = null;
        unbind(el);
    }

    // New layer fades in ON TOP while the old one stays fully visible underneath (no dip to black).
    // The old layer is only hidden/unloaded after the fade, and only if nothing has reclaimed it meanwhile.
    function crossfadeTo(nextEl) {
        const prevEl = currentElement;
        claim(nextEl);
        nextEl.style.zIndex = ++zCounter;
        nextEl.classList.add('active');
        currentElement = nextEl;

        if (prevEl && prevEl !== nextEl) {
            prevEl._cleanupTimer = setTimeout(() => {
                if (prevEl === currentElement) return;
                prevEl.classList.remove('active');
                if (prevEl.tagName === 'VIDEO') {
                    prevEl._cleanupTimer = setTimeout(() => {
                        if (prevEl !== currentElement) unloadVideo(prevEl);
                    }, fadeDurationMs + 100);
                }
            }, fadeDurationMs + 50);
        }
    }

    function fadeToBlack() {
        if (!currentElement) return;
        const el = currentElement;
        currentElement = null;
        el.classList.remove('active');
        if (el.tagName === 'VIDEO') {
            el._cleanupTimer = setTimeout(() => unloadVideo(el), fadeDurationMs + 100);
        }
    }

    let toastTimer = null;
    function showAudioToast() {
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(hideAudioToast, TOAST_HIDE_MS);
    }
    function hideAudioToast() {
        clearTimeout(toastTimer);
        toast.classList.remove('show');
    }

    // ---------- playlist ----------

    async function fetchPlaylist() {
        try {
            const response = await fetch('?fetch=1&t=' + Date.now(), { cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();
            if (typeof data.version === 'number' && data.version !== APP_VERSION) {
                reloadPending = true;
            }
            if (typeof data.fade_duration === 'number') applyFadeDuration(data.fade_duration);
            if (typeof data.mute_videos === 'boolean') muteVideos = data.mute_videos;
            if (Array.isArray(data.playlist)) playlist = data.playlist; // empty list = admin removed everything
        } catch (err) {
            // Network blip: keep playing the last known playlist.
            console.error('Error fetching media list:', err);
        }
    }

    async function playNext() {
        const myToken = ++token;

        if (currentIndex >= playlist.length) {
            currentIndex = 0;
            if (reloadPending || Date.now() - bootTime > MAX_UPTIME_MS) {
                location.reload();
                return;
            }
            await fetchPlaylist();
            if (myToken !== token) return;
            if (reloadPending) { location.reload(); return; }
        }

        if (playlist.length === 0) {
            fadeToBlack();
            later(myToken, playNext, EMPTY_RETRY_MS);
            return;
        }

        const item = playlist[currentIndex];
        currentIndex++;

        activeLayer = activeLayer === 0 ? 1 : 0;
        const targetLayer = layers[activeLayer];

        // Exactly one hand-off per item, however many events/timeouts fire.
        let done = false;
        const finish = (delay, reason) => {
            if (done || myToken !== token) return;
            done = true;
            if (reason) console.warn(reason, item.src);
            later(myToken, playNext, delay);
        };

        const loadTimer = later(myToken, () => finish(0, 'Timed out loading, skipping:'), LOAD_TIMEOUT_MS);

        if (item.type === 'image') {
            const img = targetLayer.img;
            claim(img);

            const show = () => {
                if (myToken !== token || done) return;
                clearTimeout(loadTimer);
                crossfadeTo(img);
                finish(item.duration);
            };
            const decodeThenShow = () => {
                if (img.decode) img.decode().catch(() => {}).then(show);
                else show();
            };

            if (img.getAttribute('src') === item.src && img.complete && img.naturalWidth) {
                decodeThenShow(); // same image already loaded on this layer; load won't necessarily fire again
            } else {
                bind(img, {
                    load: decodeThenShow,
                    error: () => { clearTimeout(loadTimer); finish(1000, 'Failed to load image, skipping:'); }
                });
                img.src = item.src;
            }

        } else if (item.type === 'video') {
            const vid = targetLayer.vid;
            claim(vid);
            vid.loop = false;
            vid.playsInline = true;
            vid.muted = muteVideos;

            let started = false;
            let lastTime = -1;
            let lastProgressAt = Date.now();

            const beginPlaying = () => {
                if (myToken !== token || done) return;
                clearTimeout(loadTimer);
                crossfadeTo(vid);
                // crossfadeTo() unbinds; re-attach the in-play handlers.
                bind(vid, handlers);
                lastProgressAt = Date.now();
                every(myToken, () => {
                    if (done) return;
                    if (vid.currentTime !== lastTime) {
                        lastTime = vid.currentTime;
                        lastProgressAt = Date.now();
                    } else if (Date.now() - lastProgressAt > STALL_TIMEOUT_MS) {
                        finish(0, 'Video stalled, skipping:');
                    }
                }, 2000);
            };

            const startPlayback = () => {
                if (started || myToken !== token) return;
                started = true;
                vid.play().then(beginPlaying).catch(e => {
                    if (myToken !== token || done) return;
                    if (!vid.muted) {
                        console.warn('Unmuted autoplay blocked; playing muted until the screen is clicked.');
                        vid.muted = true;
                        showAudioToast();
                        vid.play().then(beginPlaying).catch(err2 => finish(1000, 'Playback failed, skipping: ' + err2));
                    } else {
                        finish(1000, 'Autoplay blocked, skipping: ' + e);
                    }
                });
            };

            const handlers = {
                loadeddata: startPlayback,
                canplay: startPlayback,
                timeupdate: () => {
                    if (done || myToken !== token) return;
                    const d = vid.duration;
                    if (!isFinite(d) || d <= 0) return;
                    // Clips shorter than the fade still get to play: lead-in is capped at a third of the clip.
                    const lead = Math.min(fadeDurationMs / 1000, d / 3);
                    if (d - vid.currentTime <= lead) finish(0);
                },
                ended: () => finish(0),
                error: () => { clearTimeout(loadTimer); finish(1000, 'Video format/network error, skipping:'); }
            };

            bind(vid, handlers);
            vid.src = item.src;
            vid.load();

        } else {
            clearTimeout(loadTimer);
            finish(0, 'Unknown item type, skipping:');
        }
    }

    // ---------- audio unlock, wake lock ----------

    document.addEventListener('click', () => {
        hideAudioToast();
        if (!muteVideos && currentElement && currentElement.tagName === 'VIDEO') {
            currentElement.muted = false;
        }
        requestWakeLock();
    });

    let wakeLock = null;
    async function requestWakeLock() {
        if (!('wakeLock' in navigator) || (wakeLock && !wakeLock.released)) return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
        } catch (err) {
            console.warn('Wake Lock request failed:', err);
        }
    }
    requestWakeLock();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') requestWakeLock();
    });

    (async () => {
        await fetchPlaylist();
        playNext();
    })();
})();
</script>
</body>
</html>

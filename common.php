<?php
// Shared helpers for index.php and admin.php. Not meant to be opened directly.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

const MEDIA_DIR      = __DIR__ . '/media/';
const MEDIA_URL      = 'media/';
const SETTINGS_FILE  = MEDIA_DIR . 'settings.json';
const IMAGE_EXTS     = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const VIDEO_EXTS     = ['mp4', 'webm', 'ogg'];
const DEFAULT_IMAGE_MS = 8000;
const DEFAULT_FADE_MS  = 1000;

function ensureMediaDir(): void
{
    if (!is_dir(MEDIA_DIR)) {
        @mkdir(MEDIA_DIR, 0755, true);
    }
    // Stop anything uploaded/renamed into media/ from ever being executed, and hide the folder listing.
    $ht = MEDIA_DIR . '.htaccess';
    if (is_dir(MEDIA_DIR) && !file_exists($ht)) {
        @file_put_contents($ht, implode("\n", [
            'Options -Indexes',
            '<FilesMatch "\.(php\d?|phtml|phar|pl|py|cgi|sh|htaccess)$">',
            '    Require all denied',
            '</FilesMatch>',
            '<IfModule mod_php.c>',
            '    php_flag engine off',
            '</IfModule>',
            '',
        ]));
    }
}

function mediaExt(string $file): string
{
    return strtolower(pathinfo($file, PATHINFO_EXTENSION));
}

function isVideoFile(string $file): bool
{
    return in_array(mediaExt($file), VIDEO_EXTS, true);
}

function isMediaName(string $file): bool
{
    return $file !== ''
        && $file[0] !== '.'                       // skips .htaccess, .tmp_ staging leftovers, etc.
        && $file === basename($file)
        && in_array(mediaExt($file), array_merge(IMAGE_EXTS, VIDEO_EXTS), true);
}

/** All playable media files currently in media/ (unordered). */
function listMediaFiles(): array
{
    $entries = is_dir(MEDIA_DIR) ? @scandir(MEDIA_DIR) : false;
    if (!is_array($entries)) {
        return [];
    }
    $out = [];
    foreach ($entries as $f) {
        if (isMediaName($f) && is_file(MEDIA_DIR . $f)) {
            $out[] = $f;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** Reads settings.json, tolerating a missing, empty, corrupted or legacy (flat name=>ms) file. */
function loadSettings(): array
{
    $raw = is_file(SETTINGS_FILE) ? json_decode((string)@file_get_contents(SETTINGS_FILE), true) : null;
    if (!is_array($raw)) {
        $raw = [];
    }

    if (isset($raw['items']) && is_array($raw['items'])) {
        $items = $raw['items'];
    } else {
        // Legacy format was a flat {"file.jpg": 8000} map.
        $items = array_filter($raw, fn($v, $k) => is_string($k) && isMediaName($k) && is_numeric($v), ARRAY_FILTER_USE_BOTH);
    }
    $cleanItems = [];
    foreach ($items as $name => $ms) {
        if (is_string($name) && is_numeric($ms)) {
            $cleanItems[$name] = max(500, (int)$ms);
        }
    }

    $order = (isset($raw['order']) && is_array($raw['order']))
        ? array_values(array_filter($raw['order'], 'is_string'))
        : [];

    return [
        'fade_duration' => isset($raw['fade_duration']) && is_numeric($raw['fade_duration'])
            ? max(0, min(10000, (int)$raw['fade_duration'])) : DEFAULT_FADE_MS,
        'mute_videos'   => isset($raw['mute_videos']) ? (bool)$raw['mute_videos'] : true,
        'order'         => $order,
        'items'         => $cleanItems,
    ];
}

/** Atomic write so the TV never reads a half-written file. */
function saveSettings(array $s): bool
{
    $data = json_encode([
        'fade_duration' => (int)$s['fade_duration'],
        'mute_videos'   => (bool)$s['mute_videos'],
        'order'         => array_values($s['order']),
        'items'         => (object)$s['items'],   // stays {} not [] when empty
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $tmp = MEDIA_DIR . '.settings.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, SETTINGS_FILE)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Files in saved order, with anything new appended alphabetically. */
function orderedMediaFiles(array $settings): array
{
    $files = listMediaFiles();
    $present = array_flip($files);
    $ordered = [];
    foreach ($settings['order'] as $f) {
        if (isset($present[$f]) && !isset($ordered[$f])) {
            $ordered[$f] = true;
        }
    }
    foreach ($files as $f) {
        if (!isset($ordered[$f])) {
            $ordered[$f] = true;
        }
    }
    return array_keys($ordered);
}

/** Lowercases nothing; keeps letters, digits, dot, dash, underscore. Strips leading dots. */
function sanitizeBaseName(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim(ltrim($name, '.'), '_-.');
}

/** Returns "base.ext", or "base-2.ext", "base-3.ext"... avoiding existing files and $taken names. */
function uniqueName(string $base, string $ext, array $taken = []): string
{
    $candidate = "$base.$ext";
    $n = 2;
    while (file_exists(MEDIA_DIR . $candidate) || isset($taken[strtolower($candidate)])) {
        $candidate = "$base-$n.$ext";
        $n++;
    }
    return $candidate;
}

function mediaUrl(string $file): string
{
    $mtime = @filemtime(MEDIA_DIR . $file) ?: 0;
    return MEDIA_URL . rawurlencode($file) . '?v=' . $mtime;
}

<?php
// TV Display admin dashboard.
$PASSWORD = 'Oxworks2026!'; // <-- CHANGE THIS PASSWORD

require __DIR__ . '/common.php';
ensureMediaDir();

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'httponly' => true,
    'secure'   => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** POST-redirect-GET: refreshing the page never re-submits an upload/save/delete. */
function redirectWith(string $type, array $lines): void
{
    $_SESSION['flash'] = ['type' => $type, 'lines' => $lines];
    header('Location: admin.php', true, 303);
    exit;
}

function iniBytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

function prettyBytes(int $b): string
{
    if ($b <= 0) return 'unlimited';
    foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
        if ($b >= $size) return rtrim(rtrim(number_format($b / $size, 1), '0'), '.') . ' ' . $unit;
    }
    return $b . ' B';
}

function uploadErrorText(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'too big for the server upload limit (' . prettyBytes(iniBytes(ini_get('upload_max_filesize'))) . ')';
        case UPLOAD_ERR_PARTIAL:    return 'only partially uploaded, try again';
        case UPLOAD_ERR_NO_TMP_DIR: return 'server has no temp folder configured';
        case UPLOAD_ERR_CANT_WRITE: return 'server could not write the file to disk';
        case UPLOAD_ERR_EXTENSION:  return 'blocked by a PHP extension';
        default:                    return 'upload failed (error ' . $code . ')';
    }
}

// ---------- login throttling (per IP, stored outside the web root) ----------

const MAX_LOGIN_FAILS = 8;
const LOCKOUT_SECONDS = 900;

function throttleFile(): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/oxworks_tv_login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
}

function throttleState(): array
{
    $s = @json_decode((string)@file_get_contents(throttleFile()), true);
    if (!is_array($s) || (time() - (int)($s['first'] ?? 0)) > LOCKOUT_SECONDS) {
        return ['count' => 0, 'first' => time()];
    }
    return $s;
}

// ---------- auth ----------

$loginError = '';
if (empty($_SESSION['logged_in']) && isset($_POST['password'])) {
    $t = throttleState();
    if ($t['count'] >= MAX_LOGIN_FAILS) {
        $loginError = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif (hash_equals($PASSWORD, (string)$_POST['password'])) {
        @unlink(throttleFile());
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: admin.php', true, 303);
        exit;
    } else {
        $t['count']++;
        @file_put_contents(throttleFile(), json_encode($t), LOCK_EX);
        sleep(1);
        $loginError = 'Incorrect password.';
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (empty($_SESSION['logged_in'])): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Display Admin Login</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #111; color: #fff; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }
        form { background: #222; padding: 2rem; border-radius: 8px; width: 100%; max-width: 320px; }
        input { width: 100%; padding: 10px; margin: 10px 0; border-radius: 4px; border: 1px solid #444; background: #333; color: #fff; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; padding: 10px; background: #0070f3; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 16px; }
        .err { color: #ff5555; font-size: 0.9rem; }
    </style>
</head>
<body>
    <form method="POST" action="admin.php">
        <h2 style="margin-top:0;">TV Display Login</h2>
        <?php if ($loginError !== ''): ?><p class="err"><?= h($loginError) ?></p><?php endif; ?>
        <input type="password" name="password" placeholder="Enter password" required autofocus autocomplete="current-password">
        <button type="submit">Log In</button>
    </form>
</body>
</html>
<?php exit; endif;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$uploadMaxBytes = iniBytes((string)ini_get('upload_max_filesize'));
$postMaxBytes   = iniBytes((string)ini_get('post_max_size'));

// Recover files stranded by an interrupted rename (".tmp_<12 hex>_<original>"), once they're a few minutes old.
foreach ((@scandir(MEDIA_DIR) ?: []) as $f) {
    if (preg_match('/^\.tmp_[0-9a-f]{12}_(.+)$/', $f, $m) && isMediaName($m[1])
        && (time() - (int)@filemtime(MEDIA_DIR . $f)) > 300) {
        @rename(MEDIA_DIR . $f, MEDIA_DIR . uniqueName(pathinfo($m[1], PATHINFO_FILENAME), mediaExt($m[1])));
    }
}

// ---------- actions (all POST, all CSRF-checked, all redirect) ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PHP silently drops the whole request when it exceeds post_max_size.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        redirectWith('err', ['That upload was too large. The server accepts at most '
            . prettyBytes($postMaxBytes) . ' per upload (' . prettyBytes($uploadMaxBytes)
            . ' per file). Upload fewer files at once, or raise post_max_size / upload_max_filesize in Plesk PHP Settings.']);
    }

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        redirectWith('err', ['Your session expired or the form was stale. Please try again.']);
    }

    $settings = loadSettings();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload') {
        $files = $_FILES['media_files'] ?? null;
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            redirectWith('err', ['No files were selected.']);
        }

        $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
        $okNames = [];
        $errors = [];

        foreach ($files['name'] as $i => $origName) {
            $origName = (string)$origName;
            $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) {
                $errors[] = $origName . ': ' . uploadErrorText($err);
                continue;
            }

            $ext = mediaExt($origName);
            if (!in_array($ext, array_merge(IMAGE_EXTS, VIDEO_EXTS), true)) {
                $errors[] = $origName . ': not a supported file type';
                continue;
            }

            $tmp = $files['tmp_name'][$i];
            if ($finfo) {
                $mime = (string)$finfo->file($tmp);
                if (!preg_match('#^(image|video)/#', $mime) && $mime !== 'application/ogg') {
                    $errors[] = $origName . ': does not look like an image or video (' . $mime . ')';
                    continue;
                }
            }

            $base = substr(sanitizeBaseName(pathinfo($origName, PATHINFO_FILENAME)), 0, 100);
            if ($base === '') $base = 'upload';
            $final = uniqueName($base, $ext); // never silently overwrites an existing file

            if (!move_uploaded_file($tmp, MEDIA_DIR . $final)) {
                $errors[] = $origName . ': could not be saved (check media/ folder permissions)';
                continue;
            }
            @chmod(MEDIA_DIR . $final, 0644);
            $settings['order'][] = $final;
            $okNames[] = $final;
        }

        if ($okNames) {
            $settings['order'] = orderedMediaFiles($settings);
            if (!saveSettings($settings)) {
                $errors[] = 'Files uploaded, but settings.json could not be written (check media/ folder permissions).';
            }
        }

        $lines = [];
        if ($okNames) $lines[] = 'Uploaded ' . count($okNames) . ' file(s). They will appear on the TV on the next loop.';
        if (!$okNames && !$errors) $lines[] = 'No files were selected.';
        redirectWith($errors ? 'err' : 'ok', array_merge($lines, $errors));
    }

    if ($action === 'save') {
        if (isset($_POST['fade_duration']) && $_POST['fade_duration'] !== '') {
            $settings['fade_duration'] = max(0, min(10000, (int)$_POST['fade_duration']));
        }
        $settings['mute_videos'] = isset($_POST['mute_videos']);

        $existing = array_flip(listMediaFiles());
        $durations = $settings['items'];
        $newOrder = [];
        $renames = [];
        $seen = [];
        $errors = [];

        $posted = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : [];
        foreach ($posted as $item) {  // arrives in on-screen (dragged) order
            if (!is_array($item)) continue;
            $old = (string)($item['old_name'] ?? '');
            if (!isset($existing[$old]) || isset($seen[$old])) continue; // stale tab or tampered form: leave it alone
            $seen[$old] = true;
            $newOrder[] = $old;

            if (!isVideoFile($old) && isset($item['duration']) && $item['duration'] !== '') {
                $durations[$old] = max(500, min(86400000, (int)$item['duration']));
            }

            // Only the base name is editable; the extension always stays the same.
            $base = substr(sanitizeBaseName((string)($item['new_base'] ?? '')), 0, 100);
            if ($base !== '' && $base !== pathinfo($old, PATHINFO_FILENAME)) {
                $renames[$old] = $base;
            }
        }

        // Anything not in the form (e.g. uploaded from another tab meanwhile) keeps its place at the end.
        foreach (orderedMediaFiles($settings) as $f) {
            if (!isset($seen[$f])) $newOrder[] = $f;
        }

        // Two-step rename: move everything being renamed out of the way first, so swaps (A<->B) work.
        $staged = [];
        foreach ($renames as $old => $base) {
            $tmpName = '.tmp_' . bin2hex(random_bytes(6)) . '_' . $old;
            if (@rename(MEDIA_DIR . $old, MEDIA_DIR . $tmpName)) {
                $staged[$old] = $tmpName;
            } else {
                $errors[] = 'Could not rename ' . $old;
            }
        }
        $taken = [];
        $finalMap = [];
        foreach ($staged as $old => $tmpName) {
            $final = uniqueName($renames[$old], mediaExt($old), $taken);
            $taken[strtolower($final)] = true;
            if (@rename(MEDIA_DIR . $tmpName, MEDIA_DIR . $final)) {
                $finalMap[$old] = $final;
                if ($final !== $renames[$old] . '.' . mediaExt($old)) {
                    $errors[] = 'Name "' . $renames[$old] . '" was already taken, so ' . $old . ' became ' . $final . '.';
                }
            } else {
                @rename(MEDIA_DIR . $tmpName, MEDIA_DIR . $old);
                $errors[] = 'Could not rename ' . $old;
            }
        }

        // Remap durations in one pass (sequential remapping would clobber entries on an A<->B swap).
        $remapped = [];
        foreach (array_intersect_key($durations, $existing) as $name => $ms) {
            $remapped[$finalMap[$name] ?? $name] = $ms;
        }
        $settings['order'] = array_map(fn($f) => $finalMap[$f] ?? $f, $newOrder);
        $settings['items'] = $remapped;

        if (!saveSettings($settings)) {
            redirectWith('err', ['Could not write settings.json (check media/ folder permissions).']);
        }
        redirectWith($errors ? 'err' : 'ok', array_merge(['All changes saved! Updates will show on the TV on the next loop.'], $errors));
    }

    if ($action === 'delete') {
        $target = (string)($_POST['file'] ?? '');
        if (!isMediaName($target) || !is_file(MEDIA_DIR . $target)) {
            redirectWith('err', ['That file no longer exists.']);
        }
        if (!@unlink(MEDIA_DIR . $target)) {
            redirectWith('err', ['Could not delete ' . $target . ' (check media/ folder permissions).']);
        }
        unset($settings['items'][$target]);
        $settings['order'] = array_values(array_diff($settings['order'], [$target]));
        saveSettings($settings);
        redirectWith('ok', ['Deleted ' . $target . '.']);
    }

    redirectWith('err', ['Unknown action.']);
}

// ---------- page ----------

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$settings = loadSettings();
$files = orderedMediaFiles($settings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oxworks TV Display Manager</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; background: #f4f4f9; color: #222; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        .btn { padding: 8px 14px; border-radius: 4px; text-decoration: none; font-size: 0.9rem; cursor: pointer; border: none; display: inline-block; font-family: inherit; }
        .btn-primary { background: #0070f3; color: #fff; font-weight: bold; }
        .btn-success { background: #16a34a; color: #fff; font-weight: bold; }
        .btn-success:hover { background: #15803d; }
        .btn-danger { background: #e00; color: #fff; }
        .btn-secondary { background: #eee; color: #333; }
        .upload-box { border: 2px dashed #ccc; padding: 20px; text-align: center; border-radius: 6px; margin-bottom: 25px; background: #fafafa; }
        .upload-box input[type=file] { max-width: 100%; }
        .limits { font-size: 0.8rem; color: #666; margin: 8px 0 0; }
        .alert { padding: 10px 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert p { margin: 0; }
        .alert p + p { margin-top: 6px; }
        .alert-ok { background: #d4edda; color: #155724; }
        .alert-err { background: #fdecea; color: #8a1c1c; }
        .tip { background: #eef6ff; color: #004085; padding: 12px 15px; border-radius: 4px; font-size: 0.9rem; margin-bottom: 20px; line-height: 1.5; }
        .table-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #f8fafc; padding: 12px 15px; border-radius: 6px; border: 1px solid #e2e8f0; flex-wrap: wrap; gap: 12px; }
        .toolbar-left { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .toolbar-left h3 { margin: 0; font-size: 1.1rem; }
        .control-group { display: flex; align-items: center; gap: 6px; font-size: 0.9rem; color: #333; }
        .checkbox-label { display: flex; align-items: center; gap: 6px; font-size: 0.9rem; color: #333; cursor: pointer; user-select: none; }
        .checkbox-label input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .unsaved { display: none; font-size: 0.8rem; font-weight: bold; color: #b45309; background: #fef3c7; padding: 4px 8px; border-radius: 4px; }
        .unsaved.show { display: inline-block; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 8px; border-bottom: 1px solid #eee; vertical-align: middle; }
        tbody tr { background: #fff; }
        tr.sortable-ghost { opacity: 0.4; background: #eef6ff; }
        .order-cell { white-space: nowrap; width: 1%; }
        .order-tools { display: inline-flex; align-items: center; gap: 4px; }
        .drag-handle { cursor: grab; font-size: 1.3rem; color: #888; user-select: none; padding: 6px 4px; touch-action: none; }
        .drag-handle:active { cursor: grabbing; color: #0070f3; }
        .order-num { font-size: 0.85rem; font-weight: bold; color: #666; min-width: 18px; text-align: center; }
        .move-btn { width: 32px; height: 32px; border: 1px solid #ddd; background: #fafafa; border-radius: 4px; cursor: pointer; font-size: 0.9rem; line-height: 1; padding: 0; color: #444; }
        .move-btn:disabled { opacity: 0.35; cursor: default; }
        .thumb { width: 80px; height: 45px; object-fit: cover; border-radius: 4px; background: #000; display: block; }
        .row-inputs { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .name-wrap { display: inline-flex; align-items: stretch; }
        .input-name { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px 0 0 4px; width: 200px; min-width: 0; font-size: 16px; }
        .ext { padding: 6px 8px; border: 1px solid #ccc; border-left: none; background: #f3f3f3; border-radius: 0 4px 4px 0; color: #555; font-size: 0.9rem; display: flex; align-items: center; }
        .input-ms { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; width: 100px; font-size: 16px; }
        .ms-label { font-size: 0.85rem; color: #555; }
        .video-badge { font-size: 0.85rem; color: #666; background: #f0f0f0; padding: 6px 10px; border-radius: 4px; display: inline-block; width: 100px; text-align: center; }
        .bottom-bar { display: flex; justify-content: flex-end; margin-top: 15px; }

        /* Phone layout: each playlist item becomes a card */
        @media (max-width: 640px) {
            body { padding: 0; }
            .container { border-radius: 0; padding: 16px; box-shadow: none; }
            thead { display: none; }
            table, tbody, tr, td { display: block; width: 100%; }
            tbody tr { display: grid; grid-template-columns: auto 1fr; grid-template-areas: "order order" "thumb name" "action action"; gap: 8px 12px; padding: 12px 0; border-bottom: 1px solid #eee; }
            td { border: none; padding: 0; }
            td.order-cell { grid-area: order; width: auto; }
            td.thumb-cell { grid-area: thumb; }
            td.name-cell { grid-area: name; min-width: 0; }
            td.action-cell { grid-area: action; }
            .move-btn { width: 40px; height: 40px; font-size: 1rem; }
            .name-wrap { width: 100%; }
            .input-name { flex: 1; width: auto; }
            .action-cell .btn { width: 100%; padding: 10px; }
            .table-toolbar .btn-success { width: 100%; padding: 12px; }
        }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h2 style="margin:0;">TV Display Manager</h2>
        <div>
            <a href="index.php" target="_blank" rel="noopener" class="btn btn-secondary">Preview Player</a>
            <a href="?logout=1" class="btn btn-secondary">Logout</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'err' ? 'alert-err' : 'alert-ok' ?>">
            <?php foreach ($flash['lines'] as $line): ?><p><?= h($line) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="tip">
        <strong>How ordering & timing work:</strong><br>
        • Reorder with the <strong>⋮⋮</strong> handle (drag, works on touch too) or the <strong>▲ ▼</strong> buttons, then click <strong>Save All Changes</strong>.<br>
        • Set still image durations and crossfade transition time in milliseconds (<code>1000</code> = 1 second, <code>0</code> = instant cut).<br>
        • <em>Note: If you uncheck <strong>Mute Videos</strong>, click once on the TV screen after loading to allow browser audio.</em>
    </div>

    <form class="upload-box" method="POST" action="admin.php" enctype="multipart/form-data" id="upload-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="upload">
        <p style="margin-top:0;"><strong>Upload Videos or Images</strong> (MP4, WebM, OGG, JPG, PNG, WebP, GIF)</p>
        <input type="file" name="media_files[]" id="file-input" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.ogg" required>
        <p class="limits">Server limits: <?= h(prettyBytes($uploadMaxBytes)) ?> per file, <?= h(prettyBytes($postMaxBytes)) ?> per upload.</p>
        <br>
        <button type="submit" class="btn btn-primary">Upload Files</button>
    </form>

    <!-- Separate form so Delete buttons inside the playlist table don't submit the save form. -->
    <form method="POST" action="admin.php" id="delete-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
    </form>

    <form method="POST" action="admin.php" id="save-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <div class="table-toolbar">
            <div class="toolbar-left">
                <h3>Playlist (<?= count($files) ?>)</h3>
                <div class="control-group">
                    <label for="fade_duration"><strong>Fade:</strong></label>
                    <input type="number" id="fade_duration" class="input-ms" name="fade_duration" value="<?= (int)$settings['fade_duration'] ?>" step="100" min="0" max="10000">
                    <span class="ms-label">ms</span>
                </div>
                <label class="checkbox-label">
                    <input type="checkbox" name="mute_videos" value="1" <?= $settings['mute_videos'] ? 'checked' : '' ?>>
                    <strong>Mute Videos</strong>
                </label>
                <span class="unsaved" id="unsaved">Unsaved changes</span>
            </div>
            <button type="submit" class="btn btn-success">Save All Changes</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Preview</th>
                    <th>Filename & Duration (ms)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="sortable-list">
                <?php if (empty($files)): ?>
                    <tr><td colspan="4" style="text-align:center; color:#777;">No media uploaded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($files as $index => $file):
                    $isVideo = isVideoFile($file);
                    $base = pathinfo($file, PATHINFO_FILENAME);
                    $url = mediaUrl($file);
                    $currentDuration = $settings['items'][$file] ?? DEFAULT_IMAGE_MS;
                ?>
                <tr class="item-row">
                    <td class="order-cell">
                        <span class="order-tools">
                            <span class="drag-handle" title="Drag to reorder" aria-hidden="true">⋮⋮</span>
                            <span class="order-num"><?= $index + 1 ?></span>
                            <button type="button" class="move-btn move-up" title="Move up" aria-label="Move up">▲</button>
                            <button type="button" class="move-btn move-down" title="Move down" aria-label="Move down">▼</button>
                        </span>
                    </td>
                    <td class="thumb-cell">
                        <?php if ($isVideo): ?>
                            <video class="thumb" src="<?= h($url) ?>#t=0.5" muted preload="metadata" playsinline></video>
                        <?php else: ?>
                            <img class="thumb" src="<?= h($url) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                    </td>
                    <td class="name-cell">
                        <div class="row-inputs">
                            <input type="hidden" name="items[<?= $index ?>][old_name]" value="<?= h($file) ?>">
                            <span class="name-wrap">
                                <input type="text" class="input-name" name="items[<?= $index ?>][new_base]" value="<?= h($base) ?>" title="Filename" maxlength="100" autocapitalize="off" spellcheck="false">
                                <span class="ext">.<?= h(mediaExt($file)) ?></span>
                            </span>
                            <?php if (!$isVideo): ?>
                                <span>
                                    <input type="number" class="input-ms" name="items[<?= $index ?>][duration]" value="<?= (int)$currentDuration ?>" step="500" min="500" title="Duration in milliseconds">
                                    <span class="ms-label">ms</span>
                                </span>
                            <?php else: ?>
                                <span class="video-badge">Full video</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="action-cell">
                        <button type="submit" form="delete-form" name="file" value="<?= h($file) ?>" class="btn btn-danger"
                                data-confirm="<?= h('Delete ' . $file . '?') ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($files) > 5): ?>
            <div class="bottom-bar">
                <button type="submit" class="btn btn-success">Save All Changes</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
(function () {
    const tbody = document.getElementById('sortable-list');
    const saveForm = document.getElementById('save-form');
    const unsavedBadge = document.getElementById('unsaved');
    const POST_MAX = <?= (int)$postMaxBytes ?>;
    const FILE_MAX = <?= (int)$uploadMaxBytes ?>;
    let dirty = false;

    function markDirty() {
        dirty = true;
        unsavedBadge.classList.add('show');
    }

    function refreshRows() {
        const rows = tbody.querySelectorAll('tr.item-row');
        rows.forEach((row, idx) => {
            row.querySelector('.order-num').textContent = idx + 1;
            row.querySelector('.move-up').disabled = idx === 0;
            row.querySelector('.move-down').disabled = idx === rows.length - 1;
        });
    }
    refreshRows();

    // Up/down buttons: work everywhere, no library needed.
    tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('.move-btn');
        if (!btn) return;
        const row = btn.closest('tr');
        if (btn.classList.contains('move-up') && row.previousElementSibling) {
            row.previousElementSibling.before(row);
        } else if (btn.classList.contains('move-down') && row.nextElementSibling) {
            row.nextElementSibling.after(row);
        } else {
            return;
        }
        refreshRows();
        markDirty();
        btn.focus();
    });

    // Drag-and-drop for mouse and touch. Loaded async so a slow/blocked CDN never freezes the page;
    // the arrow buttons work regardless.
    if (tbody.querySelector('tr.item-row')) {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        s.async = true;
        s.onload = () => {
            Sortable.create(tbody, {
                handle: '.drag-handle',
                draggable: 'tr.item-row',
                animation: 150,
                onEnd: (evt) => {
                    if (evt.oldIndex !== evt.newIndex) {
                        refreshRows();
                        markDirty();
                    }
                }
            });
        };
        document.head.appendChild(s);
    }

    saveForm.addEventListener('input', markDirty);
    saveForm.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (!confirm(btn.dataset.confirm)) { e.preventDefault(); return; }
            dirty = false;
        });
    });

    // Catch oversize uploads before they're sent (PHP would otherwise drop the request silently).
    document.getElementById('upload-form').addEventListener('submit', (e) => {
        const files = Array.from(document.getElementById('file-input').files);
        const tooBig = FILE_MAX > 0 ? files.filter(f => f.size > FILE_MAX).map(f => f.name) : [];
        const total = files.reduce((n, f) => n + f.size, 0);
        if (tooBig.length) {
            e.preventDefault();
            alert('These files are larger than the server limit (<?= h(prettyBytes($uploadMaxBytes)) ?> per file):\n\n' + tooBig.join('\n'));
        } else if (POST_MAX > 0 && total > POST_MAX) {
            e.preventDefault();
            alert('Those files add up to more than the server limit (<?= h(prettyBytes($postMaxBytes)) ?> per upload). Upload them in smaller batches.');
        } else if (dirty && !confirm('You have unsaved playlist changes. Uploading will discard them. Continue?')) {
            e.preventDefault();
        } else {
            dirty = false;
        }
    });
})();
</script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'organizer.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Serve existing static assets when running under PHP's built-in web server
if (php_sapi_name() === 'cli-server' && str_starts_with($requestPath, '/static/')) {
    $filePath = realpath(BASE_DIR . str_replace('/', DIRECTORY_SEPARATOR, $requestPath));
    if ($filePath && str_starts_with($filePath, BASE_DIR . DIRECTORY_SEPARATOR . 'static') && is_file($filePath)) {
        return false;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

init_db();

function flash(string $message, string $category = 'success'): void
{
    $_SESSION['flashes'][] = ['category' => $category, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

function redirect_to(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function linkify_text(string $text): string
{
    $escaped = e($text);
    return (string)preg_replace(
        '/(https?:\/\/[^\s<]+)/i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $escaped
    );
}

// ==============================================================================
// Route Dispatch
// ==============================================================================

if ($requestPath === '/login') {
    redirect_to('/');
}

if ($requestPath === '/watchlist/connect-whatsapp' && $method === 'POST') {
    launch_agent_bg(['--connect-whatsapp']);
    $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['started' => true]);
        exit;
    }
    flash('WhatsApp QR Scanner started! Scan the live QR code in the popup on this page.', 'success');
    redirect_to('/?show_wa_qr=1');
}

if ($requestPath === '/admin/whatsapp-qr' && $method === 'GET') {
    launch_agent_bg(['--connect-whatsapp']);
    redirect_to('/?show_wa_qr=1');
}

if ($requestPath === '/wa-status' && $method === 'GET') {
    $data = ['state' => 'idle', 'message' => "Click 'Connect WhatsApp' to load QR code."];
    if (is_file(WA_STATUS_JSON_PATH)) {
        $decoded = json_decode((string)file_get_contents(WA_STATUS_JSON_PATH), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    $data['has_image'] = is_file(WA_LIVE_IMG_PATH);
    $data['agent_running'] = is_agent_running();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if ($requestPath === '/watchlist/add' && $method === 'POST') {
    $rawInput = trim((string)($_POST['fb_urls'] ?? ''));
    $scanImmediately = ($_POST['scan_immediately'] ?? '1') === '1';

    if ($rawInput === '') {
        flash('Please paste at least one Facebook Page or WhatsApp Channel link.', 'error');
        redirect_to('/');
    }

    $candidates = array_values(array_filter(
        array_map(fn($u) => rtrim(trim($u), '/'), preg_split('/[\r\n,\s]+/', $rawInput) ?: []),
        fn($u) => $u !== ''
    ));
    $validUrls = array_values(array_filter(
        $candidates,
        fn($u) => str_starts_with($u, 'http://') || str_starts_with($u, 'https://')
    ));

    if (empty($validUrls)) {
        flash('Please enter valid Facebook or WhatsApp Channel URLs starting with https://', 'error');
        redirect_to('/');
    }

    $addedCount = 0;
    $nowStr = date('Y-m-d H:i:s');
    $stmt = get_db()->prepare(
        'INSERT OR IGNORE INTO watched_pages (fb_url, label, enabled, last_status, created_at) VALUES (?, ?, 1, "Added — queued for scan", ?)'
    );
    foreach ($validUrls as $u) {
        $stmt->execute([$u, extract_page_label($u), $nowStr]);
        if ($stmt->rowCount() > 0) {
            $addedCount++;
        }
    }

    if ($scanImmediately) {
        if (count($validUrls) === 1 && launch_agent_bg([$validUrls[0]])) {
            flash("Added {$validUrls[0]} to watchlist and launched immediate check!", 'success');
        } elseif (launch_agent_bg(['--watch-cycle'])) {
            flash("Added {$addedCount} link(s) and started full watchlist scan!", 'success');
        } else {
            flash("Added {$addedCount} link(s) to watchlist (agent is currently busy scanning).", 'success');
        }
    } else {
        flash("Added {$addedCount} link(s) to the 5-minute Auto-Pilot watchlist.", 'success');
    }
    redirect_to('/');
}

if (preg_match('#^/watchlist/toggle/(\d+)$#', $requestPath, $m) && $method === 'POST') {
    $pageId = (int)$m[1];
    $db = get_db();
    $row = $db->prepare('SELECT enabled, label FROM watched_pages WHERE id = ?');
    $row->execute([$pageId]);
    $item = $row->fetch();
    if ($item) {
        $newState = $item['enabled'] ? 0 : 1;
        $statusText = $newState ? 'Monitoring active' : 'Paused';
        $upd = $db->prepare('UPDATE watched_pages SET enabled = ?, last_status = ? WHERE id = ?');
        $upd->execute([$newState, $statusText, $pageId]);
        flash(($newState ? 'Resumed' : 'Paused') . ' monitoring for ' . $item['label'] . '.', 'success');
    }
    redirect_to('/');
}

if (preg_match('#^/watchlist/delete/(\d+)$#', $requestPath, $m) && $method === 'POST') {
    $stmt = get_db()->prepare('DELETE FROM watched_pages WHERE id = ?');
    $stmt->execute([(int)$m[1]]);
    flash('Removed link from watchlist.', 'success');
    redirect_to('/');
}

if (preg_match('#^/watchlist/scan-one/(\d+)$#', $requestPath, $m) && $method === 'POST') {
    $stmt = get_db()->prepare('SELECT fb_url, label FROM watched_pages WHERE id = ?');
    $stmt->execute([(int)$m[1]]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('Watched link not found.', 'error');
        redirect_to('/');
    }
    if (launch_agent_bg([$row['fb_url']])) {
        flash("Checking {$row['label']} ({$row['fb_url']}) for new posts now...", 'success');
    } else {
        flash('The PHP agent is currently running another check. Please wait a moment.', 'error');
    }
    redirect_to('/');
}

if ($requestPath === '/watchlist/scan-now' && $method === 'POST') {
    if (launch_agent_bg(['--watch-cycle'])) {
        flash('Launched full scan across all active Facebook & WhatsApp channels in your watchlist!', 'success');
    } else {
        flash('The PHP agent is already running a scan!', 'error');
    }
    redirect_to('/');
}

if ($requestPath === '/watchlist/autopilot' && $method === 'POST') {
    $autopilot = ($_POST['autopilot_enabled'] ?? '') === '1' ? '1' : '0';
    $interval = trim((string)($_POST['poll_interval_minutes'] ?? '5'));
    if (!in_array($interval, ['2', '5', '10', '15'], true)) {
        $interval = '5';
    }
    $headless = ($_POST['headless_mode'] ?? '') === '1' ? '1' : '0';

    set_setting('autopilot_enabled', $autopilot);
    set_setting('poll_interval_minutes', $interval);
    set_setting('headless_mode', $headless);
    set_setting('last_auto_trigger_ts', (string)time());

    flash('Auto-Pilot settings updated.', 'success');
    redirect_to('/');
}

if ($requestPath === '/repost-last' && $method === 'POST') {
    if (launch_agent_bg(['--repost-last'])) {
        flash("Agent is organizing and posting the last retrieved post via '+ Post News / Event'!", 'success');
    } else {
        flash('The PHP agent is already running!', 'error');
    }
    redirect_to('/');
}

if ($requestPath === '/agent-status' && $method === 'GET') {
    $running = is_agent_running();
    $autopilotOn = get_setting('autopilot_enabled', '1') === '1';
    $intervalMins = max(1, (int)(get_setting('poll_interval_minutes', '5') ?: '5'));
    $lastTs = (int)(get_setting('last_auto_trigger_ts', (string)time()) ?: time());
    $secondsRemaining = max(0, ($intervalMins * 60) - (time() - $lastTs));

    // Trigger 5-minute Auto-Pilot watch cycle automatically when timer elapses
    if ($autopilotOn && $secondsRemaining <= 0 && !$running && !empty(get_enabled_watched_pages())) {
        launch_agent_bg(['--watch-cycle']);
        $running = true;
        $secondsRemaining = $intervalMins * 60;
    }

    $db = get_db();
    $pages = $db->query('SELECT id, fb_url, label, enabled, last_checked_at, last_status FROM watched_pages ORDER BY id DESC')->fetchAll();
    $postCount = (int)$db->query('SELECT COUNT(*) FROM posts')->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode([
        'running' => $running,
        'autopilot_enabled' => $autopilotOn,
        'poll_interval_minutes' => $intervalMins,
        'seconds_until_next_scan' => $secondsRemaining,
        'post_count' => $postCount,
        'watched_pages' => $pages,
    ]);
    exit;
}

if ($requestPath === '/api/posts.json' && $method === 'GET') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:5000';
    $baseUrl = "{$scheme}://{$host}";

    $rows = get_db()->query(
        'SELECT id, author, category, campus_city, headline, content, image_filename, youtube_link, source_url, created_at FROM posts ORDER BY id DESC LIMIT 100'
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['image_url'] = !empty($r['image_filename']) ? ($baseUrl . '/static/uploads/' . rawurlencode((string)$r['image_filename'])) : null;
    }
    unset($r);

    header('Content-Type: application/json');
    echo json_encode(['count' => count($rows), 'posts' => $rows]);
    exit;
}

// ==============================================================================
// Main Dashboard & "+ Post News / Event" Handler (/)
// ==============================================================================

if ($requestPath === '/' && $method === 'POST') {
    $category = trim((string)($_POST['category'] ?? 'News / Gist')) ?: 'News / Gist';
    $campusCity = trim((string)($_POST['campus_city'] ?? ''));
    $campusCityVal = $campusCity !== '' ? $campusCity : null;
    $headline = trim((string)($_POST['headline'] ?? ''));
    $postText = trim((string)($_POST['post_text'] ?? ''));
    $youtubeLink = trim((string)($_POST['youtube_link'] ?? '')) ?: null;
    $sourceUrl = trim((string)($_POST['source_url'] ?? '')) ?: null;

    $savedFilename = null;
    if (!empty($_FILES['post_image']['name']) && ($_FILES['post_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $origName = (string)$_FILES['post_image']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGE_EXTENSIONS, true)) {
            flash('Unsupported image format. Allowed: png, jpg, jpeg, gif, webp.', 'error');
            redirect_to('/');
        }
        $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = UPLOAD_FOLDER . DIRECTORY_SEPARATOR . $safeName;
        if (move_uploaded_file((string)$_FILES['post_image']['tmp_name'], $destPath)) {
            $savedFilename = $safeName;
        }
    }

    if ($headline === '' && $postText === '' && $savedFilename === null) {
        flash('Please fill in Headline and Details before submitting.', 'error');
        redirect_to('/');
    }

    $stmt = get_db()->prepare(
        'INSERT INTO posts (author, category, campus_city, headline, content, image_filename, youtube_link, source_url, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        'israelobisesan44@gmail.com',
        $category,
        $campusCityVal,
        $headline,
        $postText,
        $savedFilename,
        $youtubeLink,
        $sourceUrl,
        date('Y-m-d H:i:s'),
    ]);

    flash("Post submitted via Elo54 Pulse ('Shine a Light on Your Campus or City')!", 'success');
    redirect_to('/');
}

$db = get_db();
$posts = $db->query(
    'SELECT id, author, category, campus_city, headline, content, image_filename, youtube_link, source_url, created_at FROM posts ORDER BY id DESC'
)->fetchAll();
$watchedPages = $db->query(
    'SELECT id, fb_url, label, enabled, last_checked_at, last_status, created_at FROM watched_pages ORDER BY id DESC'
)->fetchAll();
$flashes = consume_flashes();
$agentRunning = is_agent_running();
$postCount = count($posts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elo54 Pulse — Campus &amp; City News (PHP)</title>
    <style>
        :root {
            --bg: #0b0e14;
            --card: #131722;
            --modal-bg: #131722;
            --input-bg: #1c2230;
            --border: #2a3245;
            --text: #f8fafc;
            --muted: #8b98a9;
            --orange: #f97316;
            --orange-hover: #ea580c;
            --orange-soft: rgba(249, 115, 22, 0.16);
            --accent: #10b981;
            --accent-hover: #059669;
            --success-bg: rgba(16, 185, 129, 0.15);
            --success-text: #6ee7b7;
            --error-bg: rgba(239, 68, 68, 0.15);
            --error-text: #fca5a5;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 4rem;
        }
        header {
            background: rgba(19, 23, 34, 0.92);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .nav-container {
            max-width: 860px;
            margin: 0 auto;
            padding: 0.9rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-weight: 800;
            font-size: 1.2rem;
            letter-spacing: -0.02em;
        }
        .brand-pill {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: var(--orange-soft);
            color: var(--orange);
            border: 1px solid rgba(249, 115, 22, 0.4);
        }
        .btn-post-news {
            padding: 0.65rem 1.25rem;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: #ffffff;
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(249, 115, 22, 0.35);
            transition: transform 0.12s ease, opacity 0.15s ease;
        }
        .btn-post-news:hover { opacity: 0.95; transform: translateY(-1px); }
        main { max-width: 860px; margin: 1.75rem auto 0; padding: 0 1.25rem; }
        .flash { padding: 0.85rem 1rem; border-radius: 8px; font-size: 0.9rem; margin-bottom: 1.25rem; border: 1px solid transparent; }
        .flash.success { background: var(--success-bg); color: var(--success-text); border-color: rgba(16, 185, 129, 0.35); }
        .flash.error { background: var(--error-bg); color: var(--error-text); border-color: rgba(239, 68, 68, 0.35); }
        .agent-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 1.4rem 1.5rem; margin-bottom: 1.75rem; }
        .url-input-group { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 1.15rem; }
        input[type="text"]#fb-urls-input {
            flex: 1 1 320px; padding: 0.72rem 0.9rem; border-radius: 8px;
            border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-size: 0.9rem; outline: none;
        }
        input[type="text"]#fb-urls-input:focus { border-color: var(--orange); }
        .btn-run-agent { padding: 0.68rem 1.15rem; border: none; border-radius: 8px; background: var(--accent); color: #042419; font-size: 0.86rem; font-weight: 700; cursor: pointer; }
        .btn-run-agent:hover { background: var(--accent-hover); color: white; }
        .watchlist-table-wrap { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: var(--input-bg); }
        .watchlist-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); }
        .watchlist-row:last-child { border-bottom: none; }
        .watchlist-row.paused { opacity: 0.55; }
        .page-info { display: flex; flex-direction: column; gap: 0.2rem; min-width: 220px; flex: 1; }
        .page-title-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .platform-badge { font-size: 0.68rem; font-weight: 800; padding: 0.14rem 0.48rem; border-radius: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
        .platform-badge.fb { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }
        .platform-badge.wa { background: rgba(37, 211, 102, 0.18); color: #4ade80; border: 1px solid rgba(37, 211, 102, 0.45); }
        .page-label { font-weight: 700; font-size: 0.92rem; color: #ffffff; }
        .page-url { font-size: 0.78rem; color: var(--muted); text-decoration: none; word-break: break-all; }
        .page-url:hover { color: var(--orange); text-decoration: underline; }
        .page-status-meta { font-size: 0.78rem; color: #93c5fd; }
        .page-actions { display: flex; align-items: center; gap: 0.45rem; }
        .btn-xs { padding: 0.35rem 0.68rem; border-radius: 6px; border: 1px solid var(--border); background: var(--card); color: #cbd5e1; font-size: 0.76rem; font-weight: 600; cursor: pointer; }
        .btn-xs:hover { border-color: var(--orange); color: white; }
        .btn-xs.danger:hover { border-color: #ef4444; color: #fca5a5; }
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(5, 7, 12, 0.78); backdrop-filter: blur(4px); z-index: 100; align-items: center; justify-content: center; padding: 1.25rem; overflow-y: auto; }
        .modal-backdrop.open { display: flex; }
        .elo54-modal { width: 100%; max-width: 610px; background: var(--modal-bg); border: 1px solid var(--border); border-radius: 16px; padding: 1.85rem 2rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.75); margin: auto; }
        .elo54-modal h2 { font-size: 1.45rem; font-weight: 800; color: #ffffff; margin-bottom: 0.3rem; }
        .elo54-modal .modal-lead { font-size: 0.88rem; color: var(--muted); margin-bottom: 1.35rem; }
        .field-block { margin-bottom: 1.15rem; }
        .field-label { display: block; font-size: 0.85rem; font-weight: 700; color: #e2e8f0; margin-bottom: 0.45rem; }
        .field-label .req { color: #ef4444; margin-left: 2px; }
        .category-tabs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem; }
        .cat-tab { padding: 0.68rem 0.75rem; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: #94a3b8; font-size: 0.88rem; font-weight: 600; text-align: center; cursor: pointer; transition: all 0.15s ease; }
        .cat-tab.active { background: rgba(249, 115, 22, 0.14); border-color: var(--orange); color: var(--orange); font-weight: 700; }
        .elo54-select, .elo54-input, .elo54-textarea { width: 100%; padding: 0.75rem 0.95rem; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); font-family: inherit; font-size: 0.93rem; outline: none; }
        .elo54-select:focus, .elo54-input:focus, .elo54-textarea:focus { border-color: var(--orange); }
        .elo54-textarea { min-height: 115px; resize: vertical; line-height: 1.5; }
        .field-hint { font-size: 0.76rem; color: var(--muted); margin-top: 0.35rem; }
        .two-col-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 560px) { .two-col-row { grid-template-columns: 1fr; } }
        input[type="file"].elo54-file { font-size: 0.83rem; color: var(--muted); margin-top: 0.25rem; }
        input[type="file"].elo54-file::file-selector-button { padding: 0.5rem 0.9rem; margin-right: 0.65rem; border-radius: 999px; border: 1px solid var(--border); background: #232a3b; color: #f8fafc; font-weight: 600; font-size: 0.82rem; cursor: pointer; }
        .modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 1rem; margin-top: 1.4rem; }
        .btn-cancel { background: transparent; border: none; color: var(--muted); font-size: 0.9rem; font-weight: 600; cursor: pointer; padding: 0.5rem 0.75rem; }
        .btn-cancel:hover { color: #ffffff; }
        .btn-send-review { padding: 0.72rem 1.45rem; border: none; border-radius: 999px; background: linear-gradient(135deg, #f97316, #ea580c); color: #ffffff; font-size: 0.92rem; font-weight: 700; cursor: pointer; box-shadow: 0 4px 14px rgba(249, 115, 22, 0.35); }
        .btn-send-review:hover { background: var(--orange-hover); }
        .feed-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .feed-header h2 { font-size: 1.15rem; font-weight: 700; }
        .post-count { font-size: 0.85rem; color: var(--muted); }
        .post-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 1.5rem; margin-bottom: 1.25rem; }
        .post-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
        .badge-cat { font-size: 0.75rem; font-weight: 700; padding: 0.22rem 0.65rem; border-radius: 999px; background: var(--orange-soft); color: var(--orange); border: 1px solid rgba(249, 115, 22, 0.35); }
        .badge-city { font-size: 0.75rem; font-weight: 600; padding: 0.22rem 0.65rem; border-radius: 999px; background: rgba(59, 130, 246, 0.15); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.35); }
        .post-timestamp { margin-left: auto; font-size: 0.8rem; color: var(--muted); }
        .post-headline { font-size: 1.2rem; font-weight: 800; color: #ffffff; margin-bottom: 0.6rem; line-height: 1.35; }
        .post-content { white-space: pre-wrap; word-break: break-word; line-height: 1.6; font-size: 0.96rem; color: #cbd5e1; }
        .post-content a, .post-source-box a { color: #fb923c; text-decoration: underline; }
        .post-source-box { margin-top: 0.85rem; padding: 0.6rem 0.85rem; border-radius: 8px; background: var(--input-bg); border: 1px solid var(--border); font-size: 0.84rem; color: var(--muted); word-break: break-all; }
        .post-image-container { margin-top: 1rem; border-radius: 10px; overflow: hidden; border: 1px solid var(--border); background: #080a0f; }
        .post-image-container img { display: block; width: 100%; max-height: 540px; object-fit: contain; }
        .empty-feed { text-align: center; padding: 3rem 1.5rem; background: var(--card); border: 1px dashed var(--border); border-radius: 12px; color: var(--muted); }
    </style>
</head>
<body>
    <header>
        <div class="nav-container">
            <div class="brand">
                <span>Elo54</span>
                <span class="brand-pill">Pulse PHP Agent</span>
            </div>
            <button type="button" id="open-post-modal-btn" class="btn-post-news" onclick="openPostModal()">
                + Post News / Event
            </button>
        </div>
    </header>

    <main>
        <?php foreach ($flashes as $f): ?>
            <div class="flash <?= e($f['category']) ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>

        <section class="agent-card">
            <p style="font-size: 0.78rem; color: var(--muted); margin-bottom: 0.75rem;">
                Connect your WhatsApp at <a href="/admin/whatsapp-qr" style="color: #4ade80; text-decoration: underline;">/admin/whatsapp-qr</a>
            </p>
            <form method="POST" action="/watchlist/add">
                <div class="url-input-group">
                    <input
                        type="text"
                        id="fb-urls-input"
                        name="fb_urls"
                        placeholder="Paste Facebook Page or WhatsApp Channel link(s) (e.g. https://whatsapp.com/channel/0029Va... or https://www.facebook.com/bbcnews)"
                        required
                    >
                    <input type="hidden" name="scan_immediately" value="1">
                    <button type="submit" id="add-page-btn" class="btn-run-agent">
                        + Add Link &amp; Check
                    </button>
                </div>
            </form>

            <div class="watchlist-table-wrap" id="watchlist-container">
                <?php if (!empty($watchedPages)): ?>
                    <?php foreach ($watchedPages as $wp): ?>
                        <?php $isWa = is_whatsapp_url((string)$wp['fb_url']); ?>
                        <div class="watchlist-row <?= $wp['enabled'] ? '' : 'paused' ?>" data-page-id="<?= (int)$wp['id'] ?>">
                            <div class="page-info">
                                <div class="page-title-row">
                                    <span class="platform-badge <?= $isWa ? 'wa' : 'fb' ?>">
                                        <?= $isWa ? 'WA' : 'FB' ?>
                                    </span>
                                    <span class="page-label" id="page-label-<?= (int)$wp['id'] ?>"><?= e($wp['label']) ?></span>
                                    <span class="page-status-meta" id="page-status-<?= (int)$wp['id'] ?>">
                                        · <?= e($wp['last_status'] ?: 'Waiting for scan') ?>
                                        <?php if (!empty($wp['last_checked_at'])): ?>
                                            (<?= e($wp['last_checked_at']) ?>)
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <a class="page-url" href="<?= e($wp['fb_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($wp['fb_url']) ?></a>
                            </div>
                            <div class="page-actions">
                                <form method="POST" action="/watchlist/scan-one/<?= (int)$wp['id'] ?>">
                                    <button type="submit" class="btn-xs">Check Now</button>
                                </form>
                                <form method="POST" action="/watchlist/toggle/<?= (int)$wp['id'] ?>">
                                    <button type="submit" class="btn-xs"><?= $wp['enabled'] ? 'Pause' : 'Resume' ?></button>
                                </form>
                                <form method="POST" action="/watchlist/delete/<?= (int)$wp['id'] ?>">
                                    <button type="submit" class="btn-xs danger">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 1rem; text-align: center; color: var(--muted); font-size: 0.88rem;">
                        No Facebook pages or WhatsApp channels in watchlist yet. Add your first link above!
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Private Admin WhatsApp Web QR Code Scanner Modal (?show_wa_qr=1) -->
        <div id="wa-qr-modal-backdrop" class="modal-backdrop">
            <div class="elo54-modal" role="dialog" style="max-width: 720px; text-align: center;">
                <h2>📱 Connect WhatsApp Web (Live QR Code)</h2>
                <p class="modal-lead" id="wa-qr-status-text" style="color: #4ade80; font-weight: 600; margin-top: 0.35rem;">
                    Starting WhatsApp Web inside persistent browser profile...
                </p>
                <div style="background: #080a0f; border: 1px solid var(--border); border-radius: 12px; padding: 0.75rem; min-height: 360px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                    <img id="wa-live-qr-img" src="" alt="Live WhatsApp Web QR Code" style="max-width: 100%; max-height: 520px; border-radius: 8px; display: none;">
                    <div id="wa-qr-placeholder" style="color: var(--muted); font-size: 0.92rem;">
                        Loading live WhatsApp Web screen (takes ~4 seconds)...
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <span style="font-size: 0.8rem; color: var(--muted);">
                        Open WhatsApp on your phone &rarr; <strong>Linked Devices</strong> &rarr; <strong>Link a Device</strong>
                    </span>
                    <button type="button" class="btn-send-review" onclick="closeWhatsAppQrModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Elo54 "+ Post News / Event" Popup Modal -->
        <div id="post-modal-backdrop" class="modal-backdrop">
            <div class="elo54-modal" role="dialog" aria-labelledby="modal-title">
                <h2 id="modal-title">Shine a Light on Your Campus or City</h2>
                <p class="modal-lead">Share verified news, events, or opportunities with your community on Elo54 Pulse.</p>

                <form method="POST" action="/" enctype="multipart/form-data">
                    <div class="field-block">
                        <label class="field-label">What are you posting?</label>
                        <input type="hidden" id="post-category" name="category" value="News / Gist">
                        <div class="category-tabs">
                            <button type="button" class="cat-tab active" data-cat="News / Gist" onclick="selectCategory(this)">News / Gist</button>
                            <button type="button" class="cat-tab" data-cat="Event" onclick="selectCategory(this)">Event</button>
                            <button type="button" class="cat-tab" data-cat="Opportunity" onclick="selectCategory(this)">Opportunity</button>
                        </div>
                    </div>

                    <div class="field-block">
                        <label class="field-label" for="campus-city">Campus or City</label>
                        <select id="campus-city" name="campus_city" class="elo54-select">
                            <option value="" selected>Select a campus, city, or state...</option>
                            <?php foreach (CAMPUS_CITY_OPTIONS as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-block">
                        <label class="field-label" for="post-headline">Headline <span class="req">*</span></label>
                        <input type="text" id="post-headline" name="headline" class="elo54-input" maxlength="140" placeholder="Padel's Pilates Softlife Wellness Morning" required>
                        <div class="field-hint">Keep it clear and factual. Max 140 characters, no ALL CAPS.</div>
                    </div>

                    <div class="field-block">
                        <label class="field-label" for="post-text">Details <span class="req">*</span></label>
                        <textarea id="post-text" name="post_text" class="elo54-textarea" placeholder="What happened (or is happening), who's involved, and what people in your campus or city need to know." required></textarea>
                    </div>

                    <div class="field-block two-col-row">
                        <div>
                            <label class="field-label" for="post-image">Image (optional, JPG/PNG/WebP &le; 5 MB)</label>
                            <input type="file" id="post-image" name="post_image" class="elo54-file" accept="image/*">
                        </div>
                        <div>
                            <label class="field-label" for="youtube-link">Or YouTube Link (optional)</label>
                            <input type="url" id="youtube-link" name="youtube_link" class="elo54-input" placeholder="https://youtube.com/watch?v=...">
                        </div>
                    </div>

                    <div class="field-block">
                        <label class="field-label" for="source-link">Source / Registration Link (optional)</label>
                        <input type="url" id="source-link" name="source_url" class="elo54-input" placeholder="https://...">
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closePostModal()">Cancel</button>
                        <button type="submit" id="publish-btn" class="btn-send-review">Send for Review</button>
                    </div>
                </form>
            </div>
        </div>

        <section id="live-feed">
            <div class="feed-header">
                <h2>Elo54 Pulse Live Feed</h2>
                <span class="post-count" id="feed-post-count"><?= $postCount ?> post<?= $postCount === 1 ? '' : 's' ?></span>
            </div>

            <?php if (!empty($posts)): ?>
                <?php foreach ($posts as $post): ?>
                    <article class="post-card" data-post-id="<?= (int)$post['id'] ?>">
                        <div class="post-badges">
                            <span class="badge-cat"><?= e($post['category'] ?: 'News / Gist') ?></span>
                            <?php if (!empty($post['campus_city'])): ?>
                                <span class="badge-city">📍 <?= e($post['campus_city']) ?></span>
                            <?php endif; ?>
                            <time class="post-timestamp"><?= e($post['created_at']) ?></time>
                        </div>

                        <?php if (!empty($post['headline'])): ?>
                            <h3 class="post-headline"><?= e($post['headline']) ?></h3>
                        <?php endif; ?>

                        <?php if (!empty($post['content'])): ?>
                            <div class="post-content"><?= linkify_text((string)$post['content']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($post['source_url'])): ?>
                            <div class="post-source-box">
                                <strong>Source / Registration Link:</strong>
                                <a href="<?= e($post['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($post['source_url']) ?></a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($post['image_filename'])): ?>
                            <div class="post-image-container">
                                <img src="/static/uploads/<?= rawurlencode((string)$post['image_filename']) ?>" alt="Attached image for post #<?= (int)$post['id'] ?>">
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-feed">
                    No posts published yet. Click <strong>+ Post News / Event</strong> or add a Facebook / WhatsApp Channel link above.
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        function openPostModal() { document.getElementById("post-modal-backdrop").classList.add("open"); }
        function closePostModal() { document.getElementById("post-modal-backdrop").classList.remove("open"); }
        function selectCategory(btn) {
            document.querySelectorAll(".cat-tab").forEach(el => el.classList.remove("active"));
            btn.classList.add("active");
            document.getElementById("post-category").value = btn.getAttribute("data-cat");
        }

        let waPollTimer = null;
        function closeWhatsAppQrModal() {
            document.getElementById("wa-qr-modal-backdrop").classList.remove("open");
            if (waPollTimer) { clearInterval(waPollTimer); waPollTimer = null; }
        }
        async function pollWhatsAppQrStatus() {
            try {
                const res = await fetch("/wa-status");
                const data = await res.json();
                const statusEl = document.getElementById("wa-qr-status-text");
                const imgEl = document.getElementById("wa-live-qr-img");
                const placeholderEl = document.getElementById("wa-qr-placeholder");
                if (data.message && statusEl) statusEl.textContent = data.message;
                if (data.has_image && imgEl) {
                    imgEl.src = "/static/wa_live.png?t=" + Date.now();
                    imgEl.style.display = "block";
                    if (placeholderEl) placeholderEl.style.display = "none";
                }
            } catch (e) { console.error(e); }
        }
        if (window.location.search.includes("show_wa_qr=1")) {
            document.getElementById("wa-qr-modal-backdrop").classList.add("open");
            waPollTimer = setInterval(pollWhatsAppQrStatus, 1500);
            pollWhatsAppQrStatus();
        }

        let currentPostCount = <?= $postCount ?>;
        let wasRunning = <?= $agentRunning ? 'true' : 'false' ?>;
        setInterval(async () => {
            try {
                const res = await fetch("/agent-status");
                const data = await res.json();
                if (data.watched_pages) {
                    for (const wp of data.watched_pages) {
                        const statusEl = document.getElementById("page-status-" + wp.id);
                        if (statusEl) {
                            const checked = wp.last_checked_at ? ` (${wp.last_checked_at})` : "";
                            statusEl.textContent = `· ${wp.last_status || "Waiting for scan"}${checked}`;
                        }
                        const labelEl = document.getElementById("page-label-" + wp.id);
                        if (labelEl && wp.label) labelEl.textContent = wp.label;
                    }
                }
                if (data.post_count > currentPostCount || (wasRunning && !data.running)) {
                    window.location.reload();
                }
                wasRunning = data.running;
            } catch (e) { console.error(e); }
        }, 3000);
    </script>
</body>
</html>

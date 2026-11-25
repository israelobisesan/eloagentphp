<?php
declare(strict_types=1);

define('BASE_DIR', __DIR__);
define('UPLOAD_FOLDER', BASE_DIR . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'uploads');
define('DB_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'sandbox_posts.db');
define('MEMORY_DB_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'agent_memory.db');
define('PROFILE_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'fb_profile');
define('AGENT_SCRIPT', BASE_DIR . DIRECTORY_SEPARATOR . 'agent.php');
define('WA_LIVE_IMG_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'wa_live.png');
define('WA_STATUS_JSON_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'wa_status.json');
define('TEMP_IMAGE_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'temp_post_image.jpg');
define('AGENT_PID_FILE', BASE_DIR . DIRECTORY_SEPARATOR . 'agent.pid');

const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

if (!is_dir(UPLOAD_FOLDER)) {
    @mkdir(UPLOAD_FOLDER, 0775, true);
}
if (!is_dir(PROFILE_DIR)) {
    @mkdir(PROFILE_DIR, 0775, true);
}

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
    }
    return $pdo;
}

function get_memory_db(): PDO
{
    static $memPdo = null;
    if ($memPdo === null) {
        $memPdo = new PDO('sqlite:' . MEMORY_DB_PATH);
        $memPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $memPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $memPdo->exec('PRAGMA busy_timeout = 5000;');
    }
    return $memPdo;
}

function is_whatsapp_url(string $url): bool
{
    $host = strtolower((string)(parse_url(trim($url), PHP_URL_HOST) ?? ''));
    return $host !== '' && str_contains($host, 'whatsapp.com');
}

function http_fetch_raw(string $url, int $timeoutSec = 12): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => ($body !== false && $status >= 200 && $status < 400), 'status' => $status, 'body' => (string)$body, 'error' => $err];
}

function extract_page_label(string $sourceUrl): string
{
    $urlClean = trim($sourceUrl);
    if (is_whatsapp_url($urlClean)) {
        $res = http_fetch_raw($urlClean, 8);
        if ($res['ok'] && preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $res['body'], $m)) {
            $rawTitle = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $cleanTitle = trim((string)preg_replace('/\s*\|\s*WhatsApp\s+Channel\s*$/i', '', $rawTitle));
            if ($cleanTitle !== '' && strtolower($cleanTitle) !== 'whatsapp') {
                return $cleanTitle;
            }
        }
        $path = trim((string)(parse_url($urlClean, PHP_URL_PATH) ?? ''), '/');
        $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== '' && strtolower($p) !== 'channel'));
        if (!empty($parts)) {
            return 'WhatsApp Channel (' . substr($parts[0], 0, 10) . '...)';
        }
        return 'WhatsApp Channel';
    }

    $path = trim((string)(parse_url($urlClean, PHP_URL_PATH) ?? ''), '/');
    if ($path !== '') {
        $parts = explode('/', $path);
        if (!empty($parts[0])) {
            return $parts[0];
        }
    }
    return $urlClean;
}

function init_db(): void
{
    $db = get_db();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author TEXT NOT NULL,
            category TEXT DEFAULT "News / Gist",
            campus_city TEXT DEFAULT NULL,
            headline TEXT,
            content TEXT NOT NULL,
            image_filename TEXT,
            youtube_link TEXT,
            source_url TEXT,
            created_at TEXT NOT NULL
        )'
    );

    $cols = [];
    foreach ($db->query('PRAGMA table_info(posts)') as $row) {
        $cols[$row['name']] = true;
    }
    $migrations = [
        'category' => 'TEXT DEFAULT "News / Gist"',
        'campus_city' => 'TEXT DEFAULT NULL',
        'headline' => 'TEXT',
        'youtube_link' => 'TEXT',
        'source_url' => 'TEXT',
    ];
    foreach ($migrations as $col => $def) {
        if (!isset($cols[$col])) {
            $db->exec("ALTER TABLE posts ADD COLUMN $col $def");
        }
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS watched_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fb_url TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            enabled INTEGER DEFAULT 1,
            last_checked_at TEXT,
            last_status TEXT DEFAULT "Waiting for scan",
            created_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $defaultFb = getenv('FB_TARGET_URL') ?: 'https://www.facebook.com/bbcnews';
    $defaults = [
        'fb_target_url' => $defaultFb,
        'autopilot_enabled' => '1',
        'poll_interval_minutes' => '5',
        'headless_mode' => '0',
        'last_auto_trigger_ts' => (string)time(),
    ];
    $insSet = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $insSet->execute([$k, $v]);
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM watched_pages')->fetchColumn();
    if ($count === 0) {
        $nowStr = date('Y-m-d H:i:s');
        $seeds = [
            ['https://www.facebook.com/bbcnews', 'bbcnews'],
            ['https://www.facebook.com/cnn', 'cnn'],
            ['https://www.facebook.com/RealAmericasVoice', 'RealAmericasVoice'],
        ];
        $insPage = $db->prepare(
            'INSERT OR IGNORE INTO watched_pages (fb_url, label, enabled, last_status, created_at) VALUES (?, ?, 1, "Ready to monitor", ?)'
        );
        foreach ($seeds as [$u, $lbl]) {
            $insPage->execute([$u, $lbl, $nowStr]);
        }
    }

    init_memory_db();
}

function init_memory_db(): void
{
    $mem = get_memory_db();
    $mem->exec(
        'CREATE TABLE IF NOT EXISTS seen_posts (
            post_hash TEXT PRIMARY KEY,
            post_preview TEXT,
            published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function get_setting(string $key, string $default = ''): string
{
    init_db();
    $stmt = get_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function set_setting(string $key, string $value): void
{
    init_db();
    $stmt = get_db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $stmt->execute([$key, $value]);
}

function is_post_seen(string $postHash): bool
{
    init_memory_db();
    $stmt = get_memory_db()->prepare('SELECT 1 FROM seen_posts WHERE post_hash = ?');
    $stmt->execute([$postHash]);
    return $stmt->fetchColumn() !== false;
}

function mark_post_seen(string $postHash, string $postText): void
{
    init_memory_db();
    $preview = mb_strlen($postText) > 200 ? mb_substr($postText, 0, 200) . '...' : $postText;
    $stmt = get_memory_db()->prepare('INSERT OR IGNORE INTO seen_posts (post_hash, post_preview) VALUES (?, ?)');
    $stmt->execute([$postHash, $preview]);
}

function get_enabled_watched_pages(): array
{
    init_db();
    $rows = get_db()->query('SELECT id, fb_url, label FROM watched_pages WHERE enabled = 1 ORDER BY id ASC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $u = trim((string)$r['fb_url']);
        if ($u !== '') {
            $out[] = ['id' => (int)$r['id'], 'fb_url' => $u, 'label' => (string)$r['label']];
        }
    }
    return $out;
}

function update_watched_page_status(string $targetUrl, string $status, ?string $newLabel = null): void
{
    init_db();
    $nowStr = date('H:i:s');
    $cleanUrl = rtrim(trim($targetUrl), '/');
    $db = get_db();

    foreach (array_unique([$cleanUrl, trim($targetUrl)]) as $candidate) {
        if ($newLabel !== null && $newLabel !== '') {
            $stmt = $db->prepare('UPDATE watched_pages SET last_checked_at = ?, last_status = ?, label = ? WHERE fb_url = ?');
            $stmt->execute([$nowStr, $status, $newLabel, $candidate]);
        } else {
            $stmt = $db->prepare('UPDATE watched_pages SET last_checked_at = ?, last_status = ? WHERE fb_url = ?');
            $stmt->execute([$nowStr, $status, $candidate]);
        }
    }
}

function is_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);
        $joined = implode("\n", $out);
        return str_contains($joined, (string)$pid);
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

function is_chromium_profile_active(): bool
{
    if (is_file(AGENT_PID_FILE)) {
        $pid = (int)trim((string)@file_get_contents(AGENT_PID_FILE));
        if ($pid > 0 && is_pid_alive($pid)) {
            return true;
        }
        @unlink(AGENT_PID_FILE);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \\"Name = \'chrome.exe\'\\" | Where-Object { $_.CommandLine -like \'*fb_profile*\' }).Count"';
        $count = (int)trim((string)@shell_exec($cmd));
        return $count > 0;
    }

    $out = [];
    $code = 1;
    @exec('pgrep -f fb_profile 2>/dev/null', $out, $code);
    return $code === 0;
}

function is_agent_running(): bool
{
    return is_chromium_profile_active();
}

function launch_agent_bg(array $args): bool
{
    if (is_agent_running()) {
        return false;
    }

    $phpBin = PHP_BINARY ?: 'php';
    $escapedArgs = array_map('escapeshellarg', $args);
    $argStr = implode(' ', $escapedArgs);

    set_setting('last_auto_trigger_ts', (string)time());

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start "" /B ' . escapeshellarg($phpBin) . ' ' . escapeshellarg(AGENT_SCRIPT) . ($argStr !== '' ? ' ' . $argStr : '') . ' > NUL 2>&1';
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg(AGENT_SCRIPT) . ($argStr !== '' ? ' ' . $argStr : '') . ' >/dev/null 2>&1 &';
        exec($cmd);
    }
    return true;
}

function write_wa_status(string $state, string $message): void
{
    $dir = dirname(WA_STATUS_JSON_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        WA_STATUS_JSON_PATH,
        json_encode(['state' => $state, 'message' => $message, 'updated_at' => time()], JSON_UNESCAPED_UNICODE)
    );
}

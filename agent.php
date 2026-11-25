<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'organizer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cdp_client.php';

function app_test_site_url(): string
{
    $envUrl = trim((string)getenv('TEST_SITE_URL'));
    if ($envUrl !== '') {
        return rtrim($envUrl, '/') . '/';
    }
    $port = trim((string)getenv('PORT')) ?: '5000';
    return "http://127.0.0.1:{$port}/";
}

function human_delay(float $minSec = 0.6, float $maxSec = 1.4): void
{
    $us = random_int((int)($minSec * 1000000), (int)($maxSec * 1000000));
    usleep($us);
}

function download_image_to_temp(string $imageUrl, string $outputPath): ?string
{
    $res = http_fetch_raw($imageUrl, 20);
    if (!$res['ok'] || strlen($res['body']) < 256) {
        echo "      [!] Failed to download image: {$res['error']} (HTTP {$res['status']})\n";
        return null;
    }
    file_put_contents($outputPath, $res['body']);
    echo "      Downloaded post image -> {$outputPath}\n";
    return $outputPath;
}

// ==============================================================================
// Facebook Scraper (Direct Public Page + Cloud Plugin Fallback)
// ==============================================================================

function dismiss_facebook_dialogs(CdpPage $page): void
{
    $page->evaluate(
        '(function() {
            const selectors = [
                \'div[role="dialog"] [aria-label="Close"]\',
                \'div[role="dialog"] div[aria-label="Close"]\',
                \'button\',
                \'div[role="button"]\'
            ];
            for (const el of document.querySelectorAll(\'div[role="dialog"] [aria-label="Close"]\')) {
                el.click();
            }
            for (const btn of document.querySelectorAll(\'button, div[role="button"]\')) {
                const t = (btn.innerText || "").trim().toLowerCase();
                if (t === "allow all cookies" || t === "decline optional cookies" || t === "not now") {
                    btn.click();
                }
            }
        })()'
    );
}

function extract_from_facebook_direct(CdpPage $page, string $targetUrl): array
{
    echo "[1/5] Navigating directly to Facebook page: {$targetUrl} ...\n";
    $page->goto($targetUrl, 2800);
    dismiss_facebook_dialogs($page);
    human_delay(0.8, 1.4);

    echo "      Scrolling down ~450px to render the latest feed post...\n";
    $page->wheel(0, 450);
    human_delay(2.0, 2.8);
    dismiss_facebook_dialogs($page);

    if (!$page->waitForSelector('[role="article"]', 12000)) {
        return ['caption' => '', 'image' => null, 'link' => null];
    }

    // Expand "See more" on first few articles and extract clean text, external link, and photo
    $raw = $page->evaluate(
        '(async function() {
            const sleep = (ms) => new Promise(r => setTimeout(r, ms));
            const articles = Array.from(document.querySelectorAll(\'[role="article"]\')).slice(0, 5);
            for (const art of articles) {
                const rect = art.getBoundingClientRect();
                if (rect.height < 40) continue;

                // Click "See more" if present
                for (const btn of art.querySelectorAll(\'div[role="button"], span\')) {
                    const bt = (btn.innerText || "").trim().toLowerCase();
                    if (bt === "see more") {
                        btn.scrollIntoView({ block: "center" });
                        btn.click();
                        await sleep(900);
                        break;
                    }
                }

                // Extract caption
                const cleanNodeText = (node) => {
                    const clone = node.cloneNode(true);
                    for (const b of clone.querySelectorAll(\'[role="button"], span, div\')) {
                        const t = (b.innerText || b.textContent || "").trim().toLowerCase();
                        if (t === "see more" || t === "see less" || t === "… see more" || t === "... see more" ||
                            /^view\\s+(all\\s+|more\\s+|previous\\s+)?\\d*\\s*(replies|reply|comments?)$/i.test(t)) {
                            b.remove();
                        }
                    }
                    return (clone.innerText || clone.textContent || "").trim();
                };

                let blocks = [];
                const dedicated = art.querySelector(\'[data-ad-preview="message"], [data-ad-comet-preview="message"]\');
                if (dedicated) {
                    const mt = cleanNodeText(dedicated);
                    if (mt) blocks = [mt];
                } else {
                    const actionBar = art.querySelector(\'[aria-label="Like"], [aria-label="Comment"], [aria-label="Leave a comment"], [aria-label="Share"]\');
                    for (const el of art.querySelectorAll(\'div[dir="auto"], span[dir="auto"]\')) {
                        const pa = el.closest(\'[role="article"]\');
                        if (pa && pa !== art) continue;
                        if (el.closest(\'[role="button"], button, form, ul, [aria-label*="comment" i]\')) continue;
                        if (actionBar && (actionBar.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) continue;
                        const a = el.closest("a[href]");
                        if (a) {
                            const h = a.getAttribute("href") || "";
                            if (h.includes("l.facebook.com/l.php") || (h.startsWith("http") && !h.includes("facebook.com"))) continue;
                        }
                        if (el.querySelector(\'div[dir="auto"]\')) continue;
                        const txt = cleanNodeText(el);
                        if (txt) blocks.push(txt);
                    }
                }

                // Extract external links
                const hrefs = [];
                for (const a of art.querySelectorAll("a[href]")) {
                    const pa = a.closest(\'[role="article"]\');
                    if (pa && pa !== art) continue;
                    if (a.closest(\'form, [aria-label*="comment" i]\')) continue;
                    if (a.href) hrefs.push(a.href);
                }

                // Extract images (direct scontent > 200x200 or link thumbnail > 140x120)
                const directPhotos = [];
                const linkThumbs = [];
                for (const img of art.querySelectorAll("img")) {
                    const pa = img.closest(\'[role="article"]\');
                    if (pa && pa !== art) continue;
                    const src = img.currentSrc || img.src || "";
                    if (!src.startsWith("http")) continue;
                    const ir = img.getBoundingClientRect();
                    if (src.includes("scontent") && ir.width > 200 && ir.height > 200) {
                        directPhotos.push(src);
                    } else if ((src.includes("fbcdn.net") || src.includes("external") || src.includes("scontent")) && ir.width > 140 && ir.height > 120) {
                        linkThumbs.push(src);
                    }
                }
                for (const svgImg of art.querySelectorAll("svg image")) {
                    const href = svgImg.getAttribute("xlink:href") || svgImg.getAttribute("href") || "";
                    const sr = svgImg.getBoundingClientRect();
                    if (href.startsWith("http") && sr.width > 140 && sr.height > 120) {
                        linkThumbs.push(href);
                    }
                }

                const caption = blocks.join("\\n").trim();
                const imageUrl = directPhotos[0] || linkThumbs[0] || null;
                if (!caption && !imageUrl) continue;

                return { caption, hrefs, imageUrl };
            }
            return null;
        })()'
    );

    if (!is_array($raw)) {
        return ['caption' => '', 'image' => null, 'link' => null];
    }

    $externalLink = null;
    foreach ($raw['hrefs'] ?? [] as $h) {
        $cleaned = clean_facebook_redirect_url((string)$h);
        if ($cleaned !== null) {
            $externalLink = $cleaned;
            break;
        }
    }

    return [
        'caption' => trim((string)($raw['caption'] ?? '')),
        'image' => $raw['imageUrl'] ?? null,
        'link' => $externalLink,
    ];
}

function extract_from_facebook_plugin_fallback(CdpPage $page, string $targetUrl): array
{
    $pluginUrl = 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($targetUrl) . '&tabs=timeline&width=500&height=800';
    echo "      [Facebook Plugin Fallback] Trying cloud-proof timeline endpoint...\n";
    $page->goto($pluginUrl, 3500);

    $raw = $page->evaluate(
        '(function() {
            const imgs = Array.from(document.querySelectorAll("img"))
                .filter(i => {
                    const r = i.getBoundingClientRect();
                    return i.src && i.src.startsWith("http") && r.width > 200 && r.height > 140 && r.top > 180;
                })
                .map(i => i.src);
            const hrefs = Array.from(document.querySelectorAll("a[href]"))
                .map(a => a.href)
                .filter(h => h.includes("l.facebook.com/l.php") || (h.startsWith("http") && !h.includes("facebook.com")));

            const lines = (document.body.innerText || "").split(/\\r?\\n/).map(s => s.trim()).filter(Boolean);
            // Skip top header lines ("Follow", follower count, etc.) and take the first post body lines
            let startIdx = 0;
            for (let i = 0; i < Math.min(lines.length, 15); i++) {
                if (lines[i].toLowerCase() === "follow" || /^\\d+[.,\\d]*[kmb]?\\s+followers$/i.test(lines[i])) {
                    startIdx = i + 1;
                }
            }
            // Skip author name + timestamp ("3h", "1d", etc.)
            const body = lines.slice(startIdx).filter(l => !/^\\d+[hmwd]$/i.test(l) && !/^(like|comment|share|see more)$/i.test(l));
            return {
                caption: body.slice(1, 10).join("\\n").trim(),
                imageUrl: imgs[0] || null,
                hrefs
            };
        })()'
    );

    if (!is_array($raw)) {
        return ['caption' => '', 'image' => null, 'link' => null];
    }

    $externalLink = null;
    foreach ($raw['hrefs'] ?? [] as $h) {
        $cleaned = clean_facebook_redirect_url((string)$h);
        if ($cleaned !== null) {
            $externalLink = $cleaned;
            break;
        }
    }

    return [
        'caption' => trim((string)($raw['caption'] ?? '')),
        'image' => $raw['imageUrl'] ?? null,
        'link' => $externalLink,
    ];
}

function process_facebook_url(CdpPage $page, string $targetUrl): bool
{
    update_watched_page_status($targetUrl, 'Scanning page...');
    try {
        $data = extract_from_facebook_direct($page, $targetUrl);
        if ($data['caption'] === '' && $data['image'] === null) {
            $data = extract_from_facebook_plugin_fallback($page, $targetUrl);
        }

        $caption = $data['caption'];
        $imageUrl = $data['image'];
        $externalLink = $data['link'];

        if ($caption === '' && $imageUrl === null && $externalLink === null) {
            update_watched_page_status($targetUrl, 'No eligible post found');
            return false;
        }

        $downloadedImage = null;
        if ($imageUrl) {
            if (is_file(TEMP_IMAGE_PATH)) {
                @unlink(TEMP_IMAGE_PATH);
            }
            $downloadedImage = download_image_to_temp($imageUrl, TEMP_IMAGE_PATH);
        }

        if ($caption === '') {
            $caption = "(Image post from {$targetUrl})";
        }

        echo "[3/5] Checking SQLite deduplication database (agent_memory.db)...\n";
        $postHash = hash('sha256', $caption . '|' . ($externalLink ?? ''));
        if (is_post_seen($postHash)) {
            echo "      Post already published (SHA-256: " . substr($postHash, 0, 12) . "...). Skipping.\n";
            update_watched_page_status($targetUrl, 'Up to date (no new post)');
            return false;
        }

        $fbTitle = $page->title();
        publish_to_elo54_frontpage($page, $caption, $downloadedImage, $externalLink, "{$fbTitle}\n{$targetUrl}");
        mark_post_seen($postHash, $caption);
        update_watched_page_status($targetUrl, '✅ Published new post!');
        return true;
    } catch (Throwable $exc) {
        echo "      [!] Error checking {$targetUrl}: {$exc->getMessage()}\n";
        update_watched_page_status($targetUrl, 'Scan error: ' . substr($exc->getMessage(), 0, 45));
        return false;
    } finally {
        if (is_file(TEMP_IMAGE_PATH)) {
            @unlink(TEMP_IMAGE_PATH);
        }
    }
}

// ==============================================================================
// WhatsApp Channel Scraper (No Follow Required + Live QR Linker)
// ==============================================================================

function fetch_whatsapp_public_metadata(string $targetUrl): array
{
    $path = trim((string)(parse_url(trim($targetUrl), PHP_URL_PATH) ?? ''), '/');
    $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));
    $isSinglePost = count($parts) >= 3 && strtolower($parts[0]) === 'channel';

    $channelName = null;
    $ogDesc = null;
    $ogImage = null;

    $res = http_fetch_raw($targetUrl, 12);
    if ($res['ok']) {
        $html = $res['body'];
        if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $rawT = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $channelName = trim((string)preg_replace('/\s*\|\s*WhatsApp\s+Channel\s*$/i', '', $rawT));
        }
        if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $ogDesc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $ogImage = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }

    return [$channelName, $ogDesc, $ogImage, $isSinglePost];
}

function dismiss_whatsapp_dialogs(CdpPage $page): void
{
    for ($i = 0; $i < 3; $i++) {
        $dismissed = $page->evaluate(
            '(function() {
                const dlg = document.querySelector(\'div[role="dialog"][aria-modal="true"], div[role="dialog"]\');
                if (!dlg) return false;
                const btns = Array.from(dlg.querySelectorAll(\'button, div[role="button"]\'));
                for (const b of btns) {
                    const t = (b.innerText || "").trim().toLowerCase();
                    const a = (b.getAttribute("aria-label") || "").toLowerCase();
                    if (t === "continue" || t === "ok" || a === "close") {
                        b.click();
                        return true;
                    }
                }
                if (btns[0]) {
                    btns[0].click();
                    return true;
                }
                return false;
            })()'
        );
        if (!$dismissed) {
            break;
        }
        usleep(700000);
    }
}

function extract_latest_whatsapp_web_message(CdpPage $page, string $channelName, string $channelUrl): array
{
    echo "      [WhatsApp Web] Opening https://web.whatsapp.com/ ...\n";
    if (!str_contains($page->url(), 'web.whatsapp.com')) {
        $page->goto('https://web.whatsapp.com/', 3000);
    }

    $loaded = $page->waitForSelector('#pane-side, [aria-label="Channels"], [data-icon*="newsletter"], [aria-label="Chats"], canvas', 55000);
    if (!$loaded) {
        throw new RuntimeException('WhatsApp Web timed out while loading.');
    }

    $needsQr = $page->evaluate(
        '(function() {
            const hasQr = document.querySelector(\'canvas[aria-label*="Scan"]\') !== null;
            const hasApp = document.querySelector(\'#pane-side, [aria-label="Chats"], [aria-label="Channels"]\') !== null;
            return hasQr && !hasApp;
        })()'
    );
    if ($needsQr) {
        throw new RuntimeException('Waiting for WhatsApp sync');
    }

    human_delay(2.5, 3.5);
    dismiss_whatsapp_dialogs($page);

    // 1. Open Channels tab and look for followed channel
    $page->evaluate(
        '(function() {
            const sels = [\'[aria-label="Channels"]\', \'[title="Channels"]\', \'span[data-icon="newsletter-tab"]\', \'span[data-icon="newsletter-outline"]\'];
            for (const s of sels) {
                const el = document.querySelector(s);
                if (el) { el.click(); return true; }
            }
            return false;
        })()'
    );
    human_delay(1.5, 2.2);
    dismiss_whatsapp_dialogs($page);

    $channelOpened = (bool)$page->callJsFunction(
        'function(name) {
            const el = document.querySelector("span[title=\\"" + name.replace(/"/g, "\\\\\\"") + "\\"]");
            if (el) { el.click(); return true; }
            return false;
        }',
        [$channelName]
    );

    if ($channelOpened) {
        human_delay(1.8, 2.5);
    } else {
        // 2. Open unfollowed channel in Preview Mode via "(You)" Self-Chat invite link click
        echo "      [WhatsApp Web] Opening unfollowed channel '{$channelName}' via '(You)' self-chat link...\n";
        $page->evaluate(
            '(function() {
                for (const s of [\'[aria-label="Chats"]\', \'[title="Chats"]\', \'span[data-icon="chat"]\']) {
                    const el = document.querySelector(s);
                    if (el) { el.click(); break; }
                }
            })()'
        );
        human_delay(1.2, 1.8);

        $selfOpened = (bool)$page->evaluate(
            '(function() {
                for (const sp of document.querySelectorAll("#pane-side span")) {
                    if ((sp.innerText || "").includes("(You)")) {
                        sp.click();
                        return true;
                    }
                }
                return false;
            })()'
        );

        if (!$selfOpened) {
            $page->clickSelector('input[aria-label="Search or start a new chat"], input[placeholder*="chat" i]');
            $page->typeText('(You)');
            human_delay(1.8, 2.5);
            $selfOpened = (bool)$page->evaluate(
                '(function() {
                    for (const sp of document.querySelectorAll("#pane-side span")) {
                        if ((sp.innerText || "").includes("(You)")) {
                            sp.click();
                            return true;
                        }
                    }
                    return false;
                })()'
            );
            human_delay(1.5, 2.2);
        }

        if (!$selfOpened || !$page->waitForSelector('#main', 15000)) {
            throw new RuntimeException("Could not open '(You)' self-chat to preview '{$channelName}'.");
        }

        $urlParts = array_values(array_filter(explode('/', rtrim($channelUrl, '/'))));
        $inviteCode = end($urlParts) ?: '';

        $hasLink = (bool)$page->callJsFunction(
            'function(code) { return document.querySelectorAll("#main a[href*=\\"" + code + "\\"]").length > 0; }',
            [$inviteCode]
        );

        if (!$hasLink) {
            $page->clickSelector('#main footer div[contenteditable="true"]');
            usleep(300000);
            $page->typeText($channelUrl);
            human_delay(1.0, 1.5);
            $page->pressKey('Enter', 13);
            human_delay(2.5, 3.2);
        }

        $clickedPreview = (bool)$page->callJsFunction(
            'function(code) {
                const btns = Array.from(document.querySelectorAll("#main div[role=\'button\'], #main button, #main span"));
                for (let i = btns.length - 1; i >= 0; i--) {
                    if ((btns[i].innerText || "").trim() === "View channel") {
                        btns[i].click();
                        return true;
                    }
                }
                const anchors = Array.from(document.querySelectorAll("#main a[href*=\\"" + code + "\\"]"));
                if (anchors.length > 0) {
                    const lastA = anchors[anchors.length - 1];
                    lastA.removeAttribute("target");
                    lastA.removeAttribute("rel");
                    lastA.click();
                    return true;
                }
                return false;
            }',
            [$inviteCode]
        );

        if (!$clickedPreview) {
            throw new RuntimeException("Could not click 'View channel' for '{$channelName}'.");
        }

        // Wait until #main header switches away from "(You)"
        $deadline = microtime(true) + 15.0;
        $switched = false;
        while (microtime(true) < $deadline) {
            $switched = (bool)$page->evaluate(
                '(function() { const h = document.querySelector("#main header"); return Boolean(h && !h.innerText.includes("(You)")); })()'
            );
            if ($switched) {
                break;
            }
            usleep(400000);
        }
        if (!$switched) {
            throw new RuntimeException("Channel '{$channelName}' preview did not load.");
        }
        human_delay(2.5, 3.5);
    }

    $page->waitForSelector('#main', 15000);
    human_delay(1.2, 2.0);

    // Expand "Read more" if truncated on bottom message
    $page->evaluate(
        '(function() {
            const rms = Array.from(document.querySelectorAll("#main span[role=\'button\'], #main div[role=\'button\']"))
                .filter(el => (el.innerText || "").trim().toLowerCase() === "read more");
            if (rms.length > 0) rms[rms.length - 1].click();
        })()'
    );
    human_delay(0.6, 1.0);

    // Extract leaf text nodes + external link + canvas-converted blob image from bottom message bubble
    $msgData = $page->evaluate(
        '(function() {
            const mainEl = document.querySelector("#main");
            if (!mainEl) return null;

            const rows = Array.from(mainEl.querySelectorAll(\'div[role="row"], div.message-in, div[data-id]\'));
            for (let i = rows.length - 1; i >= 0; i--) {
                const row = rows[i];

                let candidates = Array.from(row.querySelectorAll("span.selectable-text.copyable-text"));
                if (candidates.length === 0) {
                    candidates = Array.from(row.querySelectorAll(\'.selectable-text, span[dir="ltr"], div[dir="auto"]\'));
                }
                const leafNodes = candidates.filter(el => !candidates.some(other => other !== el && el.contains(other)));
                const nonPreviewNodes = leafNodes.filter(el => !el.closest("a[href]"));
                const targetNodes = nonPreviewNodes.length > 0 ? nonPreviewNodes : leafNodes;

                const lines = [];
                const seenLines = new Set();
                for (const tn of targetNodes) {
                    if (tn.closest(\'[role="button"]\')) continue;
                    const raw = (tn.innerText || "").trim();
                    if (!raw) continue;
                    for (const line of raw.split(/\\r?\\n/)) {
                        const cleanLine = line.trim();
                        if (!cleanLine) continue;
                        const norm = cleanLine.toLowerCase();
                        if (!seenLines.has(norm)) {
                            seenLines.add(norm);
                            lines.push(cleanLine);
                        }
                    }
                }
                const fullText = lines.join("\\n").trim();

                let externalLink = null;
                for (const a of row.querySelectorAll(\'a[href^="http"]\')) {
                    if (!a.href.includes("whatsapp.com")) {
                        externalLink = a.href;
                        break;
                    }
                }

                let imageDataUrl = null;
                for (const img of row.querySelectorAll("img")) {
                    const rect = img.getBoundingClientRect();
                    if (rect.width > 130 && rect.height > 110 && img.complete && img.naturalWidth > 130) {
                        try {
                            const canvas = document.createElement("canvas");
                            canvas.width = img.naturalWidth;
                            canvas.height = img.naturalHeight;
                            const ctx = canvas.getContext("2d");
                            ctx.drawImage(img, 0, 0);
                            imageDataUrl = canvas.toDataURL("image/jpeg", 0.92);
                            break;
                        } catch (e) {
                            if (img.src && img.src.startsWith("http")) {
                                imageDataUrl = img.src;
                                break;
                            }
                        }
                    }
                }

                if (fullText || imageDataUrl) {
                    return { fullText, externalLink, imageDataUrl };
                }
            }
            return null;
        })()'
    );

    if (!is_array($msgData)) {
        throw new RuntimeException("No messages found inside WhatsApp Channel '{$channelName}'.");
    }

    $cleanedLines = [];
    foreach (preg_split('/\r?\n/', (string)($msgData['fullText'] ?? '')) as $line) {
        $s = trim((string)preg_replace('/(?:\s*(?:\.\.\.|…)?\s*\b(?:see\s+more|see\s+less|read\s+more)\b)+\s*$/iu', '', $line));
        if ($s === '' || ctype_digit($s) || is_comment_ui_line($s)) {
            continue;
        }
        $cleanedLines[] = $s;
    }
    $caption = trim(implode("\n", $cleanedLines));

    $savedImagePath = null;
    $imgData = $msgData['imageDataUrl'] ?? null;
    if (is_string($imgData) && $imgData !== '') {
        if (is_file(TEMP_IMAGE_PATH)) {
            @unlink(TEMP_IMAGE_PATH);
        }
        if (str_starts_with($imgData, 'data:image') && str_contains($imgData, ',')) {
            [, $b64] = explode(',', $imgData, 2);
            file_put_contents(TEMP_IMAGE_PATH, base64_decode($b64));
            $savedImagePath = TEMP_IMAGE_PATH;
            echo "      [WhatsApp Web] Extracted channel image -> " . TEMP_IMAGE_PATH . "\n";
        } elseif (str_starts_with($imgData, 'http')) {
            $savedImagePath = download_image_to_temp($imgData, TEMP_IMAGE_PATH);
        }
    }

    return [$caption, $savedImagePath, $msgData['externalLink'] ?? null];
}

function process_whatsapp_channel_url(CdpPage $page, string $targetUrl): bool
{
    update_watched_page_status($targetUrl, 'Checking WhatsApp Channel...');
    [$channelName, $ogDesc, $ogImage, $isSinglePost] = fetch_whatsapp_public_metadata($targetUrl);

    if ($channelName) {
        update_watched_page_status($targetUrl, "Scanning {$channelName}...", $channelName);
    }

    try {
        if ($isSinglePost && $ogDesc) {
            $caption = $ogDesc;
            $downloadedImage = $ogImage ? download_image_to_temp($ogImage, TEMP_IMAGE_PATH) : null;
            preg_match_all('/https?:\/\/\S+/iu', $caption, $um);
            $externalLink = !empty($um[0]) ? rtrim($um[0][0], '.,;)') : $targetUrl;
        } else {
            if (!$channelName) {
                throw new RuntimeException('Could not resolve WhatsApp Channel name from link.');
            }
            [$caption, $downloadedImage, $externalLink] = extract_latest_whatsapp_web_message($page, $channelName, $targetUrl);
            if (!$externalLink) {
                preg_match_all('/https?:\/\/\S+/iu', $caption, $um);
                $filteredUrls = array_values(array_filter($um[0] ?? [], fn($u) => !str_contains(strtolower($u), 'whatsapp.com')));
                $externalLink = !empty($filteredUrls) ? rtrim($filteredUrls[0], '.,;)') : null;
            }
        }

        if ($caption === '' && $downloadedImage === null) {
            update_watched_page_status($targetUrl, 'No eligible post found', $channelName);
            return false;
        }

        if ($caption === '') {
            $caption = '(Image update from ' . ($channelName ?: 'WhatsApp Channel') . ')';
        }

        $postHash = hash('sha256', 'WA|' . $caption . '|' . ($externalLink ?? ''));
        if (is_post_seen($postHash)) {
            echo "      WhatsApp post already published (SHA-256: " . substr($postHash, 0, 12) . "...). Skipping.\n";
            update_watched_page_status($targetUrl, 'Up to date (no new post)', $channelName);
            return false;
        }

        publish_to_elo54_frontpage($page, $caption, $downloadedImage, $externalLink, ($channelName ?? '') . "\n" . ($ogDesc ?? '') . "\n" . $targetUrl);
        mark_post_seen($postHash, $caption);
        update_watched_page_status($targetUrl, '✅ Published new WhatsApp post!', $channelName);
        return true;
    } catch (Throwable $exc) {
        echo "      [!] WhatsApp Channel check failed: {$exc->getMessage()}\n";
        update_watched_page_status($targetUrl, '⚠️ ' . substr($exc->getMessage(), 0, 60), $channelName);
        return false;
    } finally {
        if (is_file(TEMP_IMAGE_PATH)) {
            @unlink(TEMP_IMAGE_PATH);
        }
    }
}

// ==============================================================================
// Private Admin WhatsApp QR Code Streamer (--connect-whatsapp)
// ==============================================================================

function connect_whatsapp_interactive(): int
{
    write_wa_status('starting', 'Launching WhatsApp Web and loading QR code...');
    $browser = null;

    try {
        $browser = new CdpBrowser(PROFILE_DIR, false);
        $page = $browser->getPage();
        $page->goto('https://web.whatsapp.com/', 2000);

        $startTs = time();
        $connectedSince = null;

        while ((time() - $startTs) < 240) {
            try {
                $page->screenshot(WA_LIVE_IMG_PATH);
            } catch (Throwable) {
            }

            $state = $page->evaluate(
                '(function() {
                    const hasApp = document.querySelector(\'#pane-side, [aria-label="Channels"], [data-icon*="newsletter"], [aria-label="Chats"], [aria-label="Chat list"]\') !== null;
                    const hasScanQr = document.querySelector(\'canvas[aria-label*="Scan" i]\') !== null;
                    const hasAnyCanvas = document.querySelector("canvas") !== null;
                    return { loggedIn: hasApp && !hasScanQr, hasQr: hasScanQr || hasAnyCanvas };
                })()'
            );

            if (!empty($state['loggedIn'])) {
                if ($connectedSince === null) {
                    $connectedSince = time();
                    echo "[WhatsApp Connect] WhatsApp Web is linked! Saving session...\n";
                }
                write_wa_status('connected', '✅ WhatsApp Web is connected and saved! You can close this popup and add any WhatsApp Channel link.');
                if ((time() - $connectedSince) >= 8) {
                    break;
                }
            } elseif (!empty($state['hasQr'])) {
                write_wa_status('qr_ready', '📱 Scan this QR code right now with your phone (WhatsApp → Linked Devices → Link a Device).');
            } else {
                write_wa_status('loading', 'Loading WhatsApp Web QR code...');
            }

            usleep(1500000);
        }

        return 0;
    } catch (Throwable $exc) {
        write_wa_status('error', 'Error: ' . $exc->getMessage());
        return 1;
    } finally {
        if ($browser) {
            $browser->close();
        }
    }
}

// ==============================================================================
// Elo54 Pulse Modal Publisher
// ==============================================================================

function publish_to_elo54_frontpage(
    CdpPage $page,
    string $rawCaption,
    ?string $imagePath,
    ?string $externalLink = null,
    string $sourceContext = ''
): void {
    [$headline, $details, $sourceLink] = organize_post_for_elo54($rawCaption, $externalLink);
    if ($headline === '' || str_starts_with(strtolower($headline), 'http')) {
        echo "      [!] Skipping post with no non-URL text headline.\n";
        return;
    }

    $detectedCity = detect_actual_location($rawCaption, $sourceContext . "\n" . ($sourceLink ?? ''));
    $siteUrl = app_test_site_url();

    echo "[4/5] Opening Elo54 Pulse Frontpage: {$siteUrl} ...\n";
    $page->goto($siteUrl, 1200);

    echo "      Clicking '+ Post News / Event' to open the modal...\n";
    $page->clickSelector('#open-post-modal-btn');
    $page->waitForSelector('#post-modal-backdrop.open', 10000);
    human_delay(0.5, 0.9);

    echo "[5/5] Organizing and filling 'Shine a Light on Your Campus or City' modal...\n";
    $page->clickSelector('.cat-tab[data-cat="News / Gist"]');

    $page->callJsFunction(
        'function(loc) {
            const sel = document.querySelector("#campus-city");
            if (!sel) return;
            if (!loc) {
                sel.value = "";
            } else {
                let opt = Array.from(sel.options).find(o => o.value.toLowerCase() === loc.toLowerCase());
                if (!opt) {
                    opt = document.createElement("option");
                    opt.value = loc;
                    opt.textContent = loc;
                    sel.appendChild(opt);
                }
                sel.value = opt.value;
            }
            sel.dispatchEvent(new Event("change", { bubbles: true }));
        }',
        [$detectedCity]
    );
    echo '      Campus or City : ' . ($detectedCity !== '' ? $detectedCity : '(blank)') . "\n";

    echo "      Headline       : {$headline}\n";
    $page->fillInput('#post-headline', $headline);
    human_delay(0.3, 0.6);

    echo '      Details        : ' . mb_substr($details, 0, 90) . (mb_strlen($details) > 90 ? '...' : '') . "\n";
    $page->fillInput('#post-text', $details);
    human_delay(0.3, 0.6);

    if ($imagePath !== null && is_file($imagePath)) {
        echo "      Image          : {$imagePath}\n";
        $page->setInputFiles('#post-image', $imagePath);
        human_delay(0.4, 0.7);
    }

    if ($sourceLink !== null && $sourceLink !== '') {
        echo "      Source Link    : {$sourceLink}\n";
        $page->fillInput('#source-link', $sourceLink);
        human_delay(0.3, 0.6);
    }

    echo "      Clicking 'Send for Review'...\n";
    $page->clickSelector('#publish-btn');
    human_delay(2.0, 3.0);
    $page->waitForSelector('#live-feed .post-card', 15000);
    echo "      Success! Organized post published to Elo54 Pulse Live Feed.\n";
}

function process_single_watched_url(CdpPage $page, string $targetUrl): bool
{
    if (is_whatsapp_url($targetUrl)) {
        return process_whatsapp_channel_url($page, $targetUrl);
    }
    return process_facebook_url($page, $targetUrl);
}

function run_browser_session(string $mode, ?string $explicitUrl = null): int
{
    init_db();
    $headless = get_setting('headless_mode', '0') === '1';
    $browser = null;

    try {
        $browser = new CdpBrowser(PROFILE_DIR, $headless);
        $page = $browser->getPage();

        if ($mode === 'repost_last') {
            $row = get_db()->query('SELECT content, image_filename, source_url FROM posts ORDER BY id DESC LIMIT 1')->fetch();
            if (!$row) {
                throw new RuntimeException('No existing post found in sandbox_posts.db.');
            }
            $imgPath = !empty($row['image_filename']) ? (UPLOAD_FOLDER . DIRECTORY_SEPARATOR . $row['image_filename']) : null;
            if ($imgPath && !is_file($imgPath)) {
                $imgPath = null;
            }
            publish_to_elo54_frontpage($page, (string)$row['content'], $imgPath, $row['source_url'] ?? null);
            return 0;
        }

        if ($mode === 'watch_cycle') {
            $pages = get_enabled_watched_pages();
            if (empty($pages)) {
                echo "[Watch Cycle] No enabled links in watchlist.\n";
                return 0;
            }
            $total = count($pages);
            echo "[Watch Cycle] Scanning {$total} active link(s)...\n";
            $newPosts = 0;
            foreach ($pages as $idx => $wp) {
                $n = $idx + 1;
                echo "\n--- [{$n}/{$total}] Checking {$wp['label']} ({$wp['fb_url']}) ---\n";
                if (process_single_watched_url($page, $wp['fb_url'])) {
                    $newPosts++;
                }
                if ($n < $total) {
                    human_delay(2.0, 4.0);
                }
            }
            echo "\n[Watch Cycle Complete] Published {$newPosts} new post(s) across {$total} link(s).\n";
            return 0;
        }

        $targetUrl = $explicitUrl ?: (getenv('FB_TARGET_URL') ?: 'https://www.facebook.com/bbcnews');
        process_single_watched_url($page, $targetUrl);
        return 0;
    } catch (Throwable $exc) {
        echo "[ERROR] Agent workflow failed: {$exc->getMessage()}\n";
        return 1;
    } finally {
        if ($browser) {
            $browser->close();
        }
    }
}

// ==============================================================================
// CLI Entrypoint
// ==============================================================================

if (PHP_SAPI === 'cli') {
    file_put_contents(AGENT_PID_FILE, (string)getmypid());
    $arg = trim((string)($argv[1] ?? ''));
    $exitCode = 0;

    try {
        if ($arg === '--connect-whatsapp') {
            $exitCode = connect_whatsapp_interactive();
        } elseif ($arg === '--repost-last') {
            $exitCode = run_browser_session('repost_last');
        } elseif ($arg === '--watch-cycle') {
            $exitCode = run_browser_session('watch_cycle');
        } elseif ($arg === '--daemon') {
            echo "=== Starting Standalone 5-Minute Multi-Page PHP Auto-Pilot Daemon ===\n";
            while (true) {
                $intervalMins = max(1, (int)(get_setting('poll_interval_minutes', '5') ?: '5'));
                run_browser_session('watch_cycle');
                echo "[Daemon] Sleeping for {$intervalMins} minutes until next check...\n";
                sleep($intervalMins * 60);
            }
        } elseif (str_starts_with($arg, 'http')) {
            $exitCode = run_browser_session('single', $arg);
        } elseif (!empty(get_enabled_watched_pages())) {
            $exitCode = run_browser_session('watch_cycle');
        } else {
            $exitCode = run_browser_session('single', getenv('FB_TARGET_URL') ?: 'https://www.facebook.com/bbcnews');
        }
    } finally {
        if (is_file(AGENT_PID_FILE)) {
            @unlink(AGENT_PID_FILE);
        }
    }

    exit($exitCode);
}

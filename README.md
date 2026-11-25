# Elo54 Pulse — Pure PHP Autonomous Facebook & WhatsApp Channel News Agent

> Connect your WhatsApp at [`/admin/whatsapp-qr`](/admin/whatsapp-qr)

A **100% Pure PHP 8** autonomous news scraping, organization, and publishing platform (zero Python dependencies). PHP controls a persistent Chromium/Chrome browser directly over **Chrome DevTools Protocol (CDP WebSockets)** and stores all state in **SQLite (`PDO_SQLITE`)**.

---

## 1. Folder Structure

```text
elo agent/
├── index.php             # Main PHP web router, Watchlist controller, "+ Post News / Event" modal & live feed UI
├── db.php                # SQLite PDO manager (`sandbox_posts.db` & `agent_memory.db`) & background runner
├── organizer.php         # 4-Tier Location Detector & Elo54 post text organizer in pure PHP
├── cdp_client.php        # Pure PHP Chrome DevTools Protocol (CDP) RFC-6455 WebSocket browser driver
├── agent.php             # Autonomous CLI scraper (Facebook + WhatsApp Channels) & modal publisher
├── Dockerfile            # Pure PHP 8 + Google Chrome + Xvfb Docker container for Render
├── render.yaml           # Render deployment blueprint
└── static/
    └── uploads/          # Saved post images
```

---

## 2. How It Works

1. **Watchlist & 5-Minute Auto-Pilot (`index.php` & `db.php`):**
   * Paste unlimited **Facebook Page** (`facebook.com/...`) or **WhatsApp Channel** (`whatsapp.com/channel/...`) URLs.
   * For WhatsApp Channel links, PHP fetches `<meta property="og:title">` via `curl` to resolve the real channel name automatically.
   * Every 5 minutes (or immediately via **Check Now**), `launch_agent_bg()` spawns `php agent.php --watch-cycle` in the background.
2. **Pure PHP CDP Browser Automation (`cdp_client.php` & `agent.php`):**
   * Launches Chromium with `--user-data-dir="./fb_profile"` and `--remote-debugging-port=<free_port>`.
   * Connects directly over `stream_socket_client` WebSockets (`Page.navigate`, `Runtime.evaluate`, `DOM.setFileInputFiles`, `Input.dispatchMouseEvent`, `Page.captureScreenshot`).
   * **Facebook Pages:** No login required; scrapes `[role="article"]` (or falls back to Facebook's public `plugins/page.php` timeline if blocked on cloud IPs), expands `"See more"`, unwraps `l.facebook.com/l.php?u=...` links, and downloads the first content photo or link thumbnail.
   * **WhatsApp Channels:** No need to follow channels; opens unfollowed channels in Preview Mode via `(You)` self-chat `"View channel"`, extracts leaf text nodes (never duplicating lines), and converts `blob:` photos to JPEG via `<canvas>`.
3. **SHA-256 Deduplication (`agent_memory.db`):**
   * Hashes every extracted post in `seen_posts` so old news is never reposted.
4. **4-Tier Location Detector & Organizer (`organizer.php`):**
   * Detects **Nigerian Campuses** (`UNILAG`, `FUOYE`, `UI`, `FUTA`, etc.), **All 36 Nigerian States + FCT + Cities**, and **Foreign Locations** (`Florida, USA`, `San Francisco, USA`, `United Kingdom`, etc.) — leaving location blank when uncertain.
   * Formats `<= 140`-character Headlines, strips `"See more"` / `"View all X replies"` / empty `LinkedIn:/X:/YouTube:` footers, and moves external URLs into **Source / Registration Link**.
5. **Private Admin WhatsApp QR Linker (`/admin/whatsapp-qr`):**
   * Visit `/admin/whatsapp-qr` once to open the live QR code stream (`static/wa_live.png` + `/wa-status`), scan with your phone, and link `./fb_profile` permanently for all users.

---

## 3. Run Locally (PHP 8.2+)

```powershell
cd "c:\Users\Delice\Desktop\elo agent"
php -S 127.0.0.1:5000 -t . index.php
```
* Dashboard: `http://127.0.0.1:5000/`
* JSON API: `http://127.0.0.1:5000/api/posts.json`
* Private Admin WhatsApp QR: `http://127.0.0.1:5000/admin/whatsapp-qr`

### CLI Agent Modes (`agent.php`)
```powershell
php agent.php --watch-cycle
php agent.php https://www.facebook.com/bbcnews
php agent.php https://whatsapp.com/channel/0029VaC48eP6GcG5EiHXyu18
php agent.php --connect-whatsapp
php agent.php --daemon
```

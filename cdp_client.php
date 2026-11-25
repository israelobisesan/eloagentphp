<?php
declare(strict_types=1);

function find_chromium_executable(): string
{
    $envBin = trim((string)getenv('CHROME_BIN'));
    if ($envBin !== '' && is_file($envBin)) {
        return $envBin;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $localAppData = getenv('LOCALAPPDATA') ?: (getenv('USERPROFILE') . '\\AppData\\Local');
        $pwDirs = glob($localAppData . '\\ms-playwright\\chromium-*\\chrome-win64\\chrome.exe') ?: [];
        rsort($pwDirs);
        foreach ($pwDirs as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        $candidates = [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            $localAppData . '\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
    } else {
        $pwDirs = glob('/ms-playwright/chromium-*/chrome-linux/chrome') ?: [];
        rsort($pwDirs);
        $candidates = array_merge($pwDirs, [
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
        ]);
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
    }

    throw new RuntimeException('Could not locate Chromium/Chrome executable. Set CHROME_BIN env var or install Chromium.');
}

function release_stale_profile_lock(string $profileDir): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \\"Name = \'chrome.exe\'\\" | Where-Object { $_.CommandLine -like \'*fb_profile*\' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"';
        @exec($cmd);
    } else {
        @exec('pkill -9 -f fb_profile >/dev/null 2>&1');
    }

    foreach (['SingletonLock', 'SingletonSocket', 'SingletonCookie'] as $lockFile) {
        $path = $profileDir . DIRECTORY_SEPARATOR . $lockFile;
        if (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}

function find_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock) {
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name && str_contains($name, ':')) {
            return (int)substr($name, strrpos($name, ':') + 1);
        }
    }
    return 9222;
}

class CdpWebSocket
{
    /** @var resource */
    private $socket;
    private int $nextId = 1;

    public function __construct(string $wsUrl)
    {
        $parts = parse_url($wsUrl);
        if (!$parts || empty($parts['host']) || empty($parts['port'])) {
            throw new RuntimeException("Invalid CDP WebSocket URL: $wsUrl");
        }
        $host = $parts['host'];
        $port = (int)$parts['port'];
        $path = ($parts['path'] ?? '/') . (!empty($parts['query']) ? '?' . $parts['query'] : '');

        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
        if (!$sock) {
            throw new RuntimeException("CDP socket connection failed ($errno): $errstr");
        }
        stream_set_timeout($sock, 45);
        $this->socket = $sock;

        $key = base64_encode(random_bytes(16));
        $headers = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($this->socket, $headers);
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($this->socket, 2048);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed during CDP WebSocket handshake.');
            }
            $response .= $chunk;
        }
        if (!str_contains($response, '101')) {
            throw new RuntimeException("Unexpected CDP handshake response: $response");
        }
    }

    private function sendFrame(string $payload, int $opcode = 0x1): void
    {
        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        $maskKey = random_bytes(4);

        if ($len <= 125) {
            $header .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $len);
        }

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $maskKey[$i % 4];
        }
        $frame = $header . $maskKey . $masked;

        $written = 0;
        $total = strlen($frame);
        while ($written < $total) {
            $n = fwrite($this->socket, substr($frame, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Failed to write CDP WebSocket frame.');
            }
            $written += $n;
        }
    }

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Timed out reading CDP WebSocket frame.');
                }
                throw new RuntimeException('CDP WebSocket closed unexpectedly.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function readFrame(): ?array
    {
        $assembled = '';
        $finalOpcode = 0x1;

        while (true) {
            $b1b2 = $this->readExact(2);
            $b1 = ord($b1b2[0]);
            $b2 = ord($b1b2[1]);
            $fin = ($b1 & 0x80) !== 0;
            $opcode = $b1 & 0x0F;
            $masked = ($b2 & 0x80) !== 0;
            $len = $b2 & 0x7F;

            if ($len === 126) {
                $len = unpack('n', $this->readExact(2))[1];
            } elseif ($len === 127) {
                $len = unpack('J', $this->readExact(8))[1];
            }

            $maskKey = $masked ? $this->readExact(4) : '';
            $payload = $len > 0 ? $this->readExact($len) : '';
            if ($masked && $len > 0) {
                $unmasked = '';
                for ($i = 0; $i < $len; $i++) {
                    $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
                }
                $payload = $unmasked;
            }

            if ($opcode === 0x8) {
                return null; // Close frame
            }
            if ($opcode === 0x9) {
                $this->sendFrame($payload, 0xA); // Reply Pong
                continue;
            }
            if ($opcode === 0xA) {
                continue; // Ignore Pong
            }

            if ($opcode !== 0x0) {
                $finalOpcode = $opcode;
            }
            $assembled .= $payload;

            if ($fin) {
                return ['opcode' => $finalOpcode, 'payload' => $assembled];
            }
        }
    }

    public function call(string $method, array $params = []): array
    {
        $id = $this->nextId++;
        $req = json_encode([
            'id' => $id,
            'method' => $method,
            'params' => empty($params) ? new stdClass() : $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->sendFrame($req, 0x1);

        while (true) {
            $frame = $this->readFrame();
            if ($frame === null) {
                throw new RuntimeException("CDP connection closed while waiting for $method.");
            }
            $msg = json_decode($frame['payload'], true);
            if (!is_array($msg)) {
                continue;
            }
            if (($msg['id'] ?? null) === $id) {
                if (!empty($msg['error'])) {
                    $errMsg = $msg['error']['message'] ?? json_encode($msg['error']);
                    throw new RuntimeException("CDP error in {$method}: {$errMsg}");
                }
                return $msg['result'] ?? [];
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}

class CdpPage
{
    private CdpWebSocket $ws;
    private string $currentUrl = 'about:blank';

    public function __construct(string $wsUrl)
    {
        $this->ws = new CdpWebSocket($wsUrl);
        $this->ws->call('Page.enable');
        $this->ws->call('Runtime.enable');
        $this->ws->call('DOM.enable');
    }

    public function goto(string $url, int $waitMs = 3000): void
    {
        $this->ws->call('Page.navigate', ['url' => $url]);
        $this->currentUrl = $url;
        $deadline = microtime(true) + 35.0;
        usleep(800000);
        while (microtime(true) < $deadline) {
            $state = $this->evaluate('document.readyState');
            if ($state === 'complete' || $state === 'interactive') {
                break;
            }
            usleep(300000);
        }
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
    }

    public function url(): string
    {
        $u = $this->evaluate('window.location.href');
        return is_string($u) && $u !== '' ? $u : $this->currentUrl;
    }

    public function title(): string
    {
        $t = $this->evaluate('document.title');
        return is_string($t) ? $t : '';
    }

    public function evaluate(string $jsExpr): mixed
    {
        $res = $this->ws->call('Runtime.evaluate', [
            'expression' => $jsExpr,
            'returnByValue' => true,
            'awaitPromise' => true,
        ]);
        if (!empty($res['exceptionDetails'])) {
            $desc = $res['exceptionDetails']['exception']['description']
                ?? $res['exceptionDetails']['text']
                ?? 'JS evaluation error';
            throw new RuntimeException($desc);
        }
        return $res['result']['value'] ?? null;
    }

    public function callJsFunction(string $fnExpr, array $args = []): mixed
    {
        $jsonArgs = array_map(
            fn($a) => json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $args
        );
        $expr = '(' . $fnExpr . ')(' . implode(', ', $jsonArgs) . ')';
        return $this->evaluate($expr);
    }

    public function waitForSelector(string $selector, int $timeoutMs = 25000): bool
    {
        $deadline = microtime(true) + ($timeoutMs / 1000.0);
        while (microtime(true) < $deadline) {
            $found = $this->callJsFunction(
                'function(sel) {
                    const el = document.querySelector(sel);
                    if (!el) return false;
                    const r = el.getBoundingClientRect();
                    return r.width > 0 || r.height > 0 || el.offsetParent !== null;
                }',
                [$selector]
            );
            if ($found) {
                return true;
            }
            usleep(350000);
        }
        return false;
    }

    public function clickSelector(string $selector): bool
    {
        $box = $this->callJsFunction(
            'function(sel) {
                const el = document.querySelector(sel);
                if (!el) return null;
                el.scrollIntoView({ behavior: "instant", block: "center" });
                const r = el.getBoundingClientRect();
                if (r.width === 0 && r.height === 0) return null;
                return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
            }',
            [$selector]
        );
        if (!is_array($box)) {
            return false;
        }
        usleep(120000);
        $this->mouseClick((float)$box['x'], (float)$box['y']);
        return true;
    }

    public function mouseClick(float $x, float $y): void
    {
        $this->ws->call('Input.dispatchMouseEvent', [
            'type' => 'mouseMoved',
            'x' => $x,
            'y' => $y,
        ]);
        $this->ws->call('Input.dispatchMouseEvent', [
            'type' => 'mousePressed',
            'x' => $x,
            'y' => $y,
            'button' => 'left',
            'clickCount' => 1,
        ]);
        $this->ws->call('Input.dispatchMouseEvent', [
            'type' => 'mouseReleased',
            'x' => $x,
            'y' => $y,
            'button' => 'left',
            'clickCount' => 1,
        ]);
    }

    public function wheel(float $deltaX, float $deltaY, float $x = 640, float $y = 400): void
    {
        $this->ws->call('Input.dispatchMouseEvent', [
            'type' => 'mouseWheel',
            'x' => $x,
            'y' => $y,
            'deltaX' => $deltaX,
            'deltaY' => $deltaY,
        ]);
    }

    public function pressKey(string $key, int $windowsVirtualKeyCode = 13): void
    {
        $this->ws->call('Input.dispatchKeyEvent', [
            'type' => 'keyDown',
            'key' => $key,
            'windowsVirtualKeyCode' => $windowsVirtualKeyCode,
            'nativeVirtualKeyCode' => $windowsVirtualKeyCode,
        ]);
        $this->ws->call('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => $key,
            'windowsVirtualKeyCode' => $windowsVirtualKeyCode,
            'nativeVirtualKeyCode' => $windowsVirtualKeyCode,
        ]);
    }

    public function typeText(string $text): void
    {
        $this->ws->call('Input.insertText', ['text' => $text]);
    }

    public function fillInput(string $selector, string $value): bool
    {
        return (bool)$this->callJsFunction(
            'function(sel, val) {
                const el = document.querySelector(sel);
                if (!el) return false;
                el.focus();
                const proto = el.tagName === "TEXTAREA"
                    ? window.HTMLTextAreaElement.prototype
                    : window.HTMLInputElement.prototype;
                const setter = Object.getOwnPropertyDescriptor(proto, "value")?.set;
                if (setter) setter.call(el, val);
                else el.value = val;
                el.dispatchEvent(new Event("input", { bubbles: true }));
                el.dispatchEvent(new Event("change", { bubbles: true }));
                return true;
            }',
            [$selector, $value]
        );
    }

    public function setInputFiles(string $selector, string $filePath): bool
    {
        $absPath = realpath($filePath);
        if (!$absPath || !is_file($absPath)) {
            return false;
        }
        $doc = $this->ws->call('DOM.getDocument', ['depth' => 1]);
        $rootId = $doc['root']['nodeId'] ?? null;
        if (!$rootId) {
            return false;
        }
        $q = $this->ws->call('DOM.querySelector', [
            'nodeId' => (int)$rootId,
            'selector' => $selector,
        ]);
        $nodeId = $q['nodeId'] ?? 0;
        if (!$nodeId) {
            return false;
        }
        $this->ws->call('DOM.setFileInputFiles', [
            'nodeId' => (int)$nodeId,
            'files' => [$absPath],
        ]);
        return true;
    }

    public function screenshot(string $filePath): void
    {
        $res = $this->ws->call('Page.captureScreenshot', ['format' => 'png']);
        if (!empty($res['data'])) {
            file_put_contents($filePath, base64_decode((string)$res['data']));
        }
    }

    public function close(): void
    {
        $this->ws->close();
    }
}

class CdpBrowser
{
    private int $port;
    private string $profileDir;
    private ?CdpPage $page = null;
    private ?string $browserWsUrl = null;

    public function __construct(string $profileDir, bool $headless = false)
    {
        $this->profileDir = $profileDir;
        $this->port = find_free_port();
        release_stale_profile_lock($profileDir);

        $chromeExe = find_chromium_executable();
        $flags = [
            '--user-data-dir=' . $profileDir,
            '--remote-debugging-port=' . $this->port,
            '--window-size=1280,880',
            '--disable-notifications',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--no-first-run',
            '--no-default-browser-check',
        ];
        if ($headless) {
            $flags[] = '--headless=new';
        }
        $flags[] = 'about:blank';

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start "" /B ' . escapeshellarg($chromeExe) . ' ' . implode(' ', array_map('escapeshellarg', $flags)) . ' > NUL 2>&1';
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = 'nohup ' . escapeshellarg($chromeExe) . ' ' . implode(' ', array_map('escapeshellarg', $flags)) . ' >/dev/null 2>&1 &';
            exec($cmd);
        }

        // Wait up to 20s for CDP endpoint to respond
        $deadline = microtime(true) + 20.0;
        $pageWsUrl = null;
        while (microtime(true) < $deadline) {
            $listJson = @file_get_contents("http://127.0.0.1:{$this->port}/json/list");
            if ($listJson !== false) {
                $targets = json_decode($listJson, true);
                if (is_array($targets)) {
                    foreach ($targets as $t) {
                        if (($t['type'] ?? '') === 'page' && !empty($t['webSocketDebuggerUrl'])) {
                            $pageWsUrl = $t['webSocketDebuggerUrl'];
                            break;
                        }
                    }
                }
            }
            $verJson = @file_get_contents("http://127.0.0.1:{$this->port}/json/version");
            if ($verJson !== false) {
                $ver = json_decode($verJson, true);
                if (!empty($ver['webSocketDebuggerUrl'])) {
                    $this->browserWsUrl = $ver['webSocketDebuggerUrl'];
                }
            }
            if ($pageWsUrl) {
                break;
            }
            usleep(350000);
        }

        if (!$pageWsUrl) {
            throw new RuntimeException("Timed out waiting for Chromium CDP on port {$this->port}.");
        }

        $this->page = new CdpPage($pageWsUrl);
    }

    public function getPage(): CdpPage
    {
        if (!$this->page) {
            throw new RuntimeException('No active CDP page.');
        }
        return $this->page;
    }

    public function close(): void
    {
        if ($this->page) {
            $this->page->close();
            $this->page = null;
        }
        if ($this->browserWsUrl) {
            try {
                $bws = new CdpWebSocket($this->browserWsUrl);
                $bws->call('Browser.close');
                $bws->close();
                usleep(400000);
            } catch (Throwable) {
            }
        }
        release_stale_profile_lock($this->profileDir);
    }
}

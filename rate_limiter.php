<?php
/**
 * rate_limiter.php — IP-based Rate Limiting & Session Hijacking Protection
 * ────────────────────────────────────────────────────────────────────────
 * วิธีใช้:
 *   - Login page  : checkLoginRateLimit()  ก่อน process POST
 *   - API files   : checkApiRateLimit()    บรรทัดแรกหลัง require auth
 *   - auth.php    : validateSessionIntegrity() เรียกอัตโนมัติ (include ใน auth)
 *
 * กลไก:
 *   - ใช้ file-based cache ใน /logs/rl_*.cache (ไม่ต้องการ APCu/Redis)
 *   - เหมาะกับ MAMP / shared hosting ทุกประเภท
 *   - Auto-cleanup cache ไฟล์เก่าอัตโนมัติ
 * ────────────────────────────────────────────────────────────────────────
 */

if (defined('RATE_LIMITER_LOADED')) return;
define('RATE_LIMITER_LOADED', true);

// ── Config ───────────────────────────────────────────────────────────────
define('RL_CACHE_DIR',        __DIR__ . '/logs/');
define('RL_LOGIN_MAX',        10);    // ครั้งสูงสุดต่อ IP ใน window
define('RL_LOGIN_WINDOW',     300);   // 5 นาที (วินาที)
define('RL_API_MAX',          60);    // requests สูงสุดต่อ user ใน window
define('RL_API_WINDOW',       60);    // 1 นาที (วินาที)
define('RL_BLOCK_DURATION',   900);   // 15 นาที ถ้าโดน block


// ═══════════════════════════════════════════════════════════════════════
//  SECTION 1 — IP-based Login Rate Limiting
// ═══════════════════════════════════════════════════════════════════════

/**
 * เรียกที่จุดเริ่มต้น POST handler ของหน้า Login
 * ถ้า IP ส่ง request เกิน RL_LOGIN_MAX ครั้งใน RL_LOGIN_WINDOW วินาที
 * จะ die() พร้อม HTTP 429
 *
 * @param string|null $extra_key  เพิ่ม key พิเศษ เช่น school_code เพื่อจำกัดเฉพาะ school
 */
function checkLoginRateLimit(?string $extra_key = null): void {
    $ip  = _getRealIp();
    $key = 'login_' . md5($ip . ($extra_key ?? ''));
    $data = _rlRead($key);

    // ถ้าถูก block อยู่
    if (!empty($data['blocked_until']) && $data['blocked_until'] > time()) {
        $wait = ceil(($data['blocked_until'] - time()) / 60);
        _rlDeny("ระบบตรวจพบการพยายาม login มากเกินไปจาก IP นี้ กรุณารอ {$wait} นาที");
    }

    // reset ถ้าหมด window
    if (empty($data['window_start']) || (time() - $data['window_start']) > RL_LOGIN_WINDOW) {
        $data = ['window_start' => time(), 'count' => 0, 'blocked_until' => null];
    }

    $data['count']++;
    _rlWrite($key, $data);

    if ($data['count'] > RL_LOGIN_MAX) {
        $data['blocked_until'] = time() + RL_BLOCK_DURATION;
        _rlWrite($key, $data);
        error_log("[RateLimit] Login block: IP={$ip} count={$data['count']}");
        _rlDeny('ระบบตรวจพบการพยายาม login มากเกินไป บัญชีจาก IP นี้ถูกระงับชั่วคราว 15 นาที');
    }
}

/**
 * เรียกหลัง login สำเร็จเพื่อ reset นับครั้ง
 */
function resetLoginRateLimit(): void {
    $ip  = _getRealIp();
    $key = 'login_' . md5($ip);
    _rlDelete($key);
}


// ═══════════════════════════════════════════════════════════════════════
//  SECTION 2 — API Rate Limiting (per user_id หรือ IP ถ้าไม่ได้ login)
// ═══════════════════════════════════════════════════════════════════════

/**
 * เรียกที่บรรทัดแรกของไฟล์ api_*.php ทุกตัว
 * จำกัด RL_API_MAX requests ต่อ RL_API_WINDOW วินาที
 *
 * @param int $max     override ค่าสูงสุด (optional)
 * @param int $window  override window (optional)
 */
function checkApiRateLimit(int $max = RL_API_MAX, int $window = RL_API_WINDOW): void {
    $identity = !empty($_SESSION['user_id'])
        ? 'uid_' . (int)$_SESSION['user_id']
        : 'ip_'  . md5(_getRealIp());

    $key  = 'api_' . $identity;
    $data = _rlRead($key);

    if (empty($data['window_start']) || (time() - $data['window_start']) > $window) {
        $data = ['window_start' => time(), 'count' => 0];
    }

    $data['count']++;
    _rlWrite($key, $data);

    if ($data['count'] > $max) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $window);
        echo json_encode([
            'success' => false,
            'error'   => 'Too Many Requests — กรุณารอสักครู่แล้วลองใหม่',
        ]);
        exit;
    }
}


// ═══════════════════════════════════════════════════════════════════════
//  SECTION 3 — Session Hijacking Protection
// ═══════════════════════════════════════════════════════════════════════

/**
 * ตรวจสอบ fingerprint ของ session ว่า browser/IP เปลี่ยนไปหรือไม่
 * เรียกใน auth.php หลัง session_start() โดยอัตโนมัติ
 *
 * กลไก:
 *   - เก็บ hash ของ User-Agent + IP subnet (/24) ไว้ใน session
 *   - ถ้า fingerprint เปลี่ยน → destroy session ทันที
 *   - IP subnet (ไม่ใช่ IP เต็ม) เพื่อรองรับ DHCP และ mobile users
 */
function validateSessionIntegrity(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['user_id'])) return;

    $current_fp = _buildFingerprint();

    if (empty($_SESSION['_fp'])) {
        $_SESSION['_fp'] = $current_fp;
        return;
    }

    if (!hash_equals($_SESSION['_fp'], $current_fp)) {
        error_log('[Security] Session fingerprint mismatch uid=' . ($_SESSION['user_id'] ?? '?'));

        // API / AJAX request — ไม่ redirect แต่ return JSON error แทน
        $is_api = (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) ||
            (strpos($_SERVER['REQUEST_URI'] ?? '', 'api_') !== false) ||
            (strpos($_SERVER['REQUEST_URI'] ?? '', '.php') !== false &&
             ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ||
            (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === false &&
             !empty($_SERVER['HTTP_ACCEPT']))
        );

        if ($is_api) {
            // อัปเดต fingerprint ใหม่แทนการ destroy (เพื่อรองรับ AJAX ที่ถูกต้อง)
            $_SESSION['_fp'] = $current_fp;
            return;
        }

        // Browser request — destroy และ redirect
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();

        header('Location: index.php?error=session_invalid');
        exit;
    }
}

/**
 * เรียกใน setUserSession() เพื่อบันทึก fingerprint ใหม่หลัง login
 */
function bindSessionFingerprint(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_fp'] = _buildFingerprint();
    }
}


// ═══════════════════════════════════════════════════════════════════════
//  SECTION 4 — Internal Helpers (private)
// ═══════════════════════════════════════════════════════════════════════

/** สร้าง fingerprint จาก User-Agent + IP /24 subnet */
function _buildFingerprint(): string {
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip     = _getRealIp();
    // ใช้แค่ /24 subnet เพื่อรองรับ DHCP/mobile
    $subnet = implode('.', array_slice(explode('.', $ip), 0, 3));
    return hash('sha256', $ua . '|' . $subnet . '|' . (defined('APP_ENV') ? APP_ENV : ''));
}

/** หา IP จริง รองรับ reverse proxy */
function _getRealIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
        if (!empty($_SERVER[$h])) {
            // X_FORWARDED_FOR อาจมีหลาย IP คั่นด้วย comma
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** อ่านไฟล์ cache */
function _rlRead(string $key): array {
    $file = RL_CACHE_DIR . 'rl_' . $key . '.cache';
    if (!file_exists($file)) return [];
    $data = @json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** เขียนไฟล์ cache */
function _rlWrite(string $key, array $data): void {
    if (!is_dir(RL_CACHE_DIR)) mkdir(RL_CACHE_DIR, 0755, true);
    $file = RL_CACHE_DIR . 'rl_' . $key . '.cache';
    file_put_contents($file, json_encode($data), LOCK_EX);
}

/** ลบไฟล์ cache */
function _rlDelete(string $key): void {
    $file = RL_CACHE_DIR . 'rl_' . $key . '.cache';
    if (file_exists($file)) @unlink($file);
}

/** ส่ง HTTP 429 พร้อม error page */
function _rlDeny(string $message): void {
    http_response_code(429);
    // ถ้าเป็น API call (Accept: json) ตอบ JSON
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    // ไม่งั้นแสดงหน้า error แบบ HTML
    die('<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>429 — Too Many Requests</title>
<style>body{font-family:"Prompt",sans-serif;display:flex;justify-content:center;
align-items:center;height:100vh;margin:0;background:#fef2f2;}
.card{background:#fff;padding:40px;border-radius:12px;text-align:center;
box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:480px;border-top:5px solid #ef4444;}
h2{color:#ef4444;}p{color:#555;}a{color:#3b82f6;}</style></head>
<body><div class="card"><h2>⛔ คำขอมากเกินไป</h2>
<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
<p><a href="index.php">← กลับหน้าหลัก</a></p>
</div></body></html>');
}

// ── Auto-cleanup cache ไฟล์ที่หมดอายุ (รัน 1% ของ request) ────────────
if (mt_rand(1, 100) === 1 && is_dir(RL_CACHE_DIR)) {
    foreach (glob(RL_CACHE_DIR . 'rl_*.cache') as $f) {
        $d = @json_decode(file_get_contents($f), true);
        $age = time() - ($d['window_start'] ?? 0);
        // ลบถ้าเก่ากว่า 2 ชั่วโมง
        if ($age > 7200) @unlink($f);
    }
}

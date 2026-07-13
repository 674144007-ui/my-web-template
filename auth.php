<?php
/**
 * auth.php — ระบบจัดการสิทธิ์การเข้าถึง (Multi-Tenant Edition)
 * ────────────────────────────────────────────────────────────
 * Session ที่ใช้ (Single Source of Truth):
 *   $_SESSION['school_code']  → string : รหัสโรงเรียน เช่น BKW
 *   $_SESSION['user_id']      → int    : ID ผู้ใช้ใน Tenant DB
 *   $_SESSION['username']     → string : ชื่อ login
 *   $_SESSION['display_name'] → string : ชื่อที่แสดง
 *   $_SESSION['role']         → string : student|teacher|parent|developer
 *   $_SESSION['class_id']     → int|null
 *   $_SESSION['last_activity']→ int    : timestamp
 * ────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Security Middleware ───────────────────────────────────────
require_once __DIR__ . '/rate_limiter.php';
validateSessionIntegrity(); // ป้องกัน Session Hijacking

// ── Session Timeout ──────────────────────────────────────────
if (isset($_SESSION['last_activity'])) {
    // developer session: ไม่มี timeout (อยู่ได้ตลอด session PHP)
    $is_dev_session = (($_SESSION['role'] ?? '') === 'developer');
    $lifetime = $is_dev_session ? 86400 : (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800);
    if ((time() - $_SESSION['last_activity']) > $lifetime) {
        // API call → ไม่ redirect แต่ยืด session อีก 30 นาที
        $is_api = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                  strpos($_SERVER['REQUEST_URI'] ?? '', 'api_') !== false;
        if ($is_api) {
            $_SESSION['last_activity'] = time(); // renew
        } else {
            _destroySession();
            header('Location: index.php?error=session_expired');
            exit;
        }
    }
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// ── CSRF ─────────────────────────────────────────────────────
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token_from_post): void {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_from_post)) {
        http_response_code(403);
        die('❌ Security Error: Token ไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บ');
    }
}

// ── Helpers ──────────────────────────────────────────────────
// หมายเหตุ: function h() อยู่ใน security.php แล้ว ไม่นิยามซ้ำที่นี่

function isLoggedIn(): bool {
    if (empty($_SESSION['user_id'])) return false;
    // developer ไม่ต้องมี school_code
    if (($_SESSION['role'] ?? '') === 'developer') return true;
    return !empty($_SESSION['school_code']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'           => $_SESSION['user_id'],
        'username'     => $_SESSION['username'],
        'display_name' => $_SESSION['display_name'],
        'role'         => $_SESSION['role'],
        'class_id'     => $_SESSION['class_id'] ?? null,
        'school_code'  => $_SESSION['school_code'] ?? null,
    ];
}

// ── Routing ──────────────────────────────────────────────────
function _dashboardUrl(string $role): string {
    return match($role) {
        'student'   => 'dashboard_student.php',
        'teacher'   => 'dashboard_teacher.php',
        'parent'    => 'dashboard_parent.php',
        'developer' => 'dashboard_dev.php',
        default     => ''
    };
}

function redirectToDashboardIfLoggedIn(): void {
    if (!isLoggedIn()) return;
    $url = _dashboardUrl($_SESSION['role']);
    if (empty($url)) {
        _destroySession();
        header('Location: index.php?error=invalid_role');
        exit;
    }
    header('Location: ' . $url);
    exit;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php?error=require_login');
        exit;
    }
}

function requireRole($roles): void {
    requireLogin();
    if (!is_array($roles)) $roles = [$roles];
    $role = $_SESSION['role'] ?? '';
    if ($role === 'developer') return;
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        die('<div style="text-align:center;margin-top:100px;font-family:sans-serif;">
            <h1 style="color:#ef4444;">❌ ไม่มีสิทธิ์เข้าถึง</h1>
            <p style="color:#475569;">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</p>
            <a href="logout.php" style="padding:10px 20px;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:5px;">ออกจากระบบ</a>
        </div>');
    }
}

// ── Session Management ───────────────────────────────────────

/**
 * เซ็ต Session หลัง login สำเร็จ
 * school_code ต้องถูก set ใน $_SESSION ก่อนเรียกฟังก์ชันนี้
 * (index.php จะ set school_code ก่อน แล้วค่อยเรียก setUserSession)
 */
function setUserSession(array $user, string $role): void {
    // เก็บ school_code ไว้ก่อน destroy (developer ไม่มี school_code)
    $school_code = $_SESSION['school_code'] ?? '';
    $is_dev = (($_SESSION['role'] ?? '') === 'developer' || $role === 'developer');

    _destroySession();
    session_start();
    session_regenerate_id(true);

    // restore school_code (developer ไม่ต้อง restore)
    if (!$is_dev && $school_code) {
        $_SESSION['school_code'] = $school_code;
    }
    $_SESSION['user_id']      = (int) $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['role']         = $role;
    $_SESSION['class_id']     = $user['class_id'] ?? null;
    $_SESSION['last_activity']= time();

    // บันทึก fingerprint เพื่อป้องกัน Session Hijacking
    bindSessionFingerprint();
    // reset นับครั้ง login ที่ IP นี้ (login สำเร็จแล้ว)
    resetLoginRateLimit();
}

function _destroySession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

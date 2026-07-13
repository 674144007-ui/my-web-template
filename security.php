<?php
/**
 * security.php — เครื่องมือจัดการรหัสผ่านและความปลอดภัย (v2)
 * ────────────────────────────────────────────────────────────
 * การเปลี่ยนแปลงจากเดิม:
 *   - เพิ่ม validatePasswordPolicy() แยกออกมาเป็น function ชัดเจน
 *   - เพิ่ม sanitizeInput() สำหรับ trim + strip ค่าจาก POST
 *   - เพิ่ม generateCsrfField() helper สำหรับใส่ใน form ง่ายขึ้น
 *   - ปรับ verify_and_upgrade_password() ให้ set is_first_login=0 อัตโนมัติ
 * ────────────────────────────────────────────────────────────
 */

/**
 * Hash รหัสผ่านด้วย Argon2ID (หรือ bcrypt cost 12 ถ้า server เก่า)
 */
function generate_secure_password(string $password): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
    }
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * ตรวจสอบรหัสผ่านกับ hash + rehash อัตโนมัติถ้าอัลกอริทึมเก่า
 * v2: set is_first_login = 0 หลัง rehash สำเร็จ
 */
function verify_and_upgrade_password(string $password, string $hash, ?int $user_id = null): bool {
    global $pdo;

    if (!password_verify($password, $hash)) return false;

    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    if (password_needs_rehash($hash, $algo) && $user_id !== null && isset($pdo)) {
        try {
            $newHash = generate_secure_password($password);
            $stmt = $pdo->prepare(
                "UPDATE users SET password = :h, is_first_login = 0 WHERE id = :id"
            );
            $stmt->execute([':h' => $newHash, ':id' => $user_id]);
        } catch (PDOException $e) {
            error_log("[Security] rehash failed for user #{$user_id}: " . $e->getMessage());
        }
    }

    return true;
}

/**
 * ตรวจสอบ Policy รหัสผ่านว่าแข็งแรงพอหรือไม่
 *
 * @return array ['valid' => bool, 'errors' => string[]]
 */
function validatePasswordPolicy(string $password): array {
    $errors = [];

    if (mb_strlen($password) < 8)            $errors[] = 'ความยาวอย่างน้อย 8 ตัวอักษร';
    if (!preg_match('/[A-Z]/', $password))   $errors[] = 'ตัวอักษรพิมพ์ใหญ่ (A-Z) อย่างน้อย 1 ตัว';
    if (!preg_match('/[a-z]/', $password))   $errors[] = 'ตัวอักษรพิมพ์เล็ก (a-z) อย่างน้อย 1 ตัว';
    if (!preg_match('/[0-9]/', $password))   $errors[] = 'ตัวเลข (0-9) อย่างน้อย 1 ตัว';
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) $errors[] = 'อักขระพิเศษ เช่น !@#$%^&* อย่างน้อย 1 ตัว';

    return [
        'valid'  => empty($errors),
        'errors' => $errors,
    ];
}

/**
 * สร้างรหัสผ่านสุ่มที่ผ่าน policy โดยอัตโนมัติ
 */
function generate_random_password(int $length = 12): string {
    $lower   = 'abcdefghijklmnopqrstuvwxyz';
    $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $digits  = '0123456789';
    $special = '!@#$%^&*-_+=';
    $all     = $lower . $upper . $digits . $special;

    // ใส่อย่างน้อย 1 ตัวจากแต่ละประเภทก่อน
    $pass  = $lower[random_int(0, strlen($lower) - 1)];
    $pass .= $upper[random_int(0, strlen($upper) - 1)];
    $pass .= $digits[random_int(0, strlen($digits) - 1)];
    $pass .= $special[random_int(0, strlen($special) - 1)];

    for ($i = 4; $i < $length; $i++) {
        $pass .= $all[random_int(0, strlen($all) - 1)];
    }

    // Shuffle ให้ไม่เป็นลำดับ
    return str_shuffle($pass);
}

/**
 * Sanitize ค่าจาก input (trim + ป้องกัน null byte)
 */
function sanitizeInput(?string $value): string {
    if ($value === null) return '';
    return trim(str_replace("\0", '', $value));
}

/**
 * ป้องกัน XSS — ใช้แทน echo $value ตรงๆ
 */
function h(?string $string): string {
    if ($string === null) return '';
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Echo hidden input สำหรับ CSRF — ใช้ใน form แทนการพิมพ์เอง
 *
 * ตัวอย่าง: <?php csrfField() ?>
 */
function csrfField(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    echo '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}

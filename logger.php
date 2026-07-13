<?php
/**
 * logger.php — ระบบบันทึก Audit Log (v2)
 * ────────────────────────────────────────────────────────────
 * การเปลี่ยนแปลงจากเดิม:
 *   - เพิ่มบันทึก ip_address ทุก log entry (รองรับ IPv6 + proxy)
 *   - ไม่ require db.php ซ้ำถ้า $pdo มีอยู่แล้ว
 *   - ป้องกัน log loop (ถ้าเขียน log ไม่ได้ ให้ error_log แทน)
 *   - เพิ่ม systemLogBatch() สำหรับเขียนหลาย record พร้อมกัน
 * ────────────────────────────────────────────────────────────
 */

/**
 * บันทึก Log 1 รายการ
 *
 * @param int|null $user_id  user ที่ทำ action (null = ไม่รู้ / ก่อน login)
 * @param string   $action   รหัส action เช่น 'LOGIN_SUCCESS', 'FILE_UPLOAD'
 * @param string   $details  รายละเอียดเพิ่มเติม
 */
function systemLog(?int $user_id, string $action, string $details = ''): void {
    global $pdo;

    if (!isset($pdo)) {
        // ถ้า $pdo ยังไม่มี ลอง require ดู
        try {
            require_once __DIR__ . '/db.php';
        } catch (Throwable $e) {
            error_log("[Logger] db unavailable | {$action} | {$details}");
            return;
        }
    }

    $ip = _getClientIp();

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO system_logs (user_id, action, details, ip_address, created_at)
             VALUES (:uid, :action, :details, :ip, NOW())"
        );
        $stmt->execute([
            ':uid'     => $user_id,
            ':action'  => mb_substr($action,  0, 100),
            ':details' => mb_substr($details, 0, 1000),
            ':ip'      => $ip,
        ]);
    } catch (PDOException $e) {
        error_log("[Logger] INSERT failed | {$action} | " . $e->getMessage());
    }
}

/**
 * บันทึกหลาย Log พร้อมกันใน transaction เดียว (ประหยัด round-trip)
 *
 * @param array[] $entries  [['user_id'=>?, 'action'=>'...', 'details'=>'...'], ...]
 */
function systemLogBatch(array $entries): void {
    global $pdo;

    if (!isset($pdo) || empty($entries)) return;

    $ip = _getClientIp();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO system_logs (user_id, action, details, ip_address, created_at)
             VALUES (:uid, :action, :details, :ip, NOW())"
        );
        foreach ($entries as $e) {
            $stmt->execute([
                ':uid'     => $e['user_id']  ?? null,
                ':action'  => mb_substr($e['action']  ?? '', 0, 100),
                ':details' => mb_substr($e['details'] ?? '', 0, 1000),
                ':ip'      => $ip,
            ]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[Logger] Batch INSERT failed | " . $e->getMessage());
    }
}

/**
 * ดึง Client IP จริง (รองรับ reverse proxy)
 */
function _getClientIp(): string {
    // ลำดับ: X-Forwarded-For → X-Real-IP → REMOTE_ADDR
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            // X-Forwarded-For อาจมีหลาย IP คั่นด้วย comma — ใช้ตัวแรก
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            // ตรวจ valid IPv4/IPv6 (ป้องกัน header injection)
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

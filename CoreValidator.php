<?php
/**
 * CoreValidator.php
 * คลาสส่วนกลางสำหรับป้องกันบั๊กและข้อมูลขยะก่อนเข้าสู่ฐานข้อมูล (Phase 1)
 */

class CoreValidator {
    
    /**
     * ตรวจสอบความถูกต้องของโครงสร้าง JSON ของระบบ Gamification
     * ป้องกันผู้ใช้อุปกรณ์มือถือหรือผู้ไม่หวังดีส่ง Request ที่ทำให้แอปพลิเคชัน 3D แครช
     * * @param string $jsonString ข้อมูล JSON ที่ต้องการตรวจสอบ
     * @return array|false คืนค่าเป็น Array ที่ผ่านการกรองแล้ว หากไม่ถูกต้องคืนค่า false
     */
    public static function validateGameSettings($jsonString) {
        if (empty($jsonString)) {
            return false;
        }

        $data = json_decode($jsonString, true);

        // ตรวจสอบว่าเข้ารหัส JSON ถูกต้องหรือไม่
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::logError('JSON_DECODE_ERROR', 'รูปแบบ JSON ไม่ถูกต้อง: ' . json_last_error_msg());
            return false;
        }

        // Schema Validation - ตรวจสอบบังคับคีย์ที่จำเป็นต้องมีในระบบ Virtual Lab
        $requiredKeys = ['amount_chem1', 'amount_chem2', 'safety_goggles', 'strict_amount'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                self::logError('JSON_SCHEMA_ERROR', "ขาดพารามิเตอร์ที่จำเป็น: {$key}");
                return false;
            }
        }

        // ตรวจสอบและบังคับ Type (Type Casting ป้องกัน String ปะปนในฟิลด์คำนวณ)
        $data['amount_chem1'] = max(0, (float)$data['amount_chem1']);
        $data['amount_chem2'] = max(0, (float)$data['amount_chem2']);
        $data['safety_goggles'] = (int)(bool)$data['safety_goggles'];
        $data['strict_amount'] = (int)(bool)$data['strict_amount'];

        return $data; // คืนค่าชุดข้อมูลที่ปลอดภัยแล้ว
    }

    /**
     * ทำความสะอาดและป้องกัน SQL Injection แบบล้ำลึกสำหรับ Input ทั่วไป
     */
    public static function sanitizeInput($input, $type = 'string') {
        if ($input === null) return null;

        switch ($type) {
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            case 'string':
            default:
                // ล้างอักขระพิเศษ (รวมถึง Zero-width space และ BOM ที่ติดมากับ Excel)
                $input = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $input);
                return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    /**
     * ฟังก์ชันบันทึกความผิดพลาดลงระบบ (Centralized Logging)
     */
    private static function logError($action, $details) {
        require_once 'db.php';
        global $pdo;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO system_logs (action, details, ip_address) VALUES (?, ?, ?)");
                $stmt->execute([$action, $details, $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN']);
            } catch (PDOException $e) {
                // หากฐานข้อมูลล่ม ให้เขียนลง Text file ป้องกันข้อมูลสูญหาย
                error_log(date('[Y-m-d H:i:s] ') . "{$action}: {$details}\n", 3, __DIR__ . '/error_logs_emergency.txt');
            }
        }
    }
}
?>
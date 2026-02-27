<?php
// logger.php - ระบบบันทึกประวัติการทำงาน (Audit Log)
require_once 'db.php';

/**
 * ฟังก์ชันสำหรับบันทึก Log ลงฐานข้อมูล
 * * @param int|null $user_id รหัสผู้ใช้งาน (ถ้ามี)
 * @param string $action ชื่อการกระทำ (เช่น 'LOGIN_SUCCESS', 'DELETE_ASSIGNMENT')
 * @param string $details รายละเอียดเพิ่มเติม
 */
function systemLog($user_id, $action, $details = "") {
    global $conn;
    
    // ดึง IP Address ของผู้ใช้งาน
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    // เตรียมคำสั่ง SQL สำหรับบันทึก Log
    $stmt = $conn->prepare("
        INSERT INTO system_logs (user_id, action, details, ip_address) 
        VALUES (?, ?, ?, ?)
    ");
    
    if ($stmt) {
        $stmt->bind_param("isss", $user_id, $action, $details, $ip_address);
        $stmt->execute();
        $stmt->close();
    } else {
        // หากบันทึกลง DB ไม่ได้ ให้เขียนลง Error Log ของ Server แทน
        error_log("System Log Error: ไม่สามารถบันทึก Action '$action' ลงฐานข้อมูลได้");
    }
}
?>
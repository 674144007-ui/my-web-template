<?php
session_start();
require_once "db.php";
header("Content-Type: application/json");

// 🔥 แก้ไข: โค้ดนี้ถูกเปลี่ยนให้เป็น 'บันทึกผลลัพธ์' จริงๆ
// ตรวจสอบ Session และสิทธิ์การใช้งาน
if (!isset($_SESSION["id"])) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit;
}

// รับ input สำหรับผลลัพธ์
$user_id = $_SESSION["id"];
$game_id = $_POST["game_id"] ?? 0;
$score = $_POST["score"] ?? 0;
$ph_result = $_POST["ph_result"] ?? 0.0;
$is_passed = $_POST["is_passed"] ?? 0; // 1 หรือ 0

// ตรวจสอบความถูกต้องของ input
if ($game_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid game ID"]);
    exit;
}

// Insert ผลลัพธ์ลงในตาราง 'results'
$stmt = $pdo->prepare("
    INSERT INTO results (user_id, game_id, score, ph_result, is_passed, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");

$stmt->execute([$user_id, $game_id, $score, $ph_result, $is_passed]);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "บันทึกผลลัพธ์สำเร็จ"]);
} else {
    echo json_encode(["status" => "error", "message" => $stmt->error]);
}
?>
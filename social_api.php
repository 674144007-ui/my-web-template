<?php
// social_api.php - ระบบหลังบ้านสำหรับแชทและเพื่อน
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// ปิด Error ชั่วคราวเพื่อให้ JSON ทำงานถูกต้อง
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status'=>'error', 'msg'=>'Unauthorized']); 
    exit; 
}

$my_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// 1. เพิ่มเพื่อน (Add Friend)
if ($action == 'add_friend') {
    $target = intval($_GET['id']);
    if ($target == $my_id) { echo json_encode(['msg'=>'คุณไม่สามารถเป็นเพื่อนกับตัวเองได้']); exit; }
    
    // เช็คว่าเป็นเพื่อนกันหรือยัง
    $check = $conn->query("SELECT * FROM friends WHERE (user_id_1=$my_id AND user_id_2=$target) OR (user_id_1=$target AND user_id_2=$my_id)");
    
    if ($check->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO friends (user_id_1, user_id_2, status) VALUES (?, ?, 'pending')");
        $stmt->bind_param("ii", $my_id, $target);
        if ($stmt->execute()) {
            echo json_encode(['msg' => '✅ ส่งคำขอเป็นเพื่อนแล้ว']);
        } else {
            echo json_encode(['msg' => '❌ เกิดข้อผิดพลาด SQL']);
        }
    } else {
        echo json_encode(['msg' => '⚠️ เป็นเพื่อนกันแล้ว หรือมีคำขอค้างอยู่']);
    }
}

// 2. ส่งข้อความ (Send Message)
if ($action == 'send_message') {
    $receiver = intval($_POST['receiver_id']);
    $msg = $_POST['message'];
    $file_path = null;
    $file_name = null;

    // อัปโหลดไฟล์
    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','zip'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if(in_array($ext, $allowed)) {
            $new_name = "chat_" . time() . "_" . rand(100,999) . "." . $ext;
            if (!is_dir("uploads")) mkdir("uploads");
            move_uploaded_file($_FILES['file']['tmp_name'], "uploads/" . $new_name);
            $file_path = $new_name;
            $file_name = $_FILES['file']['name'];
        }
    }

    if (!empty($msg) || !empty($file_path)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, file_path, file_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $my_id, $receiver, $msg, $file_path, $file_name);
        $stmt->execute();
    }
    echo json_encode(['status' => 'ok']);
}

// 3. ดึงข้อความแชท (Get Messages)
if ($action == 'get_messages') {
    $partner = intval($_GET['partner_id']);
    $sql = "SELECT * FROM messages WHERE (sender_id=$my_id AND receiver_id=$partner) OR (sender_id=$partner AND receiver_id=$my_id) ORDER BY created_at ASC";
    $res = $conn->query($sql);
    $msgs = [];
    while($row = $res->fetch_assoc()) $msgs[] = $row;
    echo json_encode($msgs);
}
?>
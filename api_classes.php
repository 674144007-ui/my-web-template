<?php
/**
 * api_classes.php - Core API สำหรับระบบจัดการระดับชั้นและห้องเรียน (Dynamic Class Management)
 * รองรับการทำงานแบบ Cascading Dropdown, การสร้างห้องเรียนกลุ่ม (Bulk Insert), และ CRUD
 */

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// ตรวจสอบการเข้าสู่ระบบพื้นฐาน
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
    exit;
}

$userRole = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// รับข้อมูล Request (รองรับทั้ง GET สำหรับดึงข้อมูล และ POST/JSON สำหรับเขียนข้อมูล)
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$data = [];

if ($method === 'POST') {
    $raw_data = file_get_contents("php://input");
    $data = json_decode($raw_data, true) ?: $_POST;
    $action = $data['action'] ?? $action;

    // ตรวจสอบ CSRF Token สำหรับ Action ที่มีการแก้ไขข้อมูล
    $safe_read_actions = ['get_levels', 'get_rooms', 'get_all_classes'];
    if (!in_array($action, $safe_read_actions)) {
        if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'CSRF Token Mismatch: คำขอไม่ถูกต้องหรือหมดอายุ']);
            exit;
        }
        
        // สิทธิ์ในการแก้ไข/สร้างห้องเรียน ต้องเป็น Developer หรือ Admin เท่านั้น (ตามลอจิก Role ของคุณ)
        if ($userRole !== 'developer' && $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden: คุณไม่มีสิทธิ์จัดการโครงสร้างชั้นเรียน']);
            exit;
        }
    }
}

// ---------------------------------------------------------------------------
// ROUTER: จัดการคำขอตาม Action
// ---------------------------------------------------------------------------
switch ($action) {

    /**
     * ACTION: get_levels
     * ดึงข้อมูล "ระดับชั้น" ทั้งหมดที่มีในระบบ (ไม่ซ้ำกัน) เพื่อแสดงใน Dropdown ช่องแรก
     */
    case 'get_levels':
        $levels = [];
        // ดึงเฉพาะชั้นเรียนที่เปิดใช้งานอยู่
        $sql = "SELECT DISTINCT `level` FROM `classes` WHERE `is_active` = 1 AND `level` IS NOT NULL ORDER BY `level` ASC";
        $result = $conn->query($sql);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (trim($row['level']) !== '') {
                    $levels[] = $row['level'];
                }
            }
        }
        echo json_encode(['status' => 'success', 'data' => $levels]);
        break;

    /**
     * ACTION: get_rooms
     * ดึงข้อมูล "ห้องเรียน" ของระดับชั้นที่เลือก เพื่อแสดงใน Dropdown ช่องที่สอง
     * จำเป็นต้องส่งพารามิเตอร์: level (เช่น 'ม.1')
     */
    case 'get_rooms':
        $level = $_GET['level'] ?? ($data['level'] ?? '');
        if (empty($level)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing parameter: level']);
            exit;
        }

        $rooms = [];
        $stmt = $conn->prepare("SELECT `id`, `room`, `class_name` FROM `classes` WHERE `level` = ? AND `is_active` = 1 ORDER BY `room` ASC");
        $stmt->bind_param("s", $level);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $rooms[] = [
                'class_id' => $row['id'],
                'room_number' => $row['room'],
                'class_name' => $row['class_name']
            ];
        }
        $stmt->close();

        echo json_encode(['status' => 'success', 'data' => $rooms]);
        break;

    /**
     * ACTION: get_all_classes
     * ดึงข้อมูลห้องเรียนทั้งหมด (ใช้สำหรับแสดงในตารางหน้า Dashboard จัดการ)
     */
    case 'get_all_classes':
        $classes = [];
        $sql = "SELECT c.*, u.display_name AS teacher_name 
                FROM `classes` c 
                LEFT JOIN `users` u ON c.teacher_id = u.id 
                ORDER BY c.level ASC, c.room ASC";
        $result = $conn->query($sql);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $classes[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $classes]);
        break;

    /**
     * ACTION: create_bulk_classes
     * สร้างห้องเรียนทีละหลายๆ ห้องพร้อมกัน (เช่น สร้าง ม.1 ห้อง 1 ถึง 10)
     */
    case 'create_bulk_classes':
        $level = trim($data['level'] ?? '');
        $start_room = intval($data['start_room'] ?? 1);
        $end_room = intval($data['end_room'] ?? 0);

        if (empty($level) || $end_room < $start_room) {
            echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง กรุณาระบุระดับชั้นและจำนวนห้องให้ชัดเจน']);
            exit;
        }

        // เริ่มต้น Transaction เพื่อความปลอดภัยของข้อมูล
        $conn->begin_transaction();
        
        try {
            $stmt_check = $conn->prepare("SELECT `id` FROM `classes` WHERE `level` = ? AND `room` = ?");
            $stmt_insert = $conn->prepare("INSERT INTO `classes` (`class_name`, `level`, `room`, `is_active`) VALUES (?, ?, ?, 1)");
            
            $created_count = 0;
            $skipped_count = 0;

            for ($r = $start_room; $r <= $end_room; $r++) {
                // เช็คก่อนว่าห้องนี้มีอยู่แล้วหรือไม่
                $stmt_check->bind_param("si", $level, $r);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $skipped_count++;
                    continue; // มีแล้ว ข้ามไป
                }

                // สร้างชื่อห้อง เช่น "ม.1/1"
                $class_name = "{$level}/{$r}";

                $stmt_insert->bind_param("ssi", $class_name, $level, $r);
                $stmt_insert->execute();
                $created_count++;
            }

            $conn->commit();
            
            $message = "สร้างห้องเรียนสำเร็จ {$created_count} ห้อง";
            if ($skipped_count > 0) {
                $message .= " (ข้าม {$skipped_count} ห้องที่มีอยู่แล้ว)";
            }

            echo json_encode([
                'status' => 'success', 
                'message' => $message,
                'created' => $created_count,
                'skipped' => $skipped_count
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
        } finally {
            if(isset($stmt_check)) $stmt_check->close();
            if(isset($stmt_insert)) $stmt_insert->close();
        }
        break;

    /**
     * ACTION: update_class
     * อัปเดตข้อมูลห้องเรียนแบบระบุเจาะจง (เช่น เปลี่ยนชื่อเป็น ม.4/1 (Gifted), หรือกำหนดครูที่ปรึกษา)
     */
    case 'update_class':
        $class_id = intval($data['class_id'] ?? 0);
        $class_name = trim($data['class_name'] ?? '');
        $teacher_id = !empty($data['teacher_id']) ? intval($data['teacher_id']) : null;

        if ($class_id === 0 || empty($class_name)) {
            echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE `classes` SET `class_name` = ?, `teacher_id` = ? WHERE `id` = ?");
        $stmt->bind_param("sii", $class_name, $teacher_id, $class_id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'อัปเดตข้อมูลห้องเรียนเรียบร้อยแล้ว']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถอัปเดตข้อมูลได้: ' . $stmt->error]);
        }
        $stmt->close();
        break;

    /**
     * ACTION: toggle_active
     * เปิดหรือปิดการใช้งานห้องเรียน (Soft Disable ไม่ลบข้อมูลนักเรียนทิ้ง)
     */
    case 'toggle_active':
        $class_id = intval($data['class_id'] ?? 0);
        $is_active = intval($data['is_active'] ?? 1);

        if ($class_id === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID ไม่ถูกต้อง']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE `classes` SET `is_active` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $is_active, $class_id);
        
        if ($stmt->execute()) {
            $status_txt = $is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
            echo json_encode(['status' => 'success', 'message' => "{$status_txt}ห้องเรียนเรียบร้อยแล้ว"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเปลี่ยนสถานะได้']);
        }
        $stmt->close();
        break;

    /**
     * ACTION: delete_class
     * ลบห้องเรียนออกจากระบบ (จะลบได้ก็ต่อเมื่อไม่มีนักเรียนเหลืออยู่ในห้องนี้แล้ว)
     */
    case 'delete_class':
        $class_id = intval($data['class_id'] ?? 0);

        if ($class_id === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID ไม่ถูกต้อง']);
            exit;
        }

        // เช็คก่อนว่ามีนักเรียนอ้างอิงถึงห้องนี้หรือไม่
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE class_id = ? AND is_deleted = 0 LIMIT 1");
        $stmt_check->bind_param("i", $class_id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถลบห้องเรียนนี้ได้ เนื่องจากยังมีนักเรียนสังกัดอยู่ในห้องนี้ กรุณาย้ายนักเรียนออกก่อน']);
            $stmt_check->close();
            exit;
        }
        $stmt_check->close();

        // ลบจริง (Hard Delete)
        $stmt = $conn->prepare("DELETE FROM `classes` WHERE `id` = ?");
        $stmt->bind_param("i", $class_id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'ลบห้องเรียนออกจากระบบอย่างถาวรแล้ว']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการลบ']);
        }
        $stmt->close();
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid Action: ไม่พบคำสั่งที่ต้องการ']);
        break;
}
?>
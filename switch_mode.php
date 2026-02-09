<?php
// switch_mode.php - เวอร์ชันแก้ไข Error 500 + Debug

// 1. เปิดแสดง Error ทันที (แก้ปัญหาหน้าขาว/500)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์
$is_dev = (isset($_SESSION['role']) && $_SESSION['role'] === 'developer');
$is_simulating = (isset($_SESSION['dev_simulation_mode']) && $_SESSION['dev_simulation_mode'] === true);

// ==========================================
// 1. ฟังก์ชันเริ่มจำลอง (Start Simulation)
// ==========================================
if (isset($_GET['role']) && !$is_simulating) {
    if (!$is_dev) {
        die("❌ Access Denied: คุณไม่ใช่ Developer หรือสิทธิ์ไม่ถูกต้อง");
    }

    $my_id = $_SESSION['user_id'];
    $target_role = $_GET['role'];
    
    // ข้อมูลสมมติสำหรับแต่ละบทบาท
    $sim_data = [];
    
    try {
        // เตรียมข้อมูลหลอกๆ
        switch ($target_role) {
            case 'teacher':
                $sim_data['subject_group'] = 'วิทยาศาสตร์ (จำลอง)';
                $sim_data['teacher_department'] = 'ฝ่ายวิชาการ';
                break;
            case 'student':
                $sim_data['class_level'] = 'ม.6/1'; 
                break;
            case 'parent':
                // ค้นหานักเรียนสักคนมาเป็นลูก (จะได้มีข้อมูลแสดง)
                $res = $conn->query("SELECT id FROM users WHERE role='student' LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) {
                    $sim_data['parent_of'] = $row['id'];
                } else {
                    die("❌ Error: ไม่พบนักเรียนในระบบ (ต้องมีนักเรียนอย่างน้อย 1 คนเพื่อจำลองเป็นผู้ปกครอง)");
                }
                break;
            default:
                die("❌ Error: ไม่รู้จักบทบาท '$target_role'");
        }

        // --- เริ่มอัปเดต Database ---
        // ใช้ SQL Update โดยระบุคอลัมน์ original_role
        $sql = "UPDATE users SET role=?, original_role='developer'";
        $types = "s";
        $params = [$target_role];

        // ต่อ String SQL ตามข้อมูลที่มี
        if (isset($sim_data['subject_group'])) {
            $sql .= ", subject_group=?"; $types .= "s"; $params[] = $sim_data['subject_group'];
        }
        if (isset($sim_data['teacher_department'])) {
            $sql .= ", teacher_department=?"; $types .= "s"; $params[] = $sim_data['teacher_department'];
        }
        if (isset($sim_data['class_level'])) {
            $sql .= ", class_level=?"; $types .= "s"; $params[] = $sim_data['class_level'];
        }
        if (isset($sim_data['parent_of'])) {
            $sql .= ", parent_of=?"; $types .= "i"; $params[] = $sim_data['parent_of'];
        }

        $sql .= " WHERE id=?";
        $types .= "i";
        $params[] = $my_id;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            // สำเร็จ -> อัปเดต Session
            $_SESSION['role'] = $target_role;
            $_SESSION['dev_simulation_mode'] = true;
            
            // Redirect
            header("Location: dashboard_{$target_role}.php");
            exit();
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }

    } catch (Exception $e) {
        // ถ้ามี Error จะแสดงผลตรงนี้แทน 500
        echo "<div style='background:#fee; color:red; padding:20px; border:1px solid red; margin:20px;'>";
        echo "<h2>⚠️ เกิดข้อผิดพลาด (Simulation Error)</h2>";
        echo "<p><b>สาเหตุ:</b> " . $e->getMessage() . "</p>";
        echo "<p>กรุณาตรวจสอบว่าคุณได้รันคำสั่ง SQL เพิ่มคอลัมน์ <code>original_role</code> แล้วหรือยัง?</p>";
        echo "<a href='dashboard_dev.php'>กลับไปหน้า Dashboard</a>";
        echo "</div>";
        exit();
    }
}

// ==========================================
// 2. ฟังก์ชันออกจากโหมดจำลอง (Exit Simulation)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'exit') {
    $my_id = $_SESSION['user_id'];
    
    try {
        // ตรวจสอบก่อนคืนค่า
        $check = $conn->query("SELECT original_role FROM users WHERE id=$my_id");
        if (!$check) throw new Exception("Query failed: " . $conn->error);
        
        $user_db = $check->fetch_assoc();

        // อนุญาตให้ออก ถ้ากำลังจำลองอยู่ หรือใน DB บอกว่าเป็น dev
        if (($is_simulating) || ($user_db && $user_db['original_role'] === 'developer')) {
            
            // คืนค่า Database กลับเป็น Developer และล้างค่าสมมติต่างๆ เป็น NULL
            $sql = "UPDATE users SET 
                    role='developer', 
                    original_role=NULL,
                    subject_group=NULL, 
                    teacher_department=NULL, 
                    class_level=NULL, 
                    parent_of=NULL 
                    WHERE id=?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $my_id);
            
            if ($stmt->execute()) {
                // คืนค่า Session
                $_SESSION['role'] = 'developer';
                unset($_SESSION['dev_simulation_mode']);
                
                header("Location: dashboard_dev.php");
                exit();
            } else {
                throw new Exception("Recovery failed: " . $stmt->error);
            }
        } else {
            // ไม่ใช่ Dev แต่อยากออก -> ส่งไปหน้า Login
            header("Location: index.php"); 
            exit();
        }

    } catch (Exception $e) {
        echo "<div style='background:#fee; color:red; padding:20px; border:1px solid red; margin:20px;'>";
        echo "<h2>⚠️ ไม่สามารถออกจากโหมดจำลองได้</h2>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
        exit();
    }
}

// ถ้าไม่มี Action อะไรเลย ให้กลับ Dashboard ตาม Role ปัจจุบัน
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] == 'developer') {
        header("Location: dashboard_dev.php");
    } else {
        header("Location: dashboard_" . $_SESSION['role'] . ".php");
    }
} else {
    header("Location: index.php");
}
?>
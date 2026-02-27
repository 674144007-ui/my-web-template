<?php
// api_process_lab.php - Backend ประมวลผลเคมี ฟิสิกส์ ความปลอดภัย และตัดเกรด (Phase 5)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw_data = file_get_contents('php://input');
$request = json_decode($raw_data, true);

if (!$request) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    exit;
}

$csrf_token = $request['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
    exit;
}

$action = $request['action'] ?? 'mix'; 
$student_id = $_SESSION['user_id'] ?? 0;

// =========================================================================================
// ACTION: SUBMIT REPORT (ส่งรายงานและตัดเกรด - Phase 5)
// =========================================================================================
if ($action === 'submit_report') {
    $hp_remaining = intval($request['hp'] ?? 0);
    $successful_reactions = intval($request['success_count'] ?? 0);
    $mistakes_count = intval($request['mistakes_count'] ?? 0);
    $logs = $request['logs'] ?? [];

    // --- ระบบตัดเกรดอัตโนมัติ (Dynamic Grading Logic) ---
    // คะแนนเต็ม 100 
    // - HP มีผล 60% (เอา HP มาคูณ 0.6)
    // - ความสำเร็จของปฏิกิริยา มีผล 40% (ครั้งละ 20 คะแนน สูงสุด 40)
    // - หักคะแนนข้อผิดพลาดเล็กๆ น้อยๆ (ครั้งละ 5 คะแนน)
    
    $score_hp = $hp_remaining * 0.6;
    $score_reaction = min(40, $successful_reactions * 20);
    $score_penalty = $mistakes_count * 5;

    $final_score = round($score_hp + $score_reaction - $score_penalty);
    if ($final_score > 100) $final_score = 100;
    if ($final_score < 0 || $hp_remaining <= 0) $final_score = 0; // ถ้าตาย = 0

    // ตัดเกรดอิงเกณฑ์
    $grade = 'F';
    $feedback = "";
    if ($final_score >= 80) { $grade = 'A'; $feedback = "ยอดเยี่ยม! คุณปฏิบัติตามกฎความปลอดภัยและทดลองได้อย่างแม่นยำ"; }
    elseif ($final_score >= 70) { $grade = 'B'; $feedback = "ดีมาก! มีข้อผิดพลาดเล็กน้อย แต่โดยรวมปลอดภัยดี"; }
    elseif ($final_score >= 60) { $grade = 'C'; $feedback = "พอใช้! คุณต้องระมัดระวังเรื่องสารเคมีและอุปกรณ์ความปลอดภัยให้มากกว่านี้"; }
    elseif ($final_score >= 50) { $grade = 'D'; $feedback = "ผ่านเกณฑ์ขั้นต่ำ! โปรดทบทวนคู่มือความปลอดภัยในห้องปฏิบัติการด่วน"; }
    else { $grade = 'F'; $feedback = "ไม่ผ่านเกณฑ์! การทดลองของคุณก่อให้เกิดอันตรายต่อตนเองและผู้อื่น"; }

    if ($hp_remaining <= 0) {
        $grade = 'F';
        $feedback = "ล้มเหลวร้ายแรง! เกิดอุบัติเหตุรุนแรงจนห้องปฏิบัติการต้องปิดปรับปรุง";
    }

    // แปลง Log เป็น String ย่อๆ เพื่อเซฟลง DB
    $summary_text = "Reactions: $successful_reactions | Mistakes: $mistakes_count | Feedback: $feedback";

    // บันทึกลงฐานข้อมูล `lab_reports`
    $stmt_rep = $conn->prepare("INSERT INTO lab_reports (student_id, final_score, grade, hp_remaining, report_summary) VALUES (?, ?, ?, ?, ?)");
    $stmt_rep->bind_param("iisis", $student_id, $final_score, $grade, $hp_remaining, $summary_text);
    $stmt_rep->execute();
    $report_id = $stmt_rep->insert_id;
    $stmt_rep->close();

    systemLog($student_id, 'LAB_SUBMIT', "Submitted Lab Report ID: $report_id | Score: $final_score | Grade: $grade");

    echo json_encode([
        'status' => 'success',
        'score' => $final_score,
        'grade' => $grade,
        'feedback' => $feedback
    ]);
    exit;
}

// =========================================================================================
// ACTION: DISPOSE (จัดการของเสีย)
// =========================================================================================
if ($action === 'dispose') {
    $method = $request['method'] ?? 'sink'; 
    $chemicals = $request['chemicals'] ?? [];
    $ph = floatval($request['ph'] ?? 7.0);
    
    $is_toxic_waste = false;
    $reason = "";

    if ($ph <= 4.0) { $is_toxic_waste = true; $reason = "สารละลายมีความเป็นกรดสูงมาก (pH $ph)"; }
    elseif ($ph >= 10.0) { $is_toxic_waste = true; $reason = "สารละลายมีความเป็นด่างสูงมาก (pH $ph)"; }
    
    foreach ($chemicals as $c) {
        $c_name = strtolower($c['name']);
        if (strpos($c_name, 'copper') !== false || strpos($c_name, 'lead') !== false || strpos($c_name, 'silver') !== false) {
            $is_toxic_waste = true; $reason = "มีสารประกอบโลหะหนักปนเปื้อน ({$c['name']})";
        }
    }

    if ($method === 'sink' && $is_toxic_waste) {
        systemLog($student_id, 'WASTE_VIOLATION', "Disposed toxic waste in sink.");
        echo json_encode(['status' => 'danger', 'damage' => 20, 'message' => "ละเมิดกฎความปลอดภัย! เทของเสียอันตรายลงอ่างล้างจาน ($reason) ท่อระบายน้ำพัง!"]);
    } else if ($method === 'bin' && !$is_toxic_waste) {
        echo json_encode(['status' => 'warning', 'damage' => 0, 'message' => "ทิ้งลงถังขยะอันตรายสำเร็จ (สารนี้ปลอดภัย สามารถเทลงอ่างล้างจานเพื่อประหยัดงบได้)"]);
    } else {
        systemLog($student_id, 'WASTE_SAFE', "Properly disposed waste.");
        echo json_encode(['status' => 'success', 'damage' => 0, 'message' => "กำจัดของเสียอย่างถูกต้องตามหลักความปลอดภัยในห้องปฏิบัติการ เยี่ยมมาก!"]);
    }
    exit;
}

// =========================================================================================
// ACTION: MIX (ผสมสารเคมี)
// =========================================================================================
$chemicals_in_beaker = $request['chemicals'] ?? [];
$wear_goggles = $request['safety']['goggles'] ?? false;
$wear_gloves = $request['safety']['gloves'] ?? false;
$fume_hood_closed = $request['safety']['fume_hood'] ?? false;
$env_temp = floatval($request['environment']['temperature'] ?? 25.0);
$is_stirred = $request['environment']['is_stirred'] ?? false;

if (count($chemicals_in_beaker) < 2) {
    echo json_encode(['status' => 'neutral', 'message' => 'ต้องมีสารอย่างน้อย 2 ชนิด', 'product_name' => 'สารละลายผสม', 'color' => '#e2e8f0', 'gas' => 'ไม่มี', 'precipitate' => 'ไม่มี', 'temperature_change' => 0, 'ph_result' => 7.0, 'damage' => 0]);
    exit;
}

$chem1 = $chemicals_in_beaker[0]; $chem2 = $chemicals_in_beaker[1];
$chem1_id = intval($chem1['id']); $chem1_amt = floatval($chem1['amount']); 
$chem2_id = intval($chem2['id']); $chem2_amt = floatval($chem2['amount']); 

$stmt_info = $conn->prepare("SELECT id, type, state, color_neutral FROM chemicals WHERE id IN (?, ?)");
$stmt_info->bind_param("ii", $chem1_id, $chem2_id);
$stmt_info->execute();
$res_info = $stmt_info->get_result();

$chem_details = [];
while ($row = $res_info->fetch_assoc()) { $chem_details[$row['id']] = $row; }
$stmt_info->close();

$ph_values = []; $has_solid = false;
foreach ($chemicals_in_beaker as $c) {
    $c_id = intval($c['id']);
    if (isset($chem_details[$c_id])) {
        $type = strtolower($chem_details[$c_id]['type']);
        if ($chem_details[$c_id]['state'] === 'solid') $has_solid = true;
        if (strpos($type, 'acid') !== false) $ph_values[] = 2.0;
        elseif (strpos($type, 'base') !== false || strpos($type, 'alkali') !== false) $ph_values[] = 12.0;
        else $ph_values[] = 7.0; 
    }
}

$final_ph = count($ph_values) > 0 ? array_sum($ph_values) / count($ph_values) : 7.0;

if ($has_solid && !$is_stirred) {
    echo json_encode(['status' => 'warning', 'message' => 'สารที่เป็นของแข็งตกตะกอน กรุณาใช้ "แท่งแก้วคนสาร"', 'product_name' => 'สารผสม (ยังไม่ละลาย)', 'color' => $chem_details[$chem1_id]['color_neutral'] ?? '#e2e8f0', 'gas' => 'ไม่มี', 'precipitate' => 'ผงสารเคมีนอนก้น', 'temperature_change' => 0, 'ph_result' => $final_ph, 'is_explosion' => false, 'damage' => 0]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM reactions WHERE (chem1_id = ? AND chem2_id = ?) OR (chem1_id = ? AND chem2_id = ?) LIMIT 1");
$stmt->bind_param("iiii", $chem1_id, $chem2_id, $chem2_id, $chem1_id);
$stmt->execute();
$res = $stmt->get_result();

$response_data = [];

if ($res->num_rows > 0) {
    $reaction = $res->fetch_assoc();
    $total_amount = $chem1_amt + $chem2_amt;
    $ratio = min($chem1_amt, $chem2_amt) / max($chem1_amt, $chem2_amt);
    $reaction_completeness = ($total_amount < 5) ? 0.5 : 1.0;

    if (strpos(strtolower($reaction['product_name']), 'vapor') !== false && $env_temp < 80) {
         echo json_encode(['status' => 'warning', 'message' => 'ปฏิกิริยานี้ต้องการความร้อน (อุณหภูมิยังไม่ถึงจุดเดือด) กรุณาเปิดไฟต้มสาร', 'product_name' => 'รอความร้อน', 'color' => $reaction['result_color'], 'gas' => 'ไม่มี', 'precipitate' => 'ไม่มี', 'temperature_change' => 0, 'ph_result' => $final_ph, 'is_explosion' => false, 'damage' => 0]);
        exit;
    }

    $final_color = $reaction['result_color'];
    $heat_generated = floatval($reaction['heat_level']) * $ratio * $reaction_completeness;
    
    if (in_array(2.0, $ph_values) && in_array(12.0, $ph_values)) { $heat_generated += 25.0; $final_ph = 7.0; }

    $is_explosive = intval($reaction['is_explosive']);
    $result_gas = ($ratio > 0.2) ? $reaction['result_gas'] : 'ไม่มี';
    
    $safety_failed = false; $damage_msg = ""; $hp_damage = 0;

    if ($result_gas !== 'ไม่มี' && !$fume_hood_closed) {
        $safety_failed = true; $hp_damage += 25; $damage_msg .= "☠️ คุณสูดดมแก๊สพิษ ($result_gas) ควรเลื่อนกระจกตู้ดูดควันลง! ";
    }

    if ($is_explosive == 1 && $total_amount > 10) {
        $safety_failed = true;
        if (!$wear_goggles) { $hp_damage += 40; $damage_msg .= "💥 ระเบิด! ไม่สวมแว่นตานิรภัย ดวงตาได้รับอันตราย! "; } 
        else { $hp_damage += 10; $damage_msg .= "💥 ระเบิด! โชคดีที่ใส่แว่นตานิรภัย ได้รับบาดเจ็บเล็กน้อย! "; }
    }

    if (($env_temp + $heat_generated) > 70 && !$wear_gloves) {
        $safety_failed = true; $hp_damage += 20; $damage_msg .= "🔥 บีกเกอร์ร้อนจัด! ไม่สวมถุงมือทำให้มือพุพอง! ";
    }

    if ($safety_failed) {
        $response_data = ['status' => 'danger', 'message' => $damage_msg, 'product_name' => 'เกิดอุบัติเหตุ!', 'color' => ($is_explosive == 1) ? '#000000' : $final_color, 'gas' => $result_gas, 'precipitate' => ($is_explosive == 1) ? 'เศษกระจกแตก' : 'สารตกค้าง', 'temperature_change' => $heat_generated, 'ph_result' => $final_ph, 'is_explosion' => ($is_explosive == 1), 'damage' => $hp_damage];
        systemLog($student_id, 'LAB_ACCIDENT', "Damage $hp_damage. Reason: $damage_msg");
    } else {
        $response_data = ['status' => 'success', 'message' => 'เกิดปฏิกิริยาทางเคมีสมบูรณ์ ปลอดภัยดีเยี่ยม!', 'product_name' => $reaction['product_name'], 'color' => $final_color, 'gas' => $result_gas, 'precipitate' => ($total_amount > 5) ? $reaction['result_precipitate'] : 'ไม่มี', 'temperature_change' => $heat_generated, 'ph_result' => $final_ph, 'is_explosion' => false, 'damage' => 0];
    }
} else {
    $mixed_color = '#e2e8f0'; 
    if (count($chem_details) == 2) { $mixed_color = ($chem1_amt > $chem2_amt) ? $chem_details[$chem1_id]['color_neutral'] : $chem_details[$chem2_id]['color_neutral']; }
    $response_data = ['status' => 'neutral', 'message' => 'ผสมกันทางกายภาพ (ไม่มีปฏิกิริยาเคมี)', 'product_name' => 'Mixture', 'color' => $mixed_color, 'gas' => 'ไม่มี', 'precipitate' => 'ไม่มี', 'temperature_change' => 0, 'ph_result' => $final_ph, 'is_explosion' => false, 'damage' => 0];
}

$stmt->close();
$conn->close();

echo json_encode($response_data);
exit;
?>
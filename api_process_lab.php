<?php
/**
 * api_process_lab.php - สมองกลประมวลผลปฏิกิริยาเคมี (Smart Keyword Scanning Engine)
 * แก้ไขปัญหา: ผสมสารแล้วไม่เกิดปฏิกิริยา
 */
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/rate_limiter.php';
checkApiRateLimit();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีข้อมูลส่งมา']);
    exit;
}

$chemicals = $data['chemicals'] ?? [];
$env = $data['environment'] ?? ['temp' => 25.0, 'stirring' => false];
$safety = $data['safety'] ?? ['goggles' => false, 'gloves' => false];

// ค่าเริ่มต้น (Default Response)
$response = [
    'is_explosion' => false,
    'gas' => 'ไม่มี',
    'precipitate' => 'ไม่มี',
    'color' => '#ffffff',
    'product_name' => 'สารละลายผสม',
    'message' => 'ผสมสารเข้าด้วยกัน แต่ไม่มีปฏิกิริยาเคมีใหม่เกิดขึ้น',
    'final_temp' => floatval($env['temp']),
    'ph_result' => 7.00,
    'damage' => 0,
    'residue_generated' => []
];

if (count($chemicals) < 2) {
    if (count($chemicals) == 1) {
        $response['color'] = $chemicals[0]['color'] ?? '#ffffff';
        $response['product_name'] = "สารละลาย " . $chemicals[0]['name'];
        $response['message'] = 'มีสารเพียงชนิดเดียวในบีกเกอร์';
    }
    echo json_encode($response);
    exit;
}

// 1. นำชื่อและสูตรสารเคมีทั้งหมดมารวมกัน เพื่อทำ Keyword Scanning
$combined_string = "";
foreach ($chemicals as $chem) {
    $combined_string .= strtolower($chem['name'] . " " . ($chem['formula'] ?? '') . " ");
}

// ==============================================================
// 🧪 SMART REACTION LOGIC (ตรวจจับคีย์เวิร์ด ไม่จำกัด ID)
// ==============================================================

// [1] โลหะโซเดียม + น้ำ = ระเบิด
if ((strpos($combined_string, 'sodium') !== false || strpos($combined_string, 'na') !== false || strpos($combined_string, 'โซเดียม') !== false) && 
    (strpos($combined_string, 'water') !== false || strpos($combined_string, 'น้ำ') !== false || strpos($combined_string, 'h2o') !== false)) {
    
    $response['is_explosion'] = true;
    $response['message'] = "เกิดการระเบิดอย่างรุนแรง! โลหะแอลคาไลทำปฏิกิริยารุนแรงกับน้ำ";
    $response['gas'] = "Hydrogen";
    $response['damage'] = 50;
    if (!$safety['goggles']) $response['damage'] += 50;

// [2] แมกนีเซียม + กรด HCl = แก๊สไฮโดรเจน + ความร้อน
} elseif ((strpos($combined_string, 'magnesium') !== false || strpos($combined_string, 'mg') !== false || strpos($combined_string, 'แมกนีเซียม') !== false) && 
          (strpos($combined_string, 'hydrochloric') !== false || strpos($combined_string, 'hcl') !== false || strpos($combined_string, 'กรด') !== false)) {
    
    $response['color'] = '#ffffff'; 
    $response['product_name'] = 'แมกนีเซียมคลอไรด์ + ก๊าซไฮโดรเจน';
    $response['gas'] = 'Hydrogen (H2)';
    $response['final_temp'] += 35.0; // คายความร้อน อุณหภูมิพุ่ง
    $response['ph_result'] = 2.5;
    $response['message'] = 'โลหะทำปฏิกิริยากับกรด เกิดฟองแก๊สและคายความร้อน';

// [3] เลด(II)ไนเตรต + โพแทสเซียมไอโอไดด์ = ตะกอนสีเหลือง
} elseif ((strpos($combined_string, 'lead') !== false || strpos($combined_string, 'pb') !== false || strpos($combined_string, 'เลด') !== false) && 
          (strpos($combined_string, 'iodide') !== false || strpos($combined_string, 'ki') !== false || strpos($combined_string, 'ไอโอไดด์') !== false)) {
    
    $response['color'] = '#fbbf24'; // เหลืองสด
    $response['product_name'] = 'เลด(II)ไอโอไดด์ (PbI2)';
    $response['precipitate'] = 'ตะกอนสีเหลืองสด';
    $response['message'] = 'เกิดปฏิกิริยาการตกตะกอน (Precipitation) สีเหลืองชัดเจน';

// [4] ไฮโดรเจนเปอร์ออกไซด์ + MnO2 หรือ ยีสต์ = แก๊สออกซิเจน
} elseif ((strpos($combined_string, 'peroxide') !== false || strpos($combined_string, 'h2o2') !== false) && 
          (strpos($combined_string, 'manganese') !== false || strpos($combined_string, 'mno2') !== false || strpos($combined_string, 'yeast') !== false || strpos($combined_string, 'ยีสต์') !== false)) {
    
    $response['color'] = '#e2e8f0'; // ขุ่น
    $response['product_name'] = 'ก๊าซออกซิเจน + น้ำ';
    $response['gas'] = 'Oxygen (O2)';
    $response['final_temp'] += 10.0;
    $response['message'] = 'ตัวเร่งปฏิกิริยาทำงาน ทำให้เกิดฟองแก๊สออกซิเจนอย่างรวดเร็ว';

// [5] ระบบกรด-เบส และ สมดุลทั่วไป
} else {
    $hasAcid = (strpos($combined_string, 'กรด') !== false || strpos($combined_string, 'acid') !== false || strpos($combined_string, 'hcl') !== false);
    $hasBase = (strpos($combined_string, 'เบส') !== false || strpos($combined_string, 'ไฮดรอกไซด์') !== false || strpos($combined_string, 'hydroxide') !== false || strpos($combined_string, 'naoh') !== false);
    
    // จำลองสีผสม
    $response['color'] = $chemicals[1]['color'] ?? $chemicals[0]['color'];

    if ($hasAcid && !$hasBase) {
        $response['ph_result'] = 2.50;
    } elseif ($hasBase && !$hasAcid) {
        $response['ph_result'] = 11.50;
    } elseif ($hasAcid && $hasBase) {
        $response['ph_result'] = 7.00;
        $response['color'] = '#ffffff'; // สีใส (สะเทิน)
        $response['product_name'] = 'เกลือ + น้ำ';
        $response['final_temp'] += 5.0; // สะเทินมักคายความร้อนเล็กน้อย
        $response['message'] = "เกิดปฏิกิริยาสะเทิน (Neutralization) ได้น้ำและเกลือ";
    }
}

echo json_encode($response);
?>
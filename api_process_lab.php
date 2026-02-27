<?php
// api_process_lab.php - เอนจินประมวลผลเคมีและฟิสิกส์ (Ultimate Realism Engine - Phase 3)
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (!$data || !isset($data['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Data Request']);
    exit;
}

// ตรวจสอบ CSRF
if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
    exit;
}

$action = $data['action'];

if ($action === 'mix') {
    $chemicals = $data['chemicals'] ?? [];
    $env = $data['environment'] ?? ['temp' => 25.0, 'stirring' => false];
    $safety = $data['safety'] ?? ['goggles' => false, 'gloves' => false];
    $quest_context = $data['quest_context'] ?? null;
    $residue = $data['residue'] ?? []; // รับข้อมูลสารตกค้างจากการทดลองรอบที่แล้ว

    if (empty($chemicals)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่มีสารเคมีในบีกเกอร์']);
        exit;
    }

    // 1. รวบรวมและจัดกลุ่มสารเคมี (รวมสารตกค้างด้วย)
    $mix_summary = [];
    $total_solid_mass = 0;
    $total_liquid_vol = 0;
    
    // นำสารตกค้างมารวมในการคำนวณ (Human Error Simulation)
    foreach ($residue as $res) {
        if (!isset($mix_summary[$res['id']])) {
            $mix_summary[$res['id']] = ['name' => 'สารตกค้าง('.$res['name'].')', 'amt' => 0, 'state' => $res['state']];
        }
        $mix_summary[$res['id']]['amt'] += $res['amt'];
    }

    foreach ($chemicals as $chem) {
        $id = $chem['id'];
        if (!isset($mix_summary[$id])) {
            $mix_summary[$id] = ['name' => $chem['name'], 'amt' => 0, 'state' => $chem['state'], 'color' => $chem['color']];
        }
        $mix_summary[$id]['amt'] += $chem['amt'];
        
        if ($chem['state'] === 'solid') $total_solid_mass += $chem['amt'];
        if ($chem['state'] === 'liquid') $total_liquid_vol += $chem['amt'];
    }

    $chem_ids = array_keys($mix_summary);
    
    // 2. ดึงข้อมูลปฏิกิริยาจากฐานข้อมูล (ค้นหาคู่สารเคมีที่ตรงกัน)
    $reaction_found = false;
    $result_data = [
        'status' => 'info',
        'message' => 'สารผสมเข้าด้วยกัน แต่ไม่มีปฏิกิริยาเคมีเกิดขึ้น',
        'product_name' => 'สารละลายผสม',
        'color' => '#A0C4FF', // สีตั้งต้น (ถ้าไม่มีการเปลี่ยนสี)
        'ph_result' => 7.0,
        'final_temp' => $env['temp'],
        'gas' => 'ไม่มี',
        'precipitate' => 'ไม่มีตะกอน',
        'is_explosion' => false,
        'damage' => 0,
        'residue_generated' => []
    ];

    if (count($chem_ids) >= 2) {
        // สร้างเงื่อนไขหาปฏิกิริยา (จำลองการดึงมาเช็คทีละคู่)
        $id1 = $chem_ids[0];
        $id2 = $chem_ids[1];

        $stmt = $conn->prepare("SELECT * FROM reactions WHERE (chem1_id = ? AND chem2_id = ?) OR (chem1_id = ? AND chem2_id = ?) LIMIT 1");
        $stmt->bind_param("iiii", $id1, $id2, $id2, $id1);
        $stmt->execute();
        $reaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($reaction) {
            $reaction_found = true;
            $result_data['status'] = 'success';
            $result_data['product_name'] = $reaction['product_name'];
            $result_data['color'] = $reaction['result_color'];
            $result_data['gas'] = $reaction['result_gas'];
            $result_data['is_explosion'] = (bool)$reaction['is_explosive'];
            
            // --- อุณหพลศาสตร์ (Thermodynamics) ---
            // ถ้า heat_level > 0 คือคายความร้อน (Exothermic)
            // ถ้า heat_level < 0 คือดูดความร้อน (Endothermic)
            $heat_change = floatval($reaction['heat_level']);
            
            // ความร้อนขึ้นอยู่กับปริมาณสาร (ยิ่งใส่เยอะยิ่งร้อน/เย็นจัด)
            $mass_factor = min(($mix_summary[$id1]['amt'] + $mix_summary[$id2]['amt']) / 20.0, 5.0); // ตันที่ 5 เท่า
            $actual_heat_change = $heat_change * $mass_factor;
            
            $result_data['final_temp'] = $env['temp'] + $actual_heat_change;

            // คำนวณความเสียหาย (Damage)
            if ($result_data['is_explosion']) {
                $damage = 40;
                if (!$safety['goggles']) $damage += 20; // ไม่ใส่แว่นโดนหนักขึ้น
                if (!$safety['gloves']) $damage += 10;
                $result_data['damage'] = $damage;
                $result_data['message'] = "เกิดการระเบิดอย่างรุนแรง!";
            } else {
                $damage = 0;
                if ($reaction['toxicity_bonus'] > 0 && (!$safety['goggles'] || !$safety['gloves'])) {
                    $damage += intval($reaction['toxicity_bonus']);
                    $result_data['message'] = "คุณได้รับไอพิษจากการไม่สวมอุปกรณ์ป้องกัน!";
                }
                $result_data['damage'] = $damage;
            }
        }
    }

    // 3. ระบบจุดอิ่มตัวและการละลาย (Saturation & Dissolving)
    // จำลองสมมติฐาน: ของเหลว 1 ml ละลายของแข็งได้ 0.5 g (ถ้าไม่คน) และละลายได้ 1.2 g (ถ้าใช้แท่งแก้วคน)
    $solubility_rate = $env['stirring'] ? 1.2 : 0.5; 
    
    // ถ้าอุณหภูมิสูง การละลายจะดีขึ้น (เพิ่มอีก 0.5% ต่อองศาที่เกิน 25)
    if ($result_data['final_temp'] > 25) {
        $solubility_rate += (($result_data['final_temp'] - 25) * 0.005);
    }

    $max_dissolvable = $total_liquid_vol * $solubility_rate;

    if ($total_solid_mass > 0 && $total_liquid_vol > 0) {
        if ($total_solid_mass > $max_dissolvable) {
            $undissolved = $total_solid_mass - $max_dissolvable;
            $result_data['precipitate'] = "ตะกอนนอนก้น ({$undissolved} g)";
            $result_data['message'] .= " (สารละลายอิ่มตัวและมีตะกอน)";
            
            // บันทึกเป็นสารตกค้างสำหรับการทดลองรอบถัดไป
            $result_data['residue_generated'][] = [
                'id' => $chem_ids[0], 
                'name' => 'ตะกอนตกค้าง', 
                'state' => 'solid', 
                'amt' => $undissolved
            ];
        } else {
            if (!$env['stirring'] && $total_solid_mass > ($max_dissolvable * 0.5)) {
                $result_data['message'] .= " (สารละลายช้า แนะนำให้ใช้แท่งแก้วคนสาร)";
            }
        }
    } else if ($total_liquid_vol == 0 && $total_solid_mass > 0) {
        $result_data['message'] = "มีแต่ของแข็ง ไม่เกิดปฏิกิริยาเคมี";
        $result_data['color'] = $mix_summary[$chem_ids[0]]['color'] ?? '#ffffff';
    }

    // 4. การผสมสีแบบละเอียด (ถ้าไม่มีปฏิกิริยา)
    if (!$reaction_found && count($chem_ids) > 1 && $total_liquid_vol > 0) {
        // ง่ายๆ คือเอาสีมาผสมกัน (Blend Hex) แบบคร่าวๆ หรือคงสีของตัวที่ปริมาณเยอะสุด
        $dominant_color = '#ffffff';
        $max_amt = 0;
        foreach ($mix_summary as $id => $data) {
            if ($data['state'] === 'liquid' && $data['amt'] > $max_amt) {
                $max_amt = $data['amt'];
                $dominant_color = $data['color'];
            }
        }
        $result_data['color'] = $dominant_color;
    }

    echo json_encode($result_data);
    exit;
}
?>
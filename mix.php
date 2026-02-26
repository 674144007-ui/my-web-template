<?php
// mix.php
header("Content-Type: application/json");
require_once "db.php"; 

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    function hexToThaiColorName($hex) {
        if (!$hex) return "ไม่มีสี / ใส";
        $hex = strtoupper(trim($hex, '#'));
        switch ($hex) {
            case 'FFFFFF': return "สีขาวใส";
            case 'F0F8FF': return "สีขาวขุ่น / ควัน";
            case 'FF0000': return "สีแดงสด";
            case '8B0000': return "สีแดงเลือดหมู / สนิม";
            case '00BFFF': return "สีฟ้าสดใส";
            case '0000FF': return "สีน้ำเงินเข้ม";
            case 'FFFF00': return "สีเหลืองสด";
            case 'D4AF37': case 'FFD700': return "สีทอง";
            case '800080': return "สีม่วง";
            case '000000': return "สีดำ / ตะกอน";
            case 'C0C0C0': return "สีเงิน / เทา";
            default: return "สีผสม (Hex: #$hex)"; 
        }
    }

    function mixColorsWeighted($hex1, $vol1, $hex2, $vol2) {
        $hex1 = ($hex1 && $hex1 != '') ? $hex1 : '#FFFFFF';
        $hex2 = ($hex2 && $hex2 != '') ? $hex2 : '#FFFFFF';
        
        list($r1, $g1, $b1) = sscanf($hex1, "#%02x%02x%02x");
        list($r2, $g2, $b2) = sscanf($hex2, "#%02x%02x%02x");

        $totalVol = $vol1 + $vol2;
        if ($totalVol <= 0) return $hex1;

        $r = round(($r1 * $vol1 + $r2 * $vol2) / $totalVol);
        $g = round(($g1 * $vol1 + $g2 * $vol2) / $totalVol);
        $b = round(($b1 * $vol1 + $b2 * $vol2) / $totalVol);

        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }

    $id_a = isset($_GET['a']) ? intval($_GET['a']) : 0;
    $id_b = isset($_GET['b']) ? intval($_GET['b']) : 0;
    $vol_a = isset($_GET['volA']) ? floatval($_GET['volA']) : 0;
    $vol_b = isset($_GET['volB']) ? floatval($_GET['volB']) : 0;

    $stmt = $conn->prepare("SELECT id, name, type, color_neutral, toxicity, state FROM chemicals WHERE id IN (?, ?)");
    $stmt->bind_param("ii", $id_a, $id_b);
    $stmt->execute();
    $res = $stmt->get_result();
    $chemicals = [];
    while ($row = $res->fetch_assoc()) $chemicals[$row['id']] = $row;
    
    if (count($chemicals) < 2) throw new Exception("Data not found for IDs");
    $cA = $chemicals[$id_a];
    $cB = $chemicals[$id_b];

    $total_volume = $vol_a + $vol_b;
    $special_color = mixColorsWeighted($cA['color_neutral'], $vol_a, $cB['color_neutral'], $vol_b);
    
    $product_name = "สารละลายผสม"; 
    $precipitate = "ไม่มีตะกอน";
    $gas_result = "ไม่มีแก๊ส";
    $damage_player = ($cA['toxicity'] + $cB['toxicity']) / 2;
    $effect_type = "normal";
    $final_state = "liquid"; 
    $has_bubbles = false;
    $bubble_color = "#FFFFFF";
    
    $final_temp = 25.0; 

    $sql = "SELECT * FROM reactions WHERE (chem1_id=? AND chem2_id=?) OR (chem1_id=? AND chem2_id=?) LIMIT 1";
    $stmt2 = $conn->prepare($sql);
    $stmt2->bind_param("iiii", $id_a, $id_b, $id_b, $id_a);
    $stmt2->execute();
    $react = $stmt2->get_result()->fetch_assoc();

    if ($react) {
        if(isset($react['product_name'])) $product_name = $react['product_name'];
        if(isset($react['result_color'])) $special_color = $react['result_color'];
        if(isset($react['result_precipitate'])) $precipitate = $react['result_precipitate'];
        
        if(isset($react['result_gas']) && $react['result_gas'] != 'ไม่มีแก๊ส') {
            $gas_result = $react['result_gas'];
            $has_bubbles = true;
            if(isset($react['gas_color'])) $bubble_color = $react['gas_color'];
        }

        $final_temp += floatval(isset($react['heat_level']) ? $react['heat_level'] : 0);
        if ($final_temp >= 100) $final_state = 'gas';
        
        $damage_player += intval(isset($react['toxicity_bonus']) ? $react['toxicity_bonus'] : 0);
        
        if (isset($react['is_explosive']) && $react['is_explosive']) {
            $effect_type = "explosion";
            $special_color = "#000000"; 
            $damage_player = 100;
        }
    }

    echo json_encode([
        "success" => true,
        "product_name" => $product_name,
        "product_formula" => "-", 
        "color_name_thai" => hexToThaiColorName($special_color),
        
        "special_color" => $special_color,
        "liquid_color" => $special_color,
        "bubble_color" => $bubble_color,
        "has_bubbles" => $has_bubbles,
        
        "total_volume" => round($total_volume, 2),
        "temperature" => round($final_temp, 1),
        "final_state" => $final_state,
        "precipitate" => $precipitate,
        "gas" => $gas_result,
        "damage_player" => $damage_player,
        "effect_type" => $effect_type
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
if (isset($conn)) $conn->close();
?>
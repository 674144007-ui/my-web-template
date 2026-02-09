<?php
header("Content-Type: application/json");

$levels = [
    [
        "id" => 1,
        "title" => "ด่าน 1: กรด–เบสเป็นกลาง",
        "goal" => "ทำให้ pH ใกล้ 7",
        "allowed" => ["HCl", "NaOH", "H2O"]
    ],
    [
        "id" => 2,
        "title" => "ด่าน 2: สร้างตะกอน",
        "goal" => "เกิด AgCl สีขาว",
        "allowed" => ["AgNO3", "NaCl"]
    ],
    [
        "id" => 3,
        "title" => "ด่าน 3: ปฏิกิริยาคายความร้อน",
        "goal" => "อุณหภูมิ > 35°C",
        "allowed" => ["HCl", "NaOH"]
    ]
];

echo json_encode($levels);

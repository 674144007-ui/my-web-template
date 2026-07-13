<?php
// get_chemicals.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$sql = "SELECT id, name FROM chemicals ORDER BY name ASC";
$result = $pdo->query($sql);

$chemicals = [];
if ($result->rowCount() > 0) {
    while($row = $result->fetch()) {
        $chemicals[] = [
            'value' => $row['id'],
            'text'  => $row['name']
        ];
    }
}

echo json_encode($chemicals);

?>
<?php
// get_chemicals.php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$sql = "SELECT id, name FROM chemicals ORDER BY name ASC";
$result = $conn->query($sql);

$chemicals = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $chemicals[] = [
            'value' => $row['id'],
            'text'  => $row['name']
        ];
    }
}

echo json_encode($chemicals);
$conn->close();
?>
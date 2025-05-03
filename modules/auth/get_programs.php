<?php
require '../../config/db.php';
$dept_id = $_GET['department_id'] ?? 0;
if ($dept_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? ORDER BY name");
    $stmt->execute([$dept_id]);
    echo '<option value="">-- Select Course --</option>';
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo '<option value="'.$row['id'].'">'.htmlspecialchars($row['name']).'</option>';
    }
}

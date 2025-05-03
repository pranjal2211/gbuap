<?php
require '../../config/db.php';
$prog_id = $_GET['program_id'] ?? 0;
$year = $_GET['year'] ?? 0;
if ($prog_id && $year) {
    $stmt = $pdo->prepare("SELECT DISTINCT section_name FROM sections WHERE program_id = ? AND year = ? ORDER BY section_name");
    $stmt->execute([$prog_id, $year]);
    echo '<option value="">-- Select Section --</option>';
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo '<option value="'.$row['section_name'].'">'.htmlspecialchars($row['section_name']).'</option>';
    }
}

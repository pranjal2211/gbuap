<?php
session_start();
require '../../config/db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'parent') header('Location: ../auth/login.php');
$parent_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Parent Portal</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h2>Welcome, Parent</h2>
    <h4>Your Child's Attendance</h4>
    <table class="table table-bordered">
        <thead><tr><th>Child</th><th>Date</th><th>Status</th></tr></thead>
        <tbody>
        <?php
        $stmt = $pdo->prepare("SELECT s.name, a.date, a.status FROM students s JOIN attendance a ON s.id = a.student_id WHERE s.parent_id = ? ORDER BY a.date DESC");
        $stmt->execute([$parent_id]);
        foreach ($stmt as $row) {
            $status = ucfirst($row['status']);
            echo "<tr><td>{$row['name']}</td><td>{$row['date']}</td><td>$status</td></tr>";
        }
        ?>
        </tbody>
    </table>
</div>
</body>
</html>

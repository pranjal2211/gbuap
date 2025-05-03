<?php
session_start();
require '../../config/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) exit;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$mode = $_GET['mode'] ?? 'received';

$allowed_sender_roles = [];
if ($role === 'admin')       $allowed_sender_roles = ['hod'];
elseif ($role === 'hod')     $allowed_sender_roles = ['admin', 'teacher'];
elseif ($role === 'teacher') $allowed_sender_roles = ['student','hod','admin'];
elseif ($role === 'student') $allowed_sender_roles = ['admin', 'hod', 'teacher'];
elseif ($role === 'parent')  $allowed_sender_roles = ['admin', 'hod', 'teacher'];

try {
    if ($mode === 'received') {
        $placeholders = implode(',', array_fill(0, count($allowed_sender_roles), '?'));
        $sql = "SELECT n.*, u.username AS sender_name FROM notifications n
                LEFT JOIN users u ON n.sender_id = u.id
                WHERE n.user_id = ? AND n.sender_role IN ($placeholders)
                ORDER BY n.created_at DESC LIMIT 50";
        $params = array_merge([$user_id], $allowed_sender_roles);
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "<li class='notif-empty text-muted'>No notifications found.</li>";
        } else {
            foreach ($rows as $row) {
                $unread = $row['is_read'] ? '' : 'unread';
                $sender = htmlspecialchars($row['sender_name'] ?? $row['sender_role']);
                $msg = htmlspecialchars($row['message']);
                $time = date('d M Y h:i A', strtotime($row['created_at']));
                echo "<li class='$unread'><b>From:</b> $sender<br>$msg<span class='notif-time'>$time</span></li>";
            }
        }
    } elseif ($mode === 'sent') {
        $sql = "SELECT n.*, u.username AS recipient_name FROM notifications n
                LEFT JOIN users u ON n.user_id = u.id
                WHERE n.sender_id = ?
                ORDER BY n.created_at DESC LIMIT 50";
        $stmt = $pdo->prepare($sql); $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "<li class='notif-empty text-muted'>No sent notifications found.</li>";
        } else {
            foreach ($rows as $row) {
                $msg = htmlspecialchars($row['message']);
                $recipient = htmlspecialchars($row['recipient_name'] ?? $row['user_id']);
                $time = date('d M Y h:i A', strtotime($row['created_at']));
                echo "<li><b>To:</b> $recipient<br>$msg<span class='notif-time'>$time</span></li>";
            }
        }
    }
} catch (PDOException $e) {
    echo "<li class='notif-empty text-danger'>DB Error: " . htmlspecialchars($e->getMessage()) . "</li>";
}

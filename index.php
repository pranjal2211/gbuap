<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: modules/dashboard/dashboard.php');
    exit;
} else {
    header('Location: modules/auth/login.php');
    exit;
}
?>

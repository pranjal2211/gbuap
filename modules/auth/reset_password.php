<?php
require '../../config/db.php';
$error = '';
$success = '';
$show_form = false;

if (isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = $_GET['email'];
    // Check token validity
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND expires_at > NOW()");
    $stmt->execute([$email, $token]);
    $reset = $stmt->fetch();
    if ($reset) {
        $show_form = true;
    } else {
        $error = "Invalid or expired reset link.";
    }
} else {
    $error = "Invalid reset link.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirm = $_POST['confirm'];
    if ($password !== $confirm) {
        $error = "Passwords do not match.";
        $show_form = true;
    } else {
        // Update password (plain text for your current setup)
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$password, $email]);
        // Delete the reset token
        $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
        $success = "Password has been reset. <a href='login.php'>Login here</a>.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5" style="max-width:400px;">
    <h3 class="mb-3">Reset Password</h3>
    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($show_form): ?>
        <form method="post">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm" class="form-control" required>
            </div>
            <button class="btn btn-danger w-100" type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

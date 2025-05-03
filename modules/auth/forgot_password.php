<?php
require '../../config/db.php';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    // Check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = "No user found with this email.";
    } else {
        // Generate token and expiry
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        // Store token
        $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
            ->execute([$email, $token, $expires_at]);
        // Send email (for local testing, just display the link)
        $reset_link = "http://localhost/attendence-system/modules/auth/reset_password.php?token=$token&email=$email";
        $success = "A password reset link has been sent to your email.<br><a href='$reset_link'>Reset Password (for testing)</a>";
        // In production, use PHPMailer to send $reset_link to $email
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5" style="max-width:400px;">
    <h3 class="mb-3">Forgot Password</h3>
    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label class="form-label">Enter your registered email</label>
            <input type="email" name="email" class="form-control" required>
        </div>
        <button class="btn btn-danger w-100" type="submit">Send Reset Link</button>
    </form>
    <div class="mt-3"><a href="login.php">Back to Login</a></div>
</div>
</body>
</html>

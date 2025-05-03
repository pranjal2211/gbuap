<?php
// --- PHP Backend Logic (UNCHANGED) ---
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (empty($email) || empty($password) || empty($role)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
            $stmt->execute([$email, $role]);
            $user = $stmt->fetch();

            // !!! SECURITY WARNING: Plain text password comparison !!!
            if ($user && $password === $user['password']) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['theme'] = $user['theme'] ?? 'light';
                $_SESSION['language'] = $user['language'] ?? 'en';

                switch ($user['role']) {
                    case 'student': case 'parent': header('Location: ../dashboard/dashboard.php'); break;
                    case 'teacher': header('Location: ../dashboard/dashboard_teacher.php'); break;
                    case 'admin': header('Location: ../dashboard/dashboard_admin.php'); break;
                    case 'hod': header('Location: ../dashboard/dashboard_hod.php'); break;
                    default: header('Location: ../dashboard/dashboard.php');
                }
                exit;
            } else {
                $error = "Invalid credentials or role.";
            }
        } catch (PDOException $e) {
            $error = "Database error. Please try again later.";
            error_log("Login DB Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>GBU Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f6f8f5; margin: 0; min-height: 100vh; font-family: 'Helvetica', Arial, sans-serif;}
        .split { display: flex; min-height: 100vh; }
        .left {
            flex: 1;
            background: linear-gradient(135deg, #fff 0%, #f6f8f5 100%);
            display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 2rem;
            border-right: 1.5px solid #f0eaea;
        }
        .logo { width: 110px; margin-bottom: 20px;}
        .heading { font-size: 2.0rem; font-weight: bold; letter-spacing: 0.01em; color:#d32f2f;}
        .subtitle { color: #555; margin-bottom: 40px; font-size:1.09rem;}
        .right {
            flex: 1;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            min-height: 100vh;
        }
        .login-form {
            width: 100%;
            max-width: 430px;
            background: #fff;
            border-radius: 1.2rem;
            box-shadow: 0 2px 12px 0 rgba(211,77,77,0.06);
            padding: 2.2rem 2.1rem 2.1rem 2.1rem;
        }
        .form-title { font-size:2.1rem; font-weight:700; color:#d32f2f; text-align:center; margin-bottom:0.3rem; letter-spacing:0.01em;}
        .form-subtitle { font-size:1.08rem; color:#888; text-align:center; margin-bottom:2.2rem;}
        .form-label {
            font-weight: 700;
            margin-bottom: 0.2rem;
            font-size: 0.97rem;
        }
        .form-control, .form-select { margin-bottom: 0.9rem; font-size: 1.03rem; padding: 0.7rem 1rem; border-radius: 0.4rem;}
        .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-container .form-control {
            padding-right: 2.5rem;
            width: 100%;
        }
        .toggle-password-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            font-size: 1.1rem;
            z-index: 2;
        }
        input[type="password"]::-ms-reveal,
        input[type="password"]::-webkit-reveal,
        input[type="password"]::-webkit-clear-button {
            display: none;
        }
        .btn-primary {
            background: #d32f2f !important; border: none; padding: 1.1rem 0;
            font-weight: 600; font-size: 1.17rem; border-radius: 0.6rem; margin-top: 1.1rem;
        }
        .btn-primary:hover, .btn-primary:focus { background: #a31515 !important; }
        .link {
            color: #d32f2f; text-decoration: none; transition: color 0.2s;
            font-size: 0.98rem; font-weight: 500;
        }
        .link:hover { text-decoration: underline; color: #a31515; }
        .alert { font-size: 0.98rem; padding: 0.7rem 1rem; margin-bottom: 1rem; border-radius: 0.4rem;}
        @media (max-width: 992px) {
            .split { flex-direction: column; min-height: auto; }
            .left { padding: 3rem 1rem; min-height: 250px; border-right: none; border-bottom: 1.5px solid #f0eaea;}
            .right { padding: 2rem 1rem; min-height: auto; }
            .login-form { max-width: 100%; }
            .form-title { font-size:1.5rem; }
        }
        @media (max-width: 576px) {
            .form-title { font-size: 1.2rem; }
            .form-subtitle { font-size: 0.9rem; }
            .left { min-height: 200px; }
        }
    </style>
</head>
<body>
<div class="split">
    <div class="left">
        <img src="../../assets/gbu-logo.png" class="logo" alt="GBU Logo">
        <div class="heading">GAUTAM BUDDHA UNIVERSITY</div>
        <div class="subtitle">An Ultimate Destination For Higher Learning</div>
    </div>
    <div class="right">
        <div class="login-form">
            <div class="form-title mb-1">Welcome Back</div>
            <div class="form-subtitle mb-4">Sign in to your GBU Attendance Portal</div>
            <?php if($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="login.php">
                <label class="form-label">University Mail</label>
                <input type="email" name="email" class="form-control" placeholder="Enter your university ID" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

                <label class="form-label">Password</label>
                <div class="password-container">
                    <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Enter your password" required>
                    <i class="fas fa-eye toggle-password-icon" id="togglePasswordIcon"></i>
                </div>

                <label class="form-label">Select Role</label>
                <select name="role" class="form-select" required>
                    <option value="">Choose your role</option>
                    <option value="student" <?= (($_POST['role'] ?? '') == 'student') ? 'selected' : '' ?>>Student</option>
                    <option value="teacher" <?= (($_POST['role'] ?? '') == 'teacher') ? 'selected' : '' ?>>Teacher</option>
                    <option value="hod" <?= (($_POST['role'] ?? '') == 'hod') ? 'selected' : '' ?>>HOD</option>
                    <option value="admin" <?= (($_POST['role'] ?? '') == 'admin') ? 'selected' : '' ?>>Admin</option>
                    <option value="parent" <?= (($_POST['role'] ?? '') == 'parent') ? 'selected' : '' ?>>Parent</option>
                </select>
                <div class="d-flex justify-content-between mb-3">
                    <a href="register.php" class="link">Create Account</a>
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const passwordInput = document.getElementById('passwordInput');
        const toggleIcon = document.getElementById('togglePasswordIcon');
        if (toggleIcon && passwordInput) {
            toggleIcon.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        }
    });
</script>
</body>
</html>

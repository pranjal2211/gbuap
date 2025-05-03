<?php
session_start();
require '../../config/db.php';
$msg = '';
$allowed_roles = ['student','parent'];

// Fetch all departments for dropdown
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $department_id = $_POST['department_id'] ?? '';
    $program_id = $_POST['program_id'] ?? '';
    $year = $_POST['year'] ?? '';
    $section = $_POST['section'] ?? '';
    $roll = $_POST['roll'] ?? '';
    $parent_email = ($role === 'student') ? filter_var(trim($_POST['parent_email']), FILTER_SANITIZE_EMAIL) : null;
    $student_email = ($role === 'parent') ? filter_var(trim($_POST['student_email']), FILTER_SANITIZE_EMAIL) : null;

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
        $msg = "<div class='alert alert-danger'>All required fields must be filled.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "<div class='alert alert-danger'>Invalid email format.</div>";
    } elseif ($password !== $confirm_password) {
        $msg = "<div class='alert alert-danger'>Passwords do not match.</div>";
    } elseif (strlen($password) < 6) {
        $msg = "<div class='alert alert-danger'>Password must be at least 6 characters long.</div>";
    } elseif (!in_array($role, $allowed_roles)) {
        $msg = "<div class='alert alert-danger'>Invalid role selected.</div>";
    } else {
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
            $stmt_check->execute([$email, $username]);
            if ($stmt_check->fetchColumn() > 0) {
                $msg = "<div class='alert alert-danger'>Username or Email already exists.</div>";
            } else {
                $parent_user_id = NULL;
                $student_user_id = NULL;
                if ($role === 'student') {
                    if (empty($parent_email) || !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
                        $msg = "<div class='alert alert-danger'>Valid Parent's Email is required.</div>";
                    } else {
                        $stmt_parent = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role = 'parent'");
                        $stmt_parent->execute([$parent_email]);
                        $parent_data = $stmt_parent->fetch();
                        if (!$parent_data) {
                            $msg = "<div class='alert alert-danger'>Parent's Email not found or user is not a parent. Parent must register first.</div>";
                        } else {
                            $parent_user_id = $parent_data['id'];
                        }
                    }
                } elseif ($role === 'parent') {
                    if (empty($student_email) || !filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
                        $msg = "<div class='alert alert-danger'>Valid Student's Email is required.</div>";
                    } else {
                        $stmt_student = $pdo->prepare("SELECT id, parent_id FROM users WHERE email = ? AND role = 'student'");
                        $stmt_student->execute([$student_email]);
                        $student_data = $stmt_student->fetch();
                        if (!$student_data) {
                            $msg = "<div class='alert alert-danger'>Student's Email not found or user is not a student.</div>";
                        } elseif (!empty($student_data['parent_id'])) {
                            $msg = "<div class='alert alert-danger'>This student is already linked to another parent.</div>";
                        } else {
                            $student_user_id = $student_data['id'];
                        }
                    }
                }
                if (empty($msg)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "INSERT INTO users (username, email, password, role, parent_id, department_id, program_id, year, section, roll, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $parent_id_to_insert = ($role === 'student') ? $parent_user_id : NULL;
                    $stmt_insert = $pdo->prepare($sql);
                    if ($stmt_insert->execute([$username, $email, $hashed_password, $role, $parent_id_to_insert, $department_id, $program_id, $year, $section, $roll])) {
                        $new_user_id = $pdo->lastInsertId();
                        if ($role === 'parent' && $student_user_id && $new_user_id) {
                            $stmt_update_student = $pdo->prepare("UPDATE users SET parent_id = ? WHERE id = ?");
                            if (!$stmt_update_student->execute([$new_user_id, $student_user_id])) {
                                $msg = "<div class='alert alert-warning'>Parent registered, but linking student failed. Contact admin.</div>";
                            } else {
                                $msg = "<div class='alert alert-success'>Registration successful! Student linked. You can now log in.</div>";
                            }
                        } else {
                            $msg = "<div class='alert alert-success'>Registration successful! You can now log in.</div>";
                        }
                    } else {
                        $msg = "<div class='alert alert-danger'>Registration failed. DB error.</div>";
                    }
                }
            }
        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>
<?php
session_start();
require '../../config/db.php';
$msg = '';
$allowed_roles = ['student','parent'];

// Fetch all departments for dropdown
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $department_id = $_POST['department_id'] ?? '';
    $program_id = $_POST['program_id'] ?? '';
    $year = $_POST['year'] ?? '';
    $section = $_POST['section'] ?? '';
    $roll = $_POST['roll'] ?? '';
    $parent_email = ($role === 'student') ? filter_var(trim($_POST['parent_email']), FILTER_SANITIZE_EMAIL) : null;
    $student_email = ($role === 'parent') ? filter_var(trim($_POST['student_email']), FILTER_SANITIZE_EMAIL) : null;

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
        $msg = "<div class='alert alert-danger'>All required fields must be filled.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "<div class='alert alert-danger'>Invalid email format.</div>";
    } elseif ($password !== $confirm_password) {
        $msg = "<div class='alert alert-danger'>Passwords do not match.</div>";
    } elseif (strlen($password) < 6) {
        $msg = "<div class='alert alert-danger'>Password must be at least 6 characters long.</div>";
    } elseif (!in_array($role, $allowed_roles)) {
        $msg = "<div class='alert alert-danger'>Invalid role selected.</div>";
    } else {
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
            $stmt_check->execute([$email, $username]);
            if ($stmt_check->fetchColumn() > 0) {
                $msg = "<div class='alert alert-danger'>Username or Email already exists.</div>";
            } else {
                $parent_user_id = NULL;
                $student_user_id = NULL;
                if ($role === 'student') {
                    if (empty($parent_email) || !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
                        $msg = "<div class='alert alert-danger'>Valid Parent's Email is required.</div>";
                    } else {
                        $stmt_parent = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role = 'parent'");
                        $stmt_parent->execute([$parent_email]);
                        $parent_data = $stmt_parent->fetch();
                        if (!$parent_data) {
                            $msg = "<div class='alert alert-danger'>Parent's Email not found or user is not a parent. Parent must register first.</div>";
                        } else {
                            $parent_user_id = $parent_data['id'];
                        }
                    }
                } elseif ($role === 'parent') {
                    if (empty($student_email) || !filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
                        $msg = "<div class='alert alert-danger'>Valid Student's Email is required.</div>";
                    } else {
                        $stmt_student = $pdo->prepare("SELECT id, parent_id FROM users WHERE email = ? AND role = 'student'");
                        $stmt_student->execute([$student_email]);
                        $student_data = $stmt_student->fetch();
                        if (!$student_data) {
                            $msg = "<div class='alert alert-danger'>Student's Email not found or user is not a student.</div>";
                        } elseif (!empty($student_data['parent_id'])) {
                            $msg = "<div class='alert alert-danger'>This student is already linked to another parent.</div>";
                        } else {
                            $student_user_id = $student_data['id'];
                        }
                    }
                }
                if (empty($msg)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "INSERT INTO users (username, email, password, role, parent_id, department_id, program_id, year, section, roll, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $parent_id_to_insert = ($role === 'student') ? $parent_user_id : NULL;
                    $stmt_insert = $pdo->prepare($sql);
                    if ($stmt_insert->execute([$username, $email, $hashed_password, $role, $parent_id_to_insert, $department_id, $program_id, $year, $section, $roll])) {
                        $new_user_id = $pdo->lastInsertId();
                        if ($role === 'parent' && $student_user_id && $new_user_id) {
                            $stmt_update_student = $pdo->prepare("UPDATE users SET parent_id = ? WHERE id = ?");
                            if (!$stmt_update_student->execute([$new_user_id, $student_user_id])) {
                                $msg = "<div class='alert alert-warning'>Parent registered, but linking student failed. Contact admin.</div>";
                            } else {
                                $msg = "<div class='alert alert-success'>Registration successful! Student linked. You can now log in.</div>";
                            }
                        } else {
                            $msg = "<div class='alert alert-success'>Registration successful! You can now log in.</div>";
                        }
                    } else {
                        $msg = "<div class='alert alert-danger'>Registration failed. DB error.</div>";
                    }
                }
            }
        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - GBU Attendance</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f6f8f5; margin: 0; min-height: 100vh; }
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
        .right { flex: 1; background: #fff; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 2rem;}
        .register-form { width: 100%; max-width: 430px; }
        .progress-bar-container { width: 100%; background: #f0eaea; border-radius: 2rem; height: 15px; margin-bottom: 2.1rem; overflow: hidden;}
        .progress-bar { height: 100%; background: linear-gradient(90deg, rgb(211,77,77) 0%, rgb(255,128,128) 100%); border-radius: 2rem; transition: width 0.3s;}
        .stepper-nav { display: flex; justify-content: space-between; margin-bottom: 1.7rem; }
        .step-btn { flex:1; background: #f6f8f5; border: none; color: #d32f2f; font-weight: 600; font-size: 1.08rem; border-radius: 1.2rem; margin: 0 0.3rem; padding: 0.9rem 0 0.9rem 0; box-shadow: none; transition: background 0.2s, box-shadow 0.2s;}
        .step-btn.active, .step-btn:focus { background: #fff2f2; color: #d32f2f; box-shadow: 0 2px 10px rgba(211,77,77,0.09); border: 2.5px solid #d34d4d;}
        .stepper-section { display: none; }
        .stepper-section.active { display: block; animation: fadeIn 0.4s;}
        @keyframes fadeIn { from {opacity:0;} to {opacity:1;} }
        .form-label { font-weight: 500; margin-bottom: 0.2rem; font-size: 0.97rem; }
        .form-control, .form-select { margin-bottom: 0.9rem; font-size: 1.03rem; padding: 0.7rem 1rem; border-radius: 0.4rem;}
        .btn-primary { background: #d34d4d !important; border: none; padding: 1.1rem 0; font-weight: 600; font-size: 1.17rem; border-radius: 0.6rem; margin-top: 1.1rem; }
        .btn-primary:disabled { opacity: 0.5; }
        .btn-primary:hover, .btn-primary:focus { background: #a31515 !important; }
        .link { color: #d32f2f; text-decoration: none; font-size: 0.98rem; font-weight: 500; }
        .link:hover { text-decoration: underline; color: #a31515; }
        .role-specific-field { display: none; }
        .alert { font-size: 0.98rem; padding: 0.7rem 1rem; margin-bottom: 1rem; border-radius: 0.4rem;}
        .form-title { font-size:2.1rem; font-weight:700; color:#d32f2f; text-align:center; margin-bottom:0.3rem; letter-spacing:0.01em;}
        .form-subtitle { font-size:1.08rem; color:#888; text-align:center; margin-bottom:2.2rem;}
        @media (max-width: 992px) {
            .split { flex-direction: column; height: auto; overflow-y: auto; }
            .left { padding: 3rem 1rem; height: auto; min-height: 250px; border-right: none; border-bottom: 1.5px solid #f0eaea;}
            .right { padding: 2rem 1rem; height: auto; }
            .register-form { max-width: 100%; }
            .form-title { font-size:1.5rem; }
        }
        @media (max-width: 576px) {
            .form-title { font-size: 1.2rem; }
            .form-subtitle { font-size: 0.9rem; }
            .left { min-height: 200px; }
            .step-btn { font-size:0.98rem; padding:0.7rem 0;}
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
        <div class="register-form">
            <div class="form-title">Create Account</div>
            <div class="form-subtitle">Register as a Student or Parent to access the GBU Attendance Portal</div>
            <div class="progress-bar-container">
                <div class="progress-bar" id="progressBar" style="width:33%"></div>
            </div>
            <div class="stepper-nav mb-3">
                <button type="button" class="step-btn active" data-step="0">Personal</button>
                <button type="button" class="step-btn" data-step="1">Academic</button>
                <button type="button" class="step-btn" data-step="2">Link</button>
            </div>
            <?php if (!empty($msg)) echo $msg; ?>
            <form method="post" action="register.php" id="regForm" autocomplete="off">
                <!-- Step 1: Personal Details -->
                <div class="stepper-section active" id="step-0">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required minlength="6">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                    <label for="role" class="form-label">Select Role</label>
                    <select class="form-select" id="role" name="role" required onchange="showHideFields(); validateStep(0);">
                        <option value="">-- Select Your Role --</option>
                        <option value="student" <?= (($_POST['role'] ?? '') == 'student') ? 'selected' : '' ?>>Student</option>
                        <option value="parent" <?= (($_POST['role'] ?? '') == 'parent') ? 'selected' : '' ?>>Parent</option>
                    </select>
                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-primary px-5 py-3" id="nextBtn0" onclick="goStep(1)" disabled>Next &rarr;</button>
                    </div>
                </div>
                <!-- Step 2: Academic Details -->
                <div class="stepper-section" id="step-1">
                    <div id="academicFields" class="role-specific-field">
                        <label for="department_id" class="form-label">Department</label>
                        <select class="form-select" id="department_id" name="department_id" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach($departments as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="program_id" class="form-label">Course / Program</label>
                        <select class="form-select" id="program_id" name="program_id" required disabled>
                            <option value="">-- Select Course --</option>
                        </select>
                        <label for="year" class="form-label">Year</label>
                        <select class="form-select" id="year" name="year" required disabled>
                            <option value="">-- Select Year --</option>
                        </select>
                        <label for="section" class="form-label">Section</label>
                        <select class="form-select" id="section" name="section" required disabled>
                            <option value="">-- Select Section --</option>
                        </select>
                        <label for="roll" class="form-label">Roll Number</label>
                        <input type="text" class="form-control" id="roll" name="roll" required>
                    </div>
                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-primary px-5 py-3" id="nextBtn1" onclick="goStep(2)" disabled>Next &rarr;</button>
                    </div>
                </div>
                <!-- Step 3: Parent/Student Link -->
                <div class="stepper-section" id="step-2">
                    <div id="studentFields" class="role-specific-field mb-2">
                        <label for="parent_email" class="form-label">Parent's Email Address</label>
                        <input type="email" class="form-control" id="parent_email" name="parent_email" value="<?= htmlspecialchars($_POST['parent_email'] ?? '') ?>">
                        <small class="text-muted">Enter email parent used/will use to register.</small>
                    </div>
                    <div id="parentFields" class="role-specific-field mb-2">
                        <label for="student_email" class="form-label">Your Child's (Student) Email Address</label>
                        <input type="email" class="form-control" id="student_email" name="student_email" value="<?= htmlspecialchars($_POST['student_email'] ?? '') ?>">
                        <small class="text-muted">Enter email child used/will use to register.</small>
                    </div>
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary px-5 py-3 w-100" id="submitBtn" disabled>Register</button>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <span class="text-muted">Already have an account?</span> <a href="login.php" class="link">Log In</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
function showHideFields() {
    const role = document.getElementById('role').value;
    document.getElementById('studentFields').style.display = (role === 'student') ? 'block' : 'none';
    document.getElementById('parentFields').style.display = (role === 'parent') ? 'block' : 'none';
    document.getElementById('academicFields').style.display = (role === 'student') ? 'block' : 'none';
    if(role === 'parent') {
        goStep(2);
    }
    validateStep(0); validateStep(1); validateStep(2);
}
function goStep(idx) {
    document.querySelectorAll('.stepper-section').forEach((sec, i) => sec.classList.toggle('active', i === idx));
    document.querySelectorAll('.step-btn').forEach((b, i) => b.classList.toggle('active', i === idx));
    document.getElementById('progressBar').style.width = (33 + 33*idx) + '%';
}
function validateStep(step) {
    if (step === 0) {
        const u = document.getElementById('username').value.trim();
        const e = document.getElementById('email').value.trim();
        const p = document.getElementById('password').value;
        const cp = document.getElementById('confirm_password').value;
        const r = document.getElementById('role').value;
        document.getElementById('nextBtn0').disabled = !(u && e && p && cp && p.length >= 6 && p === cp && r);
    }
    if (step === 1) {
        const r = document.getElementById('role').value;
        let ok = !!r;
        if (r === 'student') {
            ok = ok &&
                document.getElementById('department_id').value &&
                document.getElementById('program_id').value &&
                document.getElementById('year').value &&
                document.getElementById('section').value &&
                document.getElementById('roll').value.trim();
        }
        document.getElementById('nextBtn1').disabled = !ok;
    }
    if (step === 2) {
        const r = document.getElementById('role').value;
        let ok = false;
        if (r === 'student') ok = !!document.getElementById('parent_email').value.trim();
        if (r === 'parent') ok = !!document.getElementById('student_email').value.trim();
        document.getElementById('submitBtn').disabled = !ok;
    }
}
// Dependent dropdowns (AJAX)
$(function(){
    $('#department_id').on('change', function(){
        let dept_id = $(this).val();
        $('#program_id').prop('disabled', true).html('<option value="">-- Select Course --</option>');
        $('#year').prop('disabled', true).html('<option value="">-- Select Year --</option>');
        $('#section').prop('disabled', true).html('<option value="">-- Select Section --</option>');
        if(dept_id){
            $.get('get_programs.php', {department_id: dept_id}, function(data){
                $('#program_id').prop('disabled', false).html(data);
            });
        }
        validateStep(1);
    });
    $('#program_id').on('change', function(){
        let prog_id = $(this).val();
        $('#year').prop('disabled', true).html('<option value="">-- Select Year --</option>');
        $('#section').prop('disabled', true).html('<option value="">-- Select Section --</option>');
        if(prog_id){
            $.get('get_years.php', {program_id: prog_id}, function(data){
                $('#year').prop('disabled', false).html(data);
            });
        }
        validateStep(1);
    });
    $('#year').on('change', function(){
        let prog_id = $('#program_id').val();
        let year = $(this).val();
        $('#section').prop('disabled', true).html('<option value="">-- Select Section --</option>');
        if(prog_id && year){
            $.get('get_sections.php', {program_id: prog_id, year: year}, function(data){
                $('#section').prop('disabled', false).html(data);
            });
        }
        validateStep(1);
    });
    $('#section, #roll').on('input change', function(){ validateStep(1); });
});
document.addEventListener('DOMContentLoaded', function() {
    showHideFields();
    validateStep(0); validateStep(1); validateStep(2);
    document.getElementById('username').addEventListener('input', ()=>validateStep(0));
    document.getElementById('email').addEventListener('input', ()=>validateStep(0));
    document.getElementById('password').addEventListener('input', ()=>validateStep(0));
    document.getElementById('confirm_password').addEventListener('input', ()=>validateStep(0));
    document.getElementById('role').addEventListener('change', ()=>{ showHideFields(); validateStep(0); validateStep(1); });
    document.getElementById('parent_email').addEventListener('input', ()=>validateStep(2));
    document.getElementById('student_email').addEventListener('input', ()=>validateStep(2));
    document.querySelectorAll('.step-btn').forEach((btn, idx) =>
        btn.addEventListener('click', ()=>goStep(idx))
    );
});
</script>
</body>
</html>

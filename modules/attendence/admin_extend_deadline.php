<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

require '../../config/db.php';

$fetch_error = null;
$departments = $programs = $sections = $subjects = $teachers = [];
$flash_message = $_SESSION['flash_message'] ?? null; unset($_SESSION['flash_message']);

// Fetch dropdown data
try {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id, p.department_id FROM sections sec JOIN programs p ON sec.program_id = p.id ORDER BY p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $teachers = $pdo->query("SELECT id, username FROM users WHERE role='teacher' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $fetch_error = "Error fetching assignments: " . htmlspecialchars($e->getMessage());
    error_log("Admin Extension Form - Fetch Assignments Error: " . $e->getMessage());
}

// Handle POST for extending deadline
$flash_message = $_SESSION['flash_message'] ?? null; unset($_SESSION['flash_message']);
$fetch_error = null;

// Handle POST for extending deadline (only if on extend tab)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['extend_deadline'])
    && (isset($_GET['tab']) && $_GET['tab'] === 'extend')
) {
    $teacher_id = $_POST['teacher_id'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $section_id = $_POST['section_id'] ?? '';
    $date = $_POST['date'] ?? '';

    if ($teacher_id && $subject_id && $section_id && $date) {
    try {
        $stmt_assign = $pdo->prepare("SELECT id FROM faculty_assignments WHERE teacher_user_id=? AND subject_id=? AND section_id=? LIMIT 1");
        $stmt_assign->execute([$teacher_id, $subject_id, $section_id]);
        $faculty_assignment_id = $stmt_assign->fetchColumn();

        if (!$faculty_assignment_id) {
            $fetch_error = "No faculty assignment found for the selected teacher, subject, and section.";
        } else {
            $stmt_check = $pdo->prepare("SELECT id FROM attendance_deadline_extensions WHERE faculty_assignment_id=? AND allowed_date=?");
            $stmt_check->execute([$faculty_assignment_id, $date]);
            $exists = $stmt_check->fetchColumn();

            $extended_until = date('Y-m-d 23:59:59');

            if ($exists) {
                $stmt_update = $pdo->prepare("UPDATE attendance_deadline_extensions SET extended_until=?, granted_by_admin_id=? WHERE id=?");
                $stmt_update->execute([$extended_until, $_SESSION['user_id'], $exists]);
            } else {
                $stmt_insert = $pdo->prepare("INSERT INTO attendance_deadline_extensions (faculty_assignment_id, allowed_date, extended_until, granted_by_admin_id) VALUES (?, ?, ?, ?)");
                $stmt_insert->execute([$faculty_assignment_id, $date, $extended_until, $_SESSION['user_id']]);
            }

            $_SESSION['flash_message'] = [
                'type' => 'success',
                'text' => 'Attendance marking deadline has been extended for the selected subject and teacher.'
            ];
        }
    } catch (PDOException $e) {
        $fetch_error = "Error extending deadline: " . htmlspecialchars($e->getMessage());
    }
} else {
    $fetch_error = "All fields are required to extend deadline.";
}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Extend Attendance Deadline</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background-color:rgb(228, 205, 157); }
        .main-content { max-width: 1000px; margin: 2rem auto; padding: 2rem; }
        .card { border-radius: 12px; box-shadow: 0 0 10px #eee; }
        .card-header { background: #fff3f3; color:rgb(8, 69, 252); font-weight: 600; font-size: 1.15rem; border-bottom: 1px solid #ffdede; }
        .form-label { font-weight: 500; }
        .btn-gbu-red { background-color:rgb(4, 108, 254); border-color:rgb(42, 0, 251); color: #fff; }
        .btn-gbu-red:hover { background-color:rgb(0, 4, 255); border-color:rgb(22, 1, 255); }
    </style>
</head>
<body>
<div class="main-content">
    <?php if (isset($_GET['tab']) && $_GET['tab'] === 'extend'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header">Extend Attendance Deadline</div>
        <div class="card-body">
            <?php if ($flash_message): ?>
    <div class="alert alert-<?= htmlspecialchars($flash_message['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash_message['text']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash_message']); ?>
<?php endif; ?>

            <?php if ($fetch_error): ?>
                <div class="alert alert-danger"><?= $fetch_error ?></div>
            <?php endif; ?>
            <form method="post" action="attendence.php?tab=extend" class="row g-3">
                <input type="hidden" name="extend_deadline" value="1">
                <div class="col-md-6">
                    <label class="form-label" for="teacher_id">Teacher</label>
                    <select class="form-select" name="teacher_id" id="teacher_id" required>
                        <option value="">Select Teacher</option>
                        <?php foreach($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="subject_id">Subject</label>
                    <select class="form-select" name="subject_id" id="subject_id" required>
                        <option value="">Select Subject</option>
                        <?php foreach($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="section_id">Section</label>
                    <select class="form-select" name="section_id" id="section_id" required>
                        <option value="">Select Section</option>
                        <?php foreach($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>">
                                <?= htmlspecialchars($sec['program_name']) ?> Yr<?= $sec['year'] ?> Sec<?= htmlspecialchars($sec['section_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="date">Date (to allow marking):</label>
                    <input type="date" class="form-control" name="date" id="date" required min="2020-01-01" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-gbu-red px-4">Extend Deadline</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) { header('Location: ../auth/login.php'); exit; }
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$theme = $_SESSION['theme'] ?? 'light';
require '../../config/db.php';
include '../sidebar.php';

$page_title = "Attendance";
$error_msg = null;
$flash_message = $_SESSION['flash_message'] ?? null; unset($_SESSION['flash_message']);
$query_error = null;
$departments = $programs = $sections = $subjects = [];
$assigned_subjects = [];
$assigned_sections = [];
$hod_department_id = null;
$student_section_id = null;
$parent_child_id = null;

try {
    if ($role === 'admin') {
        $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $programs = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id, p.department_id FROM sections sec JOIN programs p ON sec.program_id = p.id ORDER BY p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
        $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'hod') {
        $stmt_hod_dept = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND role = 'hod'");
        $stmt_hod_dept->execute([$user_id]); $hod_department_id = $stmt_hod_dept->fetchColumn();
        if (!$hod_department_id) throw new Exception("HOD not assigned to a department.");
        $departments = $pdo->query("SELECT id, name FROM departments WHERE id = $hod_department_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $programs = $pdo->query("SELECT id, name, department_id FROM programs WHERE department_id = $hod_department_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id FROM sections sec JOIN programs p ON sec.program_id = p.id WHERE p.department_id = $hod_department_id ORDER BY p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
        $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'teacher') {
        $stmt_assigned = $pdo->prepare("SELECT DISTINCT s.id as subject_id, s.name as subject_name, sec.id as section_id, sec.year as section_year, sec.section_name, p.name as program_name FROM faculty_assignments fa JOIN subjects s ON fa.subject_id = s.id JOIN sections sec ON fa.section_id = sec.id JOIN programs p ON sec.program_id = p.id WHERE fa.teacher_user_id = ? ORDER BY p.name, sec.year, sec.section_name, s.name");
        $stmt_assigned->execute([$user_id]);
        $assignments = $stmt_assigned->fetchAll(PDO::FETCH_ASSOC);
        foreach($assignments as $assign) {
            $sec_display = "Yr {$assign['section_year']} Sec {$assign['section_name']} ({$assign['program_name']})";
            if (!isset($assigned_sections[$assign['section_id']])) $assigned_sections[$assign['section_id']] = $sec_display;
            $assigned_subjects[$assign['section_id']][$assign['subject_id']] = $assign['subject_name'];
        }
        $sections = $assigned_sections;
        $subjects_query = $pdo->prepare("SELECT DISTINCT s.id, s.name FROM subjects s JOIN faculty_assignments fa ON s.id=fa.subject_id WHERE fa.teacher_user_id = ? ORDER BY s.name");
        $subjects_query->execute([$user_id]);
        $subjects = $subjects_query->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
    } elseif ($role === 'student') {
        $stmt_student_sec = $pdo->prepare("SELECT section_id FROM users WHERE id = ?");
        $stmt_student_sec->execute([$user_id]);
        $student_section_id = $stmt_student_sec->fetchColumn();
    } elseif ($role === 'parent') {
        $stmt_child = $pdo->prepare("SELECT id, section_id FROM users WHERE parent_id = ? AND role = 'student' LIMIT 1");
        $stmt_child->execute([$user_id]);
        $child_info = $stmt_child->fetch(PDO::FETCH_ASSOC);
        if ($child_info) { $parent_child_id = $child_info['id']; $student_section_id = $child_info['section_id']; }
        else { $error_msg = "No child linked."; }
    }
} catch (PDOException $e) { $error_msg = "DB Error (Initial): " . htmlspecialchars($e->getMessage()); }
catch (Exception $e) { $error_msg = "Error: " . htmlspecialchars($e->getMessage()); }

$action = 'view';
if ($role === 'teacher') {
    $action = $_GET['action'] ?? 'mark';
    if (!in_array($action, ['mark', 'view', 'upload'])) $action = 'mark';
}
$records = []; $students_for_marking = [];
$f_dept = $f_prog = $f_sec = $f_subj = $f_date_from = $f_date_to = $f_status = '';
if ($role === 'admin' || $role === 'hod' || ($role === 'teacher' && $action === 'view')) {
    $f_dept = $_GET['filter_dept'] ?? '';
    $f_prog = $_GET['filter_prog'] ?? '';
    $f_sec = $_GET['filter_sec'] ?? '';
    $f_subj = $_GET['filter_subj'] ?? '';
    $f_date_from = $_GET['filter_date_from'] ?? '';
    $f_date_to = $_GET['filter_date_to'] ?? '';
    $f_status = $_GET['filter_status'] ?? '';
}
if ($role === 'student' || $role === 'parent') {
    $f_subj = $_GET['filter_subj'] ?? '';
    $f_date_from = $_GET['filter_date_from'] ?? '';
    $f_date_to = $_GET['filter_date_to'] ?? '';
    $f_status = $_GET['filter_status'] ?? '';
}

// Teacher: Mark Attendance POST (CHANGED: use selected date)
if ($role === 'teacher' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $section_id = $_POST['section_id'];
    $subject_id = $_POST['subject_id'];
    $attendance_data = $_POST['attendance'] ?? [];
    $today = $_POST['attendance_date'] ?? date('Y-m-d'); // CHANGED LINE
    $can_mark_today = (date('H') < 24);

    if ($can_mark_today) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE section_id = ? AND role = 'student'");
        $stmt->execute([$section_id]);
        $all_students = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $present_count = 0;
        foreach ($all_students as $student_id) {
            $status = isset($attendance_data[$student_id]) && $attendance_data[$student_id] === 'present' ? 'present' : 'absent';
            if ($status === 'present') $present_count++;
            $stmt_check = $pdo->prepare("SELECT id FROM attendance WHERE student_id=? AND subject_id=? AND section_id=? AND date=?");
            $stmt_check->execute([$student_id, $subject_id, $section_id, $today]);
            $existing_id = $stmt_check->fetchColumn();
            if ($existing_id) {
                $stmt_update = $pdo->prepare("UPDATE attendance SET status=?, marked_by=?, updated_at=NOW() WHERE id=?");
                $stmt_update->execute([$status, $user_id, $existing_id]);
            } else {
                $stmt_insert = $pdo->prepare("INSERT INTO attendance (student_id, subject_id, section_id, date, status, marked_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt_insert->execute([$student_id, $subject_id, $section_id, $today, $status, $user_id]);
            }
        }
        $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Attendance saved! $present_count students present."];
    } else {
        $_SESSION['flash_message'] = ['type' => 'warning', 'text' => 'Attendance marking for today is closed after midnight.'];
    }
    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "?action=mark&section_id=$section_id&subject_id=$subject_id");
    exit;
}

// Teacher: Bulk Upload POST (unchanged)
    // ... your unchanged bulk upload code ...
    // (keep as in your original file)
    if ($role === 'teacher' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    $section_id = $_POST['section_id'];
    $subject_id = $_POST['subject_id'];
    $today = date('Y-m-d');
    $can_upload_today = (date('H') < 24);

    if (!$can_upload_today) {
        $_SESSION['flash_message'] = ['type' => 'warning', 'text' => 'Bulk upload for today is closed after midnight.'];
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'File upload error or no file selected.'];
    } else {
        $file_path = $_FILES['csv_file']['tmp_name'];
        $file = fopen($file_path, 'r');
        $pdo->beginTransaction();
        $processed = 0; $inserted = 0; $updated = 0; $errors = 0; $line = 0;
        try {
            $stmt_section_students = $pdo->prepare("SELECT id FROM users WHERE section_id = ? AND role = 'student'");
            $stmt_section_students->execute([$section_id]);
            $student_map = [];
            while($student = $stmt_section_students->fetch(PDO::FETCH_ASSOC)){
                $key = trim($student['id']);
                if(!empty($key)) $student_map[$key] = $student['id'];
            }
            if (empty($student_map)) throw new Exception("Could not map students for section {$section_id}.");

            $stmt_check = $pdo->prepare("SELECT id FROM attendance WHERE student_id = ? AND subject_id = ? AND section_id = ? AND date = ?");
            $stmt_update = $pdo->prepare("UPDATE attendance SET status = ?, marked_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO attendance (student_id, subject_id, section_id, date, status, marked_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");

            while (($row = fgetcsv($file)) !== false) {
                $line++; if ($line == 1) continue; // Skip header
                if (count($row) < 2) { $errors++; continue; }
                $id = trim($row[0]);
                $status_raw = trim(strtolower($row[1]));
                $status = ($status_raw === 'present' || $status_raw === 'p') ? 'present' : (($status_raw === 'absent' || $status_raw === 'a') ? 'absent' : null);
                if ($status === null) { $errors++; continue; }
                if (!isset($student_map[$id])) { $errors++; continue; }
                $student_id = $student_map[$id];

                $stmt_check->execute([$student_id, $subject_id, $section_id, $today]);
                $existing_id = $stmt_check->fetchColumn();
                if ($existing_id) {
                    if($stmt_update->execute([$status, $user_id, $existing_id])) { $updated++; }
                } else {
                    if ($stmt_insert->execute([$student_id, $subject_id, $section_id, $today, $status, $user_id])) { $inserted++; }
                }
                $processed++;
            }
            fclose($file); $pdo->commit();
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Upload finished. Processed: {$processed}, Inserted: {$inserted}, Updated: {$updated}. Errors: {$errors}."];
        } catch (Exception $e) {
            $pdo->rollBack();
            if(isset($file) && is_resource($file)) fclose($file);
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Error: ' . $e->getMessage()];
        }
    }
    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']) . "?action=upload");
    exit;
}

// Fetch students for teacher mark tab (unchanged)
if ($role === 'teacher' && $action === 'mark') {
    $mark_section_id_get = filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT);
    if ($mark_section_id_get && isset($assigned_sections[$mark_section_id_get])) {
        $stmt_mark_students = $pdo->prepare("SELECT id, username FROM users WHERE section_id = ? AND role = 'student' ORDER BY id ASC, username ASC");
        $stmt_mark_students->execute([$mark_section_id_get]);
        $students_for_marking = $stmt_mark_students->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ----------- CHANGED: Fetch extended dates for teacher -----------
// ----------- CHANGED: Fetch extended dates for teacher -----------
$extended_dates = [];
if ($role === 'teacher' && $action === 'mark' && isset($_GET['section_id'], $_GET['subject_id'])) {
    // 1. Find faculty_assignment_id for this teacher/subject/section
    $stmt_assign = $pdo->prepare("SELECT id FROM faculty_assignments WHERE teacher_user_id=? AND subject_id=? AND section_id=? LIMIT 1");
    $stmt_assign->execute([$user_id, $_GET['subject_id'], $_GET['section_id']]);
    $faculty_assignment_id = $stmt_assign->fetchColumn();

    // 2. Get all allowed dates for this assignment where extension is still valid
    if ($faculty_assignment_id) {
        $stmt_ext = $pdo->prepare("SELECT allowed_date FROM attendance_deadline_extensions WHERE faculty_assignment_id = ? AND extended_until >= NOW()");
        $stmt_ext->execute([$faculty_assignment_id]);
        $extended_dates = $stmt_ext->fetchAll(PDO::FETCH_COLUMN);
    }
}


// Fetch attendance records for all roles (original code, unchanged)
try {
    if ($role === 'admin' && (!isset($_GET['tab']) || $_GET['tab'] == 'view')) {
        $sql = "SELECT a.date, stud.id AS student_id, stud.username as student_name, subj.name as subject_name, p.name as program_name, sec.year as section_year, sec.section_name, a.status, marker.username as marked_by_name
                FROM attendance a
                JOIN users stud ON a.student_id = stud.id
                JOIN subjects subj ON a.subject_id = subj.id
                JOIN sections sec ON a.section_id = sec.id
                JOIN programs p ON sec.program_id = p.id
                JOIN departments d ON p.department_id = d.id
                LEFT JOIN users marker ON a.marked_by = marker.id
                WHERE 1=1";
        $params = [];
        if (!empty($f_dept)) { $sql .= " AND d.id = ?"; $params[] = $f_dept; }
        if (!empty($f_prog)) { $sql .= " AND p.id = ?"; $params[] = $f_prog; }
        if (!empty($f_sec)) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
        if (!empty($f_subj)) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        if (!empty($f_date_from)) { $sql .= " AND a.date >= ?"; $params[] = $f_date_from; }
        if (!empty($f_date_to)) { $sql .= " AND a.date <= ?"; $params[] = $f_date_to; }
        if (!empty($f_status)) { $sql .= " AND a.status = ?"; $params[] = $f_status; }
        $sql .= " ORDER BY a.date DESC, p.name, sec.year DESC, sec.section_name, stud.id, stud.username LIMIT 200";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'hod') {
        $sql = "SELECT a.date, stud.id AS student_id, stud.username as student_name, subj.name as subject_name, p.name as program_name, sec.year as section_year, sec.section_name, a.status, marker.username as marked_by_name
                FROM attendance a
                JOIN users stud ON a.student_id = stud.id
                JOIN subjects subj ON a.subject_id = subj.id
                JOIN sections sec ON a.section_id = sec.id
                JOIN programs p ON sec.program_id = p.id
                JOIN departments d ON p.department_id = d.id
                LEFT JOIN users marker ON a.marked_by = marker.id
                WHERE p.department_id = ?";
        $params = [$hod_department_id];
        if (!empty($f_prog)) { $sql .= " AND p.id = ?"; $params[] = $f_prog; }
        if (!empty($f_sec)) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
        if (!empty($f_subj)) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        if (!empty($f_date_from)) { $sql .= " AND a.date >= ?"; $params[] = $f_date_from; }
        if (!empty($f_date_to)) { $sql .= " AND a.date <= ?"; $params[] = $f_date_to; }
        if (!empty($f_status)) { $sql .= " AND a.status = ?"; $params[] = $f_status; }
        $sql .= " ORDER BY a.date DESC, p.name, sec.year DESC, sec.section_name, stud.id, stud.username LIMIT 200";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'teacher' && $action === 'view') {
        $sql = "SELECT a.date, stud.id AS student_id, stud.username as student_name, subj.name as subject_name, p.name as program_name, sec.year as section_year, sec.section_name, a.status, marker.username as marked_by_name
                FROM attendance a
                JOIN users stud ON a.student_id = stud.id
                JOIN subjects subj ON a.subject_id = subj.id
                JOIN sections sec ON a.section_id = sec.id
                JOIN programs p ON sec.program_id = p.id
                LEFT JOIN users marker ON a.marked_by = marker.id
                WHERE a.marked_by = ?";
        $params = [$user_id];
        if (!empty($f_sec)) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
        if (!empty($f_subj)) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        if (!empty($f_date_from)) { $sql .= " AND a.date >= ?"; $params[] = $f_date_from; }
        if (!empty($f_date_to)) { $sql .= " AND a.date <= ?"; $params[] = $f_date_to; }
        if (!empty($f_status)) { $sql .= " AND a.status = ?"; $params[] = $f_status; }
        $sql .= " ORDER BY a.date DESC, p.name, sec.year DESC, sec.section_name, stud.id, stud.username LIMIT 200";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'student') {
        $sql = "SELECT a.date, subj.name as subject_name, a.status
                FROM attendance a
                JOIN subjects subj ON a.subject_id = subj.id
                WHERE a.student_id = ?";
        $params = [$user_id];
        if (!empty($f_subj)) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        if (!empty($f_date_from)) { $sql .= " AND a.date >= ?"; $params[] = $f_date_from; }
        if (!empty($f_date_to)) { $sql .= " AND a.date <= ?"; $params[] = $f_date_to; }
        if (!empty($f_status)) { $sql .= " AND a.status = ?"; $params[] = $f_status; }
        $sql .= " ORDER BY a.date DESC, subj.name LIMIT 200";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'parent' && $parent_child_id) {
        $sql = "SELECT a.date, subj.name as subject_name, a.status
                FROM attendance a
                JOIN subjects subj ON a.subject_id = subj.id
                WHERE a.student_id = ?";
        $params = [$parent_child_id];
        if (!empty($f_subj)) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        if (!empty($f_date_from)) { $sql .= " AND a.date >= ?"; $params[] = $f_date_from; }
        if (!empty($f_date_to)) { $sql .= " AND a.date <= ?"; $params[] = $f_date_to; }
        if (!empty($f_status)) { $sql .= " AND a.status = ?"; $params[] = $f_status; }
        $sql .= " ORDER BY a.date DESC, subj.name LIMIT 200";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $query_error = "Error fetching records: " . htmlspecialchars($e->getMessage()); }
catch (Exception $e) { $query_error = "Error: " . htmlspecialchars($e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"> <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- ... your styles and scripts ... -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .main-content { margin-left: 230px; padding: 2rem; min-height: 100vh; }
        .card-header { background-color: #ffeaea; color: #d32f2f; font-weight: bold; }
        .btn-gbu-red { background-color: #d32f2f; border-color: #d32f2f; color: #fff; }
        .btn-gbu-red:hover { background-color: #b71c1c; border-color: #a31515; }
        .btn-gbu-secondary { background-color: #6c757d; border-color: #6c757d; color: #fff; }
        .btn-gbu-secondary:hover { background-color: #5a6268; border-color: #545b62; }
        .table th { background-color: #f1f1f1; }
        .table td, .table th { vertical-align: middle; font-size: 0.9rem; padding: 0.6rem 0.75rem; }
        .badge-present { background-color: #198754; color: white; }
        .badge-absent { background-color: #dc3545; color: white; }
        .form-label { margin-bottom: 0.2rem; font-weight: 500; }
        .form-select-sm, .form-control-sm { font-size: 0.875rem; }
        .attendance-table th:last-child, .attendance-table td:last-child { text-align: center; }
        .attendance-table .form-check-input { cursor: pointer; width: 1.2em; height: 1.2em; margin-top: 0.1em;}
        .attendance-table .form-check-label { padding-left: 0.5em; }
        .present-radio:checked { background-color: #198754; border-color: #198754; }
        .absent-radio:checked { background-color: #dc3545; border-color: #dc3545; }
        @media (max-width: 767px) { .main-content { margin-left: 0; padding: 1rem; } }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">
<div class="main-content">
    <h2 class="mb-4" style="color:#d32f2f;font-weight:700;"><?= htmlspecialchars($page_title) ?></h2>
    <?php if ($flash_message): ?> 
      <div class="alert alert-<?= htmlspecialchars($flash_message['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash_message['text']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if ($error_msg): ?> <div class="alert alert-danger"><?= $error_msg ?></div> <?php endif; ?>
    <?php if ($query_error): ?> <div class="alert alert-warning"><?= $query_error ?></div> <?php endif; ?>

    <?php if ($role === 'admin'): ?>
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= (!isset($_GET['tab']) || $_GET['tab'] == 'view') ? 'active' : '' ?>" href="?tab=view">View Records</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= (isset($_GET['tab']) && $_GET['tab'] == 'extend') ? 'active' : '' ?>" href="?tab=extend">Extend Deadline</a>
            </li>
        </ul>
        <?php if (!isset($_GET['tab']) || $_GET['tab'] == 'view'): ?>
            <!-- View Records Tab for Dean -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">View Attendance Records</div>
                <div class="card-body">
                    <!-- Filter form for Dean -->
                    <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="row g-3 align-items-end bg-light p-3 rounded border mb-4">
                        <input type="hidden" name="tab" value="view">
                        <div class="col-md-2">
                            <label class="form-label">Department</label>
                            <select class="form-select form-select-sm" name="filter_dept">
                                <option value="">All</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= ($f_dept == $dept['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Program</label>
                            <select class="form-select form-select-sm" name="filter_prog">
                                <option value="">All</option>
                                <?php foreach($programs as $prog): ?>
                                    <option value="<?= $prog['id'] ?>" <?= ($f_prog == $prog['id']) ? 'selected' : '' ?>><?= htmlspecialchars($prog['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Section</label>
                            <select class="form-select form-select-sm" name="filter_sec">
                                <option value="">All</option>
                                <?php foreach($sections as $sec): ?>
                                    <option value="<?= $sec['id'] ?>" <?= ($f_sec == $sec['id']) ? 'selected' : '' ?>>Yr <?= htmlspecialchars($sec['year']) ?> Sec <?= htmlspecialchars($sec['section_name']) ?> (<?= htmlspecialchars($sec['program_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Subject</label>
                            <select class="form-select form-select-sm" name="filter_subj">
                                <option value="">All</option>
                                <?php foreach($subjects as $subj): ?>
                                    <option value="<?= $subj['id'] ?>" <?= ($f_subj == $subj['id']) ? 'selected' : '' ?>><?= htmlspecialchars($subj['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select form-select-sm" name="filter_status">
                                <option value="">All</option>
                                <option value="present" <?= ($f_status == 'present') ? 'selected' : '' ?>>Present</option>
                                <option value="absent" <?= ($f_status == 'absent') ? 'selected' : '' ?>>Absent</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control form-control-sm" name="filter_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control form-control-sm" name="filter_date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-secondary btn-sm w-100 mt-3">Filter</button>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?tab=view" class="btn btn-outline-secondary btn-sm w-100 mt-3">Reset</a>
                        </div>
                    </form>
                    <?php include '_attendance_table.php'; ?>
                </div>
            </div>
        <?php elseif ($_GET['tab'] == 'extend'): ?>
            <!-- Extend Deadline Tab for Dean -->
            <?php include 'admin_extend_deadline.php'; ?>
        <?php endif; ?>
    <?php elseif ($role === 'hod'): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header">View Attendance Records</div>
            <div class="card-body">
                <!-- Filter form for HOD -->
                <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="row g-3 align-items-end bg-light p-3 rounded border mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select class="form-select form-select-sm" name="filter_prog">
                            <option value="">All</option>
                            <?php foreach($programs as $prog): ?>
                                <option value="<?= $prog['id'] ?>" <?= ($f_prog == $prog['id']) ? 'selected' : '' ?>><?= htmlspecialchars($prog['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Section</label>
                        <select class="form-select form-select-sm" name="filter_sec">
                            <option value="">All</option>
                            <?php foreach($sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>" <?= ($f_sec == $sec['id']) ? 'selected' : '' ?>>Yr <?= htmlspecialchars($sec['year']) ?> Sec <?= htmlspecialchars($sec['section_name']) ?> (<?= htmlspecialchars($sec['program_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Subject</label>
                        <select class="form-select form-select-sm" name="filter_subj">
                            <option value="">All</option>
                            <?php foreach($subjects as $subj): ?>
                                <option value="<?= $subj['id'] ?>" <?= ($f_subj == $subj['id']) ? 'selected' : '' ?>><?= htmlspecialchars($subj['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select form-select-sm" name="filter_status">
                            <option value="">All</option>
                            <option value="present" <?= ($f_status == 'present') ? 'selected' : '' ?>>Present</option>
                            <option value="absent" <?= ($f_status == 'absent') ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control form-control-sm" name="filter_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control form-control-sm" name="filter_date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-secondary btn-sm w-100 mt-3">Filter</button>
                    </div>
                    <div class="col-md-3">
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-3">Reset</a>
                    </div>
                </form>
                <?php include '_attendance_table.php'; ?>
            </div>
        </div>
    <?php elseif ($role === 'teacher'): ?>
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?= ($action === 'mark') ? 'active' : '' ?>" href="?action=mark">Mark Attendance</a></li>
            <li class="nav-item"><a class="nav-link <?= ($action === 'view') ? 'active' : '' ?>" href="?action=view">View Attendance</a></li>
            <li class="nav-item"><a class="nav-link <?= ($action === 'upload') ? 'active' : '' ?>" href="?action=upload">Bulk Upload</a></li>
        </ul>
        <?php if ($action === 'mark'): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">Mark Attendance for Today (<?= date('Y-m-d') ?>)</div>
                <div class="card-body">
                    <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="row g-3 align-items-end mb-4">
                        <input type="hidden" name="action" value="mark">
                        <div class="col-md-4">
                            <label class="form-label">Section</label>
                            <select class="form-select form-select-sm" name="section_id" required onchange="this.form.submit()">
                                <option value="">-- Select Section --</option>
                                <?php foreach($assigned_sections as $sec_id => $sec_display): ?>
                                    <option value="<?= $sec_id ?>" <?= (isset($_GET['section_id']) && $_GET['section_id'] == $sec_id) ? 'selected' : '' ?>><?= htmlspecialchars($sec_display) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Subject</label>
                            <select class="form-select form-select-sm" name="subject_id" required <?= !isset($_GET['section_id'])||!$_GET['section_id']?'disabled':'' ?>>
                                <option value="">-- Select Subject --</option>
                                <?php
                                $sid_get = $_GET['section_id'] ?? null;
                                if ($sid_get && isset($assigned_subjects[$sid_get])):
                                    foreach($assigned_subjects[$sid_get] as $subj_id => $subj_name): ?>
                                        <option value="<?= $subj_id ?>" <?= (isset($_GET['subject_id']) && $_GET['subject_id'] == $subj_id) ? 'selected' : '' ?>><?= htmlspecialchars($subj_name) ?></option>
                                    <?php endforeach;
                                endif; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-secondary btn-sm w-100" <?= !isset($_GET['section_id'])||!$_GET['section_id']?'disabled':'' ?>>Load</button>
                        </div>
                    </form>
                    <?php $can_mark_today = (date('H') < 24); ?>
                    <?php if (!empty($students_for_marking)): ?>
                        <?php if($can_mark_today): ?>
                        <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=mark" id="markAttendanceForm">
                            <input type="hidden" name="mark_attendance" value="1">
                            <input type="hidden" name="section_id" value="<?= htmlspecialchars($_GET['section_id'] ?? '') ?>">
                            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($_GET['subject_id'] ?? '') ?>">

                            <!-- CHANGED: Date selection for extended deadlines -->
                            <?php if (!empty($extended_dates)): ?>
    <label for="attendance_date">Select Date to Mark Attendance:</label>
    <select name="attendance_date" id="attendance_date" class="form-select form-select-sm mb-2">
        <option value="<?= date('Y-m-d') ?>">Today (<?= date('Y-m-d') ?>)</option>
        <?php foreach($extended_dates as $ex_date): ?>
            <?php if ($ex_date != date('Y-m-d')): ?>
                <option value="<?= htmlspecialchars($ex_date) ?>">Extended: <?= htmlspecialchars($ex_date) ?></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>
<?php else: ?>
    <input type="hidden" name="attendance_date" value="<?= date('Y-m-d') ?>">
<?php endif; ?>

                            <!-- END CHANGED -->

                            <div class="d-flex justify-content-end mb-2 gap-2">
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="markAll('present')">Mark All Present</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="markAll('absent')">Mark All Absent</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover attendance-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Student ID</th>
                                            <th>Student Name</th>
                                            <th width="25%">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $count = 1; foreach ($students_for_marking as $student): ?>
                                            <tr>
                                                <td><?= $count++ ?></td>
                                                <td><?= htmlspecialchars($student['id'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($student['username'] ?? '') ?></td>
                                                <td>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input present-radio" type="radio" name="attendance[<?= $student['id'] ?? '' ?>]" id="present_<?= $student['id'] ?? '' ?>" value="present">
                                                        <label class="form-check-label" for="present_<?= $student['id'] ?? '' ?>">Present</label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input absent-radio" type="radio" name="attendance[<?= $student['id'] ?? '' ?>]" id="absent_<?= $student['id'] ?? '' ?>" value="absent" checked>
                                                        <label class="form-check-label" for="absent_<?= $student['id'] ?? '' ?>">Absent</label>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-center mt-3">
                                <button type="submit" class="btn btn-gbu-red btn-lg">Save Attendance</button>
                            </div>
                        </form>
                        <?php else: ?>
                            <div class="alert alert-warning text-center">Attendance marking for today is closed after midnight.</div>
                        <?php endif; ?>
                    <?php elseif (isset($_GET['section_id'], $_GET['subject_id'])): ?>
                        <div class="alert alert-info text-center">No students found or section/subject incorrect.</div>
                    <?php else: ?>
                        <div class="alert alert-secondary text-center">Select section and subject to load attendance list.</div>
                    <?php endif; ?>
                </div>
            </div>
            <script>
            function markAll(status) {
                var radios = document.querySelectorAll('#markAttendanceForm input[type="radio"][value="'+status+'"]');
                radios.forEach(function(radio) { radio.checked = true; });
            }
            </script>
        <?php elseif ($action === 'view'): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">View Attendance Records</div>
                <div class="card-body">
                    <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="row g-3 align-items-end bg-light p-3 rounded border mb-4">
                        <input type="hidden" name="action" value="view">
                        <div class="col-md-3">
                            <label class="form-label">Section</label>
                            <select class="form-select form-select-sm" name="filter_sec">
                                <option value="">All</option>
                                <?php foreach($assigned_sections as $sec_id => $sec_display): ?>
                                    <option value="<?= $sec_id ?>" <?= ($f_sec == $sec_id) ? 'selected' : '' ?>><?= htmlspecialchars($sec_display) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Subject</label>
                            <select class="form-select form-select-sm" name="filter_subj">
                                <option value="">All</option>
                                <?php foreach($subjects as $subj_id => $subj_name): ?>
                                    <option value="<?= $subj_id ?>" <?= ($f_subj == $subj_id) ? 'selected' : '' ?>><?= htmlspecialchars($subj_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control form-control-sm" name="filter_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control form-control-sm" name="filter_date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select form-select-sm" name="filter_status">
                                <option value="">All</option>
                                <option value="present" <?= ($f_status == 'present') ? 'selected' : '' ?>>Present</option>
                                <option value="absent" <?= ($f_status == 'absent') ? 'selected' : '' ?>>Absent</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-secondary btn-sm w-100 mt-3">Filter</button>
                        </div>
                        <div class="col-md-3">
                            <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=view" class="btn btn-outline-secondary btn-sm w-100 mt-3">Reset</a>
                        </div>
                    </form>
                    <?php include '_attendance_table.php'; ?>
                </div>
            </div>
        <?php elseif ($action === 'upload'): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">Bulk Upload Attendance for Today (<?= date('Y-m-d') ?>)</div>
                <div class="card-body">
                    <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=upload" enctype="multipart/form-data">
                        <input type="hidden" name="bulk_upload" value="1">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Section <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" name="section_id" required>
                                    <option value="">-- Select Section --</option>
                                    <?php foreach($assigned_sections as $sec_id => $sec_display): ?>
                                        <option value="<?= $sec_id ?>"><?= htmlspecialchars($sec_display) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" name="subject_id" required>
                                    <option value="">-- Select Subject --</option>
                                    <?php
                                    foreach($assigned_subjects as $sec_id => $subjects_arr) {
                                        foreach($subjects_arr as $subj_id => $subj_name) {
                                            echo '<option value="'.$subj_id.'">'.$subj_name.'</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">CSV File <span class="text-danger">*</span></label>
                                <input class="form-control form-control-sm" type="file" name="csv_file" accept=".csv" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-secondary small p-2 mt-3">
                                <strong>CSV Format Instructions:</strong><br>
                                - The file must be .csv<br>
                                - The first row (header) is skipped<br>
                                - <b>Column 1:</b> Student ID (must match database)<br>
                                - <b>Column 2:</b> Status (Present/Absent or P/A)<br>
                                - Example:<br>
                                <code>12345, Present</code><br>
                                <code>12346, Absent</code>
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-center mt-3">
                            <button type="submit" class="btn btn-gbu-red btn-lg">Upload Attendance</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($role === 'student' || $role === 'parent'): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><?= ($role === 'student') ? "My Attendance" : "Child's Attendance" ?></div>
            <div class="card-body">
                <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="row g-3 align-items-end bg-light p-3 rounded border mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Subject</label>
                        <select class="form-select form-select-sm" name="filter_subj">
                            <option value="">All</option>
                            <?php foreach($subjects as $subj): ?>
                                <option value="<?= $subj['id'] ?>" <?= ($f_subj == $subj['id']) ? 'selected' : '' ?>><?= htmlspecialchars($subj['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control form-control-sm" name="filter_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control form-control-sm" name="filter_date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select form-select-sm" name="filter_status">
                            <option value="">All</option>
                            <option value="present" <?= ($f_status == 'present') ? 'selected' : '' ?>>Present</option>
                            <option value="absent" <?= ($f_status == 'absent') ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-secondary btn-sm w-100 mt-3">Filter</button>
                    </div>
                    <div class="col-md-3">
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-3">Reset</a>
                    </div>
                </form>
                <?php include '_attendance_table.php'; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

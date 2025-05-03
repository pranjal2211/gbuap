<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../auth/login.php');
    exit;
}
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

require '../../config/db.php';
include '../sidebar.php';

$theme = $_SESSION['theme'] ?? 'light';
$page_title = "Generate Report";
$page_error = null;
$user_load_error = null;
$user = null;

// Fetch user
try {
    $stmt_user = $pdo->prepare("SELECT id, username, email, role, department_id FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $user_load_error = "Your user profile could not be loaded.";
        $user = null;
    }
} catch (Exception $e) {
    $page_error = "Error loading user: " . $e->getMessage();
    $user = null;
}

// --- Filters (for admin/hod/teacher) ---
$f_dept = $_GET['filter_dept'] ?? '';
$f_prog = $_GET['filter_prog'] ?? '';
$f_sec = $_GET['filter_sec'] ?? '';
$f_subj = $_GET['filter_subj'] ?? '';
$f_stud = $_GET['filter_stud'] ?? '';
$f_teach = $_GET['filter_teach'] ?? '';
$f_date_from = $_GET['filter_date_from'] ?? '';
$f_date_to = $_GET['filter_date_to'] ?? '';
$f_status = $_GET['filter_status'] ?? '';
$f_percentage = $_GET['filter_percentage'] ?? 'below75'; // Default is below75

// --- Data for filters (admin/hod/teacher) ---
$departments = $programs = $sections = $subjects = $students = $teachers = [];
if ($role === 'admin' || $role === 'hod' || $role === 'teacher') {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id FROM sections sec JOIN programs p ON sec.program_id = p.id ORDER BY p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $teachers = $pdo->query("SELECT id, username FROM users WHERE role='teacher' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
}

// --- Fetch report data for each role ---
$records = [];
if (!$page_error && !$user_load_error && $user) try {
    if ($role === 'admin') {
        $sql = "SELECT u.username as student_name, subj.name as subject_name, CONCAT(p.name,' Yr',sec.year,' Sec',sec.section_name) as class, COUNT(a.id) as total, SUM(a.status='present') as present
            FROM users u
            JOIN sections sec ON u.section_id = sec.id
            JOIN programs p ON sec.program_id = p.id
            JOIN attendance a ON a.student_id = u.id
            JOIN subjects subj ON a.subject_id = subj.id
            JOIN departments d ON p.department_id = d.id
            WHERE u.role = 'student'";
        $params = [];
        if ($f_dept) { $sql .= " AND d.id = ?"; $params[] = $f_dept; }
        if ($f_prog) { $sql .= " AND p.id = ?"; $params[] = $f_prog; }
        if ($f_sec) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
        if ($f_subj) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        $sql .= " GROUP BY u.id, subj.id";
        if ($f_percentage == 'below75') $sql .= " HAVING total > 0 AND (present/total)*100 < 75";
        elseif ($f_percentage == 'above75') $sql .= " HAVING total > 0 AND (present/total)*100 >= 75";
        else $sql .= " HAVING total > 0";
        $sql .= " ORDER BY u.username, subj.name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'hod') {
        $department_id = $user['department_id'];
        $sql = "SELECT u.username as student_name, subj.name as subject_name, CONCAT(p.name,' Yr',sec.year,' Sec',sec.section_name) as class, COUNT(a.id) as total, SUM(a.status='present') as present
            FROM users u
            JOIN sections sec ON u.section_id = sec.id
            JOIN programs p ON sec.program_id = p.id
            JOIN attendance a ON a.student_id = u.id
            JOIN subjects subj ON a.subject_id = subj.id
            WHERE u.role = 'student' AND p.department_id = ?";
        $params = [$department_id];
        if ($f_prog) { $sql .= " AND p.id = ?"; $params[] = $f_prog; }
        if ($f_sec) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
        if ($f_subj) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        $sql .= " GROUP BY u.id, subj.id";
        if ($f_percentage == 'below75') $sql .= " HAVING total > 0 AND (present/total)*100 < 75";
        elseif ($f_percentage == 'above75') $sql .= " HAVING total > 0 AND (present/total)*100 >= 75";
        else $sql .= " HAVING total > 0";
        $sql .= " ORDER BY u.username, subj.name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'teacher') {
        $stmt_assign = $pdo->prepare("SELECT subject_id, section_id FROM faculty_assignments WHERE teacher_user_id = ?");
        $stmt_assign->execute([$user_id]);
        $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);
        $subject_section = [];
        foreach ($assignments as $a) $subject_section[] = "(a.subject_id = {$a['subject_id']} AND a.section_id = {$a['section_id']})";
        if (!$subject_section) { $records = []; }
        else {
            $where = implode(' OR ', $subject_section);
            $sql = "SELECT u.username as student_name, subj.name as subject_name, CONCAT(p.name,' Yr',sec.year,' Sec',sec.section_name) as class, COUNT(a.id) as total, SUM(a.status='present') as present
                FROM users u
                JOIN attendance a ON a.student_id = u.id
                JOIN subjects subj ON a.subject_id = subj.id
                JOIN sections sec ON a.section_id = sec.id
                JOIN programs p ON sec.program_id = p.id
                WHERE u.role = 'student' AND ($where)";
            if ($f_sec) $sql .= " AND sec.id = ".intval($f_sec);
            if ($f_subj) $sql .= " AND subj.id = ".intval($f_subj);
            $sql .= " GROUP BY u.id, subj.id";
            if ($f_percentage == 'below75') $sql .= " HAVING total > 0 AND (present/total)*100 < 75";
            elseif ($f_percentage == 'above75') $sql .= " HAVING total > 0 AND (present/total)*100 >= 75";
            else $sql .= " HAVING total > 0";
            $sql .= " ORDER BY u.username, subj.name";
            $records = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($role === 'student') {
        $sql = "SELECT subj.name as subject_name, COUNT(a.id) as total, SUM(a.status='present') as present
            FROM attendance a
            JOIN subjects subj ON a.subject_id = subj.id
            WHERE a.student_id = ?
            GROUP BY a.subject_id, subj.name
            HAVING total > 0 AND (present/total)*100 < 75";
        $stmt = $pdo->prepare($sql); $stmt->execute([$user_id]); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'parent') {
        $stmt_child = $pdo->prepare("SELECT id FROM users WHERE parent_id = ? AND role = 'student' LIMIT 1");
        $stmt_child->execute([$user_id]);
        $child = $stmt_child->fetch(PDO::FETCH_ASSOC);
        if ($child) {
            $sql = "SELECT subj.name as subject_name, COUNT(a.id) as total, SUM(a.status='present') as present
                FROM attendance a
                JOIN subjects subj ON a.subject_id = subj.id
                WHERE a.student_id = ?
                GROUP BY a.subject_id, subj.name
                HAVING total > 0 AND (present/total)*100 < 75";
            $stmt = $pdo->prepare($sql); $stmt->execute([$child['id']]); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) { $page_error = "Error generating report: " . $e->getMessage(); $records = []; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Helvetica', Arial, sans-serif; }
        .main-content { margin-left: 230px; padding: 2rem; min-height: 100vh; }
        .report-card { background: #fff; border-radius: 0.8rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); padding: 2rem; margin-bottom: 2rem; }
        .section-title { font-size: 1.2rem; font-weight: 600; color: #d32f2f; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #ffeaea; }
        .profile-table td { padding: 0.5rem 0.8rem; vertical-align: middle; font-size: 0.95rem; border-top: none;}
        .profile-table tr:first-child td { border-top: 1px solid #dee2e6; }
        .profile-table td:first-child { font-weight: 600; width: 140px; color: #555; }
        .summary-item { margin-bottom: 0.6rem; font-size: 1rem; } .summary-item b { color: #333; }
        .alert-warning { background-color: #fff3e0; border-color: #ffe0b2; color: #e65100; } .alert-warning ul { margin-bottom: 0; padding-left: 1.2rem;}
        .filter-form label { font-weight: 500; font-size: 0.9rem; margin-bottom: 0.3rem; color: #495057;}
        .filter-form .form-select-sm, .filter-form .form-control-sm { font-size: 0.875rem; }
        .action-btn { font-weight: 500; padding: 0.5rem 1.25rem; font-size: 0.9rem; }
        .btn-gbu-red { background-color: #d32f2f; border-color: #d32f2f; color: #fff; }
        .btn-gbu-red:hover { background-color: #b71c1c; border-color: #a31515; }
        .btn-download { background-color: #198754; border-color: #198754; color: #fff; padding: 0.6rem 1.5rem; font-size: 0.95rem; }
        .btn-download:hover { background-color: #157347; border-color: #146c43; }
        .download-section { text-align: right; margin-bottom: 1.5rem; margin-top: 0.5rem; }
        .table-responsive { margin-top: 1rem; }
        .table thead { background-color: #e9ecef; color: #495057; }
        .table th { font-weight: 600; border-bottom-width: 2px; white-space: nowrap; font-size: 0.9rem; padding: 0.6rem 0.75rem; }
        .table td { vertical-align: middle; font-size: 0.9rem; padding: 0.5rem 0.75rem; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        .badge { font-size: 0.8rem; padding: 0.4em 0.6em; }
        @media (max-width: 767px) { .main-content { margin-left: 0; padding: 1rem; } .report-card { padding: 1.5rem; } .download-section { text-align: center;} }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">
<div class="main-content">
    <div class="report-card">
        <h2 class="text-center mb-4" style="color:#d32f2f;font-weight:700;"><?= htmlspecialchars($page_title) ?></h2>
        <?php if ($page_error): ?>
            <div class="alert alert-danger">Critical Error: <?= $page_error ?></div>
        <?php elseif ($user_load_error): ?>
            <div class="alert alert-warning"><?= $user_load_error ?></div>
        <?php elseif (!$user): ?>
            <div class="alert alert-warning">User profile could not be loaded. Please ensure you are logged in correctly.</div>
        <?php else: ?>
            <div class="mb-4">
                <div class="section-title">1. Your Profile</div>
                <table class="table profile-table mb-0" style="max-width:550px;">
                    <tbody>
                        <tr><td>Username:</td><td><?= htmlspecialchars($user['username']) ?></td></tr>
                        <tr><td>Email:</td><td><?= htmlspecialchars($user['email']) ?></td></tr>
                        <tr><td>Role:</td><td><?= ucfirst(htmlspecialchars($user['role'])) ?></td></tr>
                    </tbody>
                </table>
            </div>
            <?php if ($role === 'admin' || $role === 'hod' || $role === 'teacher'): ?>
                <div class="mb-4">
                    <div class="section-title">2. Filter Options</div>
                    <form method="get" action="generate.php" class="row g-3 filter-form">
                        <?php if ($role === 'admin'): ?>
                        <div class="col-md-3">
                            <label for="filter_dept" class="form-label">Department</label>
                            <select class="form-select form-select-sm" id="filter_dept" name="filter_dept">
                                <option value="">All</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= ($f_dept == $dept['id'])?'selected':'' ?>><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <label for="filter_prog" class="form-label">Program</label>
                            <select class="form-select form-select-sm" id="filter_prog" name="filter_prog">
                                <option value="">All</option>
                                <?php foreach($programs as $prog): ?>
                                    <option value="<?= $prog['id'] ?>" <?= ($f_prog == $prog['id'])?'selected':'' ?>><?= htmlspecialchars($prog['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filter_sec" class="form-label">Section</label>
                            <select class="form-select form-select-sm" id="filter_sec" name="filter_sec">
                                <option value="">All</option>
                                <?php foreach($sections as $sec): ?>
                                    <option value="<?= $sec['id'] ?>" <?= ($f_sec == $sec['id'])?'selected':'' ?>><?= htmlspecialchars($sec['program_name']) ?>-Yr<?= $sec['year'] ?>-Sec<?= htmlspecialchars($sec['section_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filter_subj" class="form-label">Subject</label>
                            <select class="form-select form-select-sm" id="filter_subj" name="filter_subj">
                                <option value="">All</option>
                                <?php foreach($subjects as $subj): ?>
                                    <option value="<?= $subj['id'] ?>" <?= ($f_subj == $subj['id'])?'selected':'' ?>><?= htmlspecialchars($subj['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="filter_percentage" class="form-label">Attendance %</label>
                            <select class="form-select form-select-sm" id="filter_percentage" name="filter_percentage">
                                <option value="below75" <?= ($f_percentage == 'below75')?'selected':'' ?>>< 75%</option>
                                <option value="above75" <?= ($f_percentage == 'above75')?'selected':'' ?>>≥ 75%</option>
                                <option value="all" <?= ($f_percentage == 'all')?'selected':'' ?>>All</option>
                            </select>
                        </div>
                        <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                            <a href="generate.php" class="btn btn-gbu-secondary action-btn px-4">Reset Filters</a>
                            <button type="submit" class="btn btn-gbu-red action-btn px-4">View Results</button>
                        </div>
                    </form>
                </div>
                <div class="download-section">
                    <a href="generate_pdf.php?<?= http_build_query(array_filter($_GET)) ?>" class="btn btn-download action-btn" target="_blank">
                        <i class="fas fa-file-pdf me-2"></i> Download Results (PDF)
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Attendance</th>
                                <th>%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="5" class="text-center text-muted">No students found.</td></tr>
                            <?php else: foreach($records as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['student_name']) ?></td>
                                    <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                    <td><?= htmlspecialchars($row['class']) ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['present']) ?>/<?= htmlspecialchars($row['total']) ?></td>
                                    <td class="text-center"><?= round(($row['present']/$row['total'])*100,2) ?>%</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($role === 'student'): ?>
                <div class="download-section">
                    <a href="generate_pdf.php" class="btn btn-download action-btn" target="_blank">
                        <i class="fas fa-file-pdf me-2"></i> Download Report (PDF)
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-sm">
                        <thead class="table-light">
                            <tr><th>Subject</th><th>Attendance</th><th>%</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="3" class="text-center text-muted">All your subjects are above 75% attendance.</td></tr>
                            <?php else: foreach($records as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['present']) ?>/<?= htmlspecialchars($row['total']) ?></td>
                                    <td class="text-center"><?= round(($row['present']/$row['total'])*100,2) ?>%</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($role === 'parent'): ?>
                <div class="download-section">
                    <a href="generate_pdf.php" class="btn btn-download action-btn" target="_blank">
                        <i class="fas fa-file-pdf me-2"></i> Download Report (PDF)
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-sm">
                        <thead class="table-light">
                            <tr><th>Subject</th><th>Attendance</th><th>%</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="3" class="text-center text-muted">All subjects above 75% attendance.</td></tr>
                            <?php else: foreach($records as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['present']) ?>/<?= htmlspecialchars($row['total']) ?></td>
                                    <td class="text-center"><?= round(($row['present']/$row['total'])*100,2) ?>%</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

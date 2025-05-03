<?php
// This file is included by attendence.php when role is 'admin'
// $pdo is available from the parent script

// --- Fetch Filter Options (Keep existing logic) ---
$departments = $programs = $sections = $subjects = $students = $teachers = [];
$filter_error = null;
try {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id FROM sections sec JOIN programs p ON sec.program_id = p.id ORDER BY p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $teachers = $pdo->query("SELECT id, username FROM users WHERE role='teacher' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $filter_error = "Error fetching filter options: " . htmlspecialchars($e->getMessage());
    error_log("Admin View Attendance - Filter Fetch Error: " . $e->getMessage());
}

// --- Get Filter Values from GET (Keep existing logic) ---
$f_dept = $_GET['filter_dept'] ?? '';
$f_prog = $_GET['filter_prog'] ?? '';
$f_sec = $_GET['filter_sec'] ?? '';
$f_subj = $_GET['filter_subj'] ?? '';
$f_stud = $_GET['filter_stud'] ?? '';
$f_teach = $_GET['filter_teach'] ?? '';
$f_date_from = $_GET['filter_date_from'] ?? '';
$f_date_to = $_GET['filter_date_to'] ?? '';
$f_status = $_GET['filter_status'] ?? '';

// --- Build Query (Keep existing logic) ---
$records = [];
$query_error = null;
if (!$filter_error) {
    try {
        $sql = "SELECT a.date, stud.username as student_name, subj.name as subject_name, p.name as program_name, sec.year as section_year, sec.section_name, a.status, marker.username as marked_by_name FROM attendance a JOIN users stud ON a.student_id = stud.id JOIN subjects subj ON a.subject_id = subj.id JOIN sections sec ON a.section_id = sec.id JOIN programs p ON sec.program_id = p.id JOIN departments d ON p.department_id = d.id LEFT JOIN users marker ON a.marked_by = marker.id";
        $conditions = []; $params = [];
        if ($f_dept) { $conditions[] = "d.id = ?"; $params[] = $f_dept; }
        if ($f_prog) { $conditions[] = "p.id = ?"; $params[] = $f_prog; }
        if ($f_sec) { $conditions[] = "sec.id = ?"; $params[] = $f_sec; }
        if ($f_subj) { $conditions[] = "subj.id = ?"; $params[] = $f_subj; }
        if ($f_stud) { $conditions[] = "stud.id = ?"; $params[] = $f_stud; }
        if ($f_teach) { $conditions[] = "marker.id = ?"; $params[] = $f_teach; }
        if ($f_date_from) { $conditions[] = "a.date >= ?"; $params[] = $f_date_from; }
        if ($f_date_to) { $conditions[] = "a.date <= ?"; $params[] = $f_date_to; }
        if ($f_status) { $conditions[] = "a.status = ?"; $params[] = $f_status; }
        if (!empty($conditions)) { $sql .= " WHERE " . implode(" AND ", $conditions); }
        $sql .= " ORDER BY a.date DESC, d.name, p.name, sec.year, sec.section_name, subj.name, stud.username LIMIT 200";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $query_error = "Error fetching records: " . htmlspecialchars($e->getMessage()); error_log("Admin View Attendance - Query Error: " . $e->getMessage()); }
}
?>

<?php if($filter_error): ?>
    <div class="alert alert-danger"><?= $filter_error ?></div>
<?php else: ?>
    <form class="row g-3 filter-bar mb-4" method="get" action="attendence.php">
        <?php /* *** ADD THIS HIDDEN INPUT *** */ ?>
        <input type="hidden" name="tab" value="view">
        <?php /* **************************** */ ?>

        <div class="col-md-4">
            <label for="filter_dept" class="form-label">Department</label>
            <select class="form-select form-select-sm" id="filter_dept" name="filter_dept">
                <option value="">All Departments</option>
                <?php foreach($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= ($f_dept == $dept['id'])?'selected':'' ?>><?= htmlspecialchars($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label for="filter_prog" class="form-label">Program</label>
            <select class="form-select form-select-sm" id="filter_prog" name="filter_prog">
                <option value="">All Programs</option>
                 <?php foreach($programs as $prog): ?>
                    <option value="<?= $prog['id'] ?>" data-dept="<?= $prog['department_id'] ?>" <?= ($f_prog == $prog['id'])?'selected':'' ?>><?= htmlspecialchars($prog['name']) ?></option>
                 <?php endforeach; ?>
            </select>
        </div>
         <div class="col-md-4">
            <label for="filter_sec" class="form-label">Section</label>
            <select class="form-select form-select-sm" id="filter_sec" name="filter_sec">
                <option value="">All Sections</option>
                 <?php foreach($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" data-prog="<?= $sec['program_id'] ?>" <?= ($f_sec == $sec['id'])?'selected':'' ?>>
                        <?= htmlspecialchars($sec['program_name']) ?> - Yr <?= $sec['year'] ?> - Sec <?= htmlspecialchars($sec['section_name']) ?>
                    </option>
                 <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label for="filter_subj" class="form-label">Subject</label>
            <select class="form-select form-select-sm" id="filter_subj" name="filter_subj">
                <option value="">All Subjects</option>
                <?php foreach($subjects as $subj): ?>
                    <option value="<?= $subj['id'] ?>" <?= ($f_subj == $subj['id'])?'selected':'' ?>><?= htmlspecialchars($subj['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
         <div class="col-md-4">
            <label for="filter_stud" class="form-label">Student</label>
            <select class="form-select form-select-sm" id="filter_stud" name="filter_stud">
                <option value="">All Students</option>
                 <?php foreach($students as $stud): ?>
                    <option value="<?= $stud['id'] ?>" <?= ($f_stud == $stud['id'])?'selected':'' ?>><?= htmlspecialchars($stud['username']) ?> (<?= $stud['id'] ?>)</option>
                 <?php endforeach; ?>
            </select>
        </div>
         <div class="col-md-4">
            <label for="filter_teach" class="form-label">Marked By (Teacher)</label>
            <select class="form-select form-select-sm" id="filter_teach" name="filter_teach">
                <option value="">All Teachers</option>
                 <?php foreach($teachers as $teach): ?>
                    <option value="<?= $teach['id'] ?>" <?= ($f_teach == $teach['id'])?'selected':'' ?>><?= htmlspecialchars($teach['username']) ?> (<?= $teach['id'] ?>)</option>
                 <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="filter_date_from" class="form-label">Date From</label>
            <input type="date" class="form-control form-control-sm" id="filter_date_from" name="filter_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
        </div>
        <div class="col-md-3">
            <label for="filter_date_to" class="form-label">Date To</label>
            <input type="date" class="form-control form-control-sm" id="filter_date_to" name="filter_date_to" value="<?= htmlspecialchars($f_date_to) ?>">
        </div>
        <div class="col-md-3">
            <label for="filter_status" class="form-label">Status</label>
            <select class="form-select form-select-sm" id="filter_status" name="filter_status">
                <option value="">All</option>
                <option value="present" <?= $f_status=='present'?'selected':''; ?>>Present</option>
                <option value="absent" <?= $f_status=='absent'?'selected':''; ?>>Absent</option>
            </select>
        </div>
         <div class="col-md-3 d-flex align-items-end gap-2">
            <button class="btn btn-danger btn-sm w-100" type="submit">Filter</button>
            <?php $reset_link = "attendence.php?tab=view"; // Keep reset link pointing to view tab ?>
            <a href="<?= $reset_link ?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
        </div>
    </form>

    <?php // --- Results Table (Keep existing logic) --- ?>
    <?php if($query_error): ?>
        <div class="alert alert-danger"><?= $query_error ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover table-sm">
                 <thead class="table-light">
                    <tr><th>Date</th><th>Student</th><th>Subject</th><th>Class</th><th>Status</th><th>Marked By</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="6" class="text-center text-muted fst-italic py-3">No records found matching filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $rec): ?>
                            <tr>
                                <td><?= htmlspecialchars($rec['date']) ?></td>
                                <td><?= htmlspecialchars($rec['student_name']) ?></td>
                                <td><?= htmlspecialchars($rec['subject_name']) ?></td>
                                <td><?= htmlspecialchars($rec['program_name']) ?> - Yr <?= htmlspecialchars($rec['section_year']) ?> - Sec <?= htmlspecialchars($rec['section_name']) ?></td>
                                <td><span class="badge <?= $rec['status'] === 'present' ? 'bg-success' : 'bg-danger' ?>"><?= ucfirst($rec['status']) ?></span></td>
                                <td><?= htmlspecialchars($rec['marked_by_name'] ?? 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
// This file assumes session is started and user is teacher or admin
// $user_id and $role are available from attendence.php

if (session_status() == PHP_SESSION_NONE) session_start(); // Re-check
if (!isset($user_id) || !in_array($role, ['teacher', 'admin'])) {
    echo "<div class='alert alert-danger'>Access Denied.</div>";
    exit;
}

$filter_date = $_GET['filter_date'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$filter_student = ($role === 'admin') ? ($_GET['filter_student'] ?? '') : ''; // Only admin can filter by student

// --- Define Teacher-Subject Map (for teacher's dropdown) ---
$teacher_subject_map = [
    7 => ['Digital Image Processing', 'Software Testing', 'Analysis of Design of Algorithm'],
    8 => ['Cybersecurity', 'Web Development using PHP', 'Management Information System'],
];
$teacher_allowed_subjects = ($role === 'teacher') ? ($teacher_subject_map[$user_id] ?? []) : [];
// ---------------------------------------------------------

// Get Subjects for Dropdown Filter
$subjects_for_filter = [];
try {
    if ($role === 'admin') {
        // Admin gets all subjects globally
        $stmt_subjects = $pdo->query("SELECT DISTINCT subject FROM attendance ORDER BY subject ASC");
        $subjects_for_filter = $stmt_subjects->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($role === 'teacher') {
        // Teacher gets only their assigned subjects
        $subjects_for_filter = $teacher_allowed_subjects;
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error fetching subjects for filter: " . $e->getMessage() . "</div>";
}

// Get Students for Dropdown Filter (Admin only)
$students_for_filter = [];
if ($role === 'admin') {
    try {
        $stmt_students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username ASC");
        $students_for_filter = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>Error fetching students for filter: " . $e->getMessage() . "</div>";
    }
}


// Build Query Based on Role and Filters
$records = [];
try {
    $query = "SELECT a.id as att_id, a.date, u.username as student, a.student_id, a.subject, a.status, marker.username as marked_by_user
              FROM attendance a
              JOIN users u ON a.student_id = u.id
              LEFT JOIN users marker ON a.marked_by = marker.id"; // Join to get marker username

    $params = [];
    $conditions = [];

    if ($role === 'teacher') {
        $conditions[] = "a.marked_by = ?";
        $params[] = $user_id;
    }
    // Admin sees all, no marked_by filter needed unless specified

    if ($filter_date) {
        $conditions[] = "a.date = ?";
        $params[] = $filter_date;
    }
    if ($filter_subject) {
        $conditions[] = "a.subject = ?";
        $params[] = $filter_subject;
    }
    if ($filter_status) {
        $conditions[] = "a.status = ?";
        $params[] = $filter_status;
    }
    if ($role === 'admin' && $filter_student) { // Student filter only for admin
        $conditions[] = "a.student_id = ?";
        $params[] = $filter_student;
    }


    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY a.date DESC, a.subject ASC, u.username ASC"; // Added student username sort

    $stmt_records = $pdo->prepare($query);
    $stmt_records->execute($params);
    $records = $stmt_records->fetchAll();

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error fetching attendance records: " . $e->getMessage() . "</div>";
}

?>
<!-- Filter Form -->
<form class="row filter-bar mb-3 gx-3 gy-2 align-items-end" method="get" autocomplete="off" id="filterFormView" style="width:100%;max-width:100%;">
    <input type="hidden" name="tab" value="view"> <!-- Keep tab in URL -->

    <div class="col-md-auto col-6 mb-2 flex-grow-1">
        <label for="filter_date_view" class="form-label">Date</label>
        <input type="date" class="form-control form-control-sm" id="filter_date_view" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
    </div>

    <div class="col-md-auto col-6 mb-2 flex-grow-1">
        <label for="filter_subject_view" class="form-label">Subject</label>
        <select class="form-select form-select-sm" id="filter_subject_view" name="filter_subject">
            <option value="">All Subjects</option>
            <?php foreach($subjects_for_filter as $subject): ?>
                <option value="<?= htmlspecialchars($subject) ?>" <?= ($filter_subject == $subject) ? 'selected' : '' ?>><?= htmlspecialchars($subject) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($role === 'admin'): ?>
        <div class="col-md-auto col-6 mb-2 flex-grow-1">
            <label for="filter_student_view" class="form-label">Student</label>
            <select class="form-select form-select-sm" id="filter_student_view" name="filter_student">
                <option value="">All Students</option>
                <?php foreach($students_for_filter as $student): ?>
                    <option value="<?= $student['id'] ?>" <?= ($filter_student == $student['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($student['username']) ?> (<?= $student['id'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <div class="col-md-auto col-6 mb-2 flex-grow-1">
        <label for="filter_status_view" class="form-label">Status</label>
        <select class="form-select form-select-sm" id="filter_status_view" name="filter_status">
            <option value="">All Statuses</option>
            <option value="present" <?= $filter_status=='present'?'selected':''; ?>>Present</option>
            <option value="absent" <?= $filter_status=='absent'?'selected':''; ?>>Absent</option>
        </select>
    </div>

    <div class="col-md-auto col-12 mb-2 d-flex gap-2 justify-content-end">
        <button class="btn filter-btn btn-sm" type="submit">Filter</button>
        <button type="button" class="btn reset-btn btn-sm"
                onclick="window.location='<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>?tab=view'">Reset</button>
    </div>
</form>

<!-- Attendance Table -->
<div class="table-responsive">
    <table class="table table-bordered table-hover table-striped attendance-table">
        <thead class="table-light">
        <tr>
            <th>Date</th>
            <th>Student</th>
            <th>Subject</th>
            <th>Status</th>
            <?php if ($role === 'admin'): ?>
                <th>Marked By</th>
            <?php endif; ?>
            <!-- Add Action column if needed for delete/edit -->
        </tr>
        </thead>
        <tbody>
        <?php if (empty($records)): ?>
            <tr><td colspan="<?= ($role === 'admin') ? 5 : 4 ?>" class="text-center text-muted fst-italic py-3">No records found matching your filters.</td></tr>
        <?php else: ?>
            <?php foreach($records as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['date']) ?></td>
                    <td><?= htmlspecialchars($row['student']) ?> (<?= htmlspecialchars($row['student_id'])?>)</td>
                    <td><?= htmlspecialchars($row['subject']) ?></td>
                    <td>
                        <?php if ($row['status'] === 'present'): ?>
                            <span class="badge bg-success">Present</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Absent</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($role === 'admin'): ?>
                        <td><?= htmlspecialchars($row['marked_by_user'] ?? 'N/A') ?> (<?= htmlspecialchars($row['marked_by'] ?? 'N/A') ?>)</td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

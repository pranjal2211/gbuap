<?php
// This file assumes session is started and user is teacher or admin
// $user_id and $role are available from attendence.php

if (session_status() == PHP_SESSION_NONE) session_start(); // Re-check just in case
if (!isset($user_id) || !in_array($role, ['teacher', 'admin'])) {
    echo "<div class='alert alert-danger'>Access Denied.</div>";
    exit; // Or handle appropriately
}

// Fetch Students (Same for both roles)
$students = [];
try {
    $stmt_students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username ASC");
    $students = $stmt_students->fetchAll();
} catch (PDOException $e) {
     echo "<div class='alert alert-danger'>Error fetching students: " . $e->getMessage() . "</div>";
}


// --- Define Teacher-Subject Mapping (Needed for Teacher Validation) ---
$teacher_subject_map = [
    7 => ['Digital Image Processing', 'Software Testing', 'Analysis of Design of Algorithm'],
    8 => ['Cybersecurity', 'Web Development using PHP', 'Management Information System'],
];
$teacher_allowed_subjects = ($role === 'teacher') ? ($teacher_subject_map[$user_id] ?? []) : [];
// ------------------------------------------------------------------


// Fetch Subjects based on Role
$subjects_for_dropdown = [];
try {
    if ($role === 'admin') {
        // Admin gets all distinct subjects from the attendance table
        $stmt_subjects = $pdo->query("SELECT DISTINCT subject FROM attendance ORDER BY subject ASC");
        $subjects_for_dropdown = $stmt_subjects->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($role === 'teacher') {
        // Teacher gets only their assigned subjects
        $subjects_for_dropdown = $teacher_allowed_subjects;
        // Optional: If map is empty, maybe fetch subjects they've marked before?
        // if (empty($subjects_for_dropdown)) {
        //    $stmt_subj_fallback = $pdo->prepare("SELECT DISTINCT subject FROM attendance WHERE marked_by = ? ORDER BY subject ASC");
        //    $stmt_subj_fallback->execute([$user_id]);
        //    $subjects_for_dropdown = $stmt_subj_fallback->fetchAll(PDO::FETCH_COLUMN);
        // }
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error fetching subjects: " . $e->getMessage() . "</div>";
}


$msg = ''; // Message for this specific include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    $student_id = $_POST['student_id'];
    $subject = $_POST['subject'];
    $date = $_POST['date'];
    $status = $_POST['status'];

    // --- Teacher Subject Validation ---
    if ($role === 'teacher' && !in_array($subject, $teacher_allowed_subjects)) {
        $msg = '<div class="alert alert-danger">Error: You are not assigned to mark attendance for this subject.</div>';
    } else {
        // --- Check for Duplicate ---
        try {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id=? AND subject=? AND date=?");
            $stmt_check->execute([$student_id, $subject, $date]);
            if ($stmt_check->fetchColumn() > 0) {
                $msg = '<div class="alert alert-warning">Attendance already marked for this student, subject, and date.</div>';
            } else {
                 // --- Insert Attendance ---
                 // Check student exists (redundant if dropdown is used, but safe)
                 $stmt_check_student = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='student'");
                 $stmt_check_student->execute([$student_id]);
                 if ($stmt_check_student->fetchColumn() > 0) {
                     $stmt_insert = $pdo->prepare("INSERT INTO attendance (student_id, subject, date, status, marked_by) VALUES (?, ?, ?, ?, ?)");
                     if ($stmt_insert->execute([$student_id, $subject, $date, $status, $user_id])) { // Use logged-in user ID for marked_by
                         $msg = '<div class="alert alert-success">Attendance marked successfully!</div>';
                     } else {
                         $msg = '<div class="alert alert-danger">Error marking attendance. Database error.</div>';
                         error_log("Mark Attendance DB Error: " . implode(" ", $stmt_insert->errorInfo()));
                     }
                 } else {
                      $msg = '<div class="alert alert-danger">Invalid student selected.</div>';
                 }
            }
        } catch (PDOException $e) {
            $msg = '<div class="alert alert-danger">Database Error: ' . $e->getMessage() . '</div>';
            error_log("Mark Attendance PDO Exception: " . $e->getMessage());
        }
    }
}
?>

<?= $msg ?> <!-- Display message specific to this form -->
<form method="post" style="max-width:500px;margin:0 auto;">
    <div class="mb-3">
        <label class="form-label fw-bold">Student</label>
        <select class="form-select" name="student_id" required>
            <option value="">Select Student</option>
            <?php foreach($students as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['username']) ?> (ID: <?= $s['id'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Subject</label>
        <select class="form-select" name="subject" required>
            <option value="">Select Subject</option>
            <?php if (empty($subjects_for_dropdown)): ?>
                <option value="" disabled>No subjects available</option>
            <?php else: ?>
                <?php foreach($subjects_for_dropdown as $subj): ?>
                    <option value="<?= htmlspecialchars($subj) ?>"><?= htmlspecialchars($subj) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
         <?php if ($role === 'teacher' && empty($subjects_for_dropdown)): ?>
            <small class="text-danger">You are not assigned any subjects to mark.</small>
         <?php endif; ?>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Date</label>
        <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label fw-bold">Status</label>
        <select class="form-select" name="status" required>
            <option value="present">Present</option>
            <option value="absent">Absent</option>
        </select>
    </div>
    <button class="btn btn-danger w-100" type="submit" name="mark_attendance" <?= empty($subjects_for_dropdown) ? 'disabled' : '' ?>>Mark Attendance</button>
</form>

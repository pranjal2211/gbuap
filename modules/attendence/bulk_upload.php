<?php
// --- Error Reporting & Session ---
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// -------------------------------

// --- Check Login & Role (Teacher or Admin) ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    echo "<div class='alert alert-danger'>Access Denied.</div>";
    exit;
}
$role = $_SESSION['role']; // Get role from session
$user_id = $_SESSION['user_id']; // User performing the upload
// ------------------------------------------

// --- DB Connection ---
require '../../config/db.php';
if (!isset($pdo)) { die("Database connection failed."); }
// ---------------------

// --- Variables ---
$theme = $_SESSION['theme'] ?? 'light';
$language = $_SESSION['language'] ?? 'en';
$msg = '';
// ---------------

// --- Teacher-Subject Mapping (Only needed for Teacher role) ---
$teacher_subject_map = [
    7 => ['Digital Image Processing', 'Software Testing', 'Analysis of Design of Algorithm'],
    8 => ['Cybersecurity', 'Web Development using PHP', 'Management Information System'],
];
$allowed_subjects = ($role === 'teacher') ? ($teacher_subject_map[$user_id] ?? []) : [];
// ----------------------------------------------------------

// --- Handle POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp_name = $_FILES['csv_file']['tmp_name'];
        $file = @fopen($file_tmp_name, 'r');

        if ($file === false) {
            $msg = "<div class='alert alert-danger'>Error opening uploaded file.</div>";
        } else {
            $header = fgetcsv($file);
            if ($header === false || count($header) < 4) {
                $msg = "<div class='alert alert-danger'>Invalid CSV header or empty file.</div>";
                @fclose($file);
            } else {
                $success = 0; $fail_duplicate = 0; $fail_subject = 0;
                $fail_invalid_student = 0; $fail_other = 0; $row_num = 1;

                // Prepare statements outside loop
                $stmt_check_student = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'student'");
                $stmt_check_duplicate = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id=? AND subject=? AND date=?");
                $stmt_insert = $pdo->prepare("INSERT INTO attendance (student_id, subject, date, status, marked_by) VALUES (?, ?, ?, ?, ?)");

                while (($row = fgetcsv($file)) !== false) {
                    $row_num++;
                    if (count($row) != 4) { $fail_other++; error_log("CSV Error: Row $row_num bad columns."); continue; }

                    $student_id_raw = trim($row[0]); $subject_raw = trim($row[1]);
                    $date_raw = trim($row[2]); $status_raw = trim($row[3]);

                    // --- Subject Validation (Only for Teacher) ---
                    if ($role === 'teacher' && (empty($allowed_subjects) || !in_array($subject_raw, $allowed_subjects))) {
                        $fail_subject++; error_log("CSV Skip: Row $row_num Subject '$subject_raw' not allowed for teacher $user_id."); continue;
                    }
                    // ------------------------------------------

                    // --- Data Validation ---
                    $student_id = filter_var($student_id_raw, FILTER_VALIDATE_INT);
                    $subject = $subject_raw; $date = $date_raw; $status = strtolower($status_raw);

                    if ($student_id === false || empty($subject) || empty($date) || empty($status)) { $fail_other++; error_log("CSV Error: Row $row_num empty field."); continue; }
                    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) { $fail_other++; error_log("CSV Error: Row $row_num bad date '$date'."); continue; }
                    if ($status !== 'present' && $status !== 'absent') { $fail_other++; error_log("CSV Error: Row $row_num bad status '$status'."); continue; }
                    // ------------------------

                    // --- Check Student Exists ---
                    try {
                        $stmt_check_student->execute([$student_id]);
                        if ($stmt_check_student->fetchColumn() == 0) { $fail_invalid_student++; error_log("CSV Skip: Row $row_num Invalid student ID '$student_id'."); continue; }
                    } catch (PDOException $e) { $fail_other++; error_log("CSV DB Error: Row $row_num Check student fail: " . $e->getMessage()); continue; }
                    // ----------------------------

                    // --- Check Duplicate & Insert ---
                    try {
                        $stmt_check_duplicate->execute([$student_id, $subject, $date]);
                        if ($stmt_check_duplicate->fetchColumn() == 0) {
                            if ($stmt_insert->execute([$student_id, $subject, $date, $status, $user_id])) { // Use current user ID as marker
                                $success++;
                            } else { $fail_other++; error_log("CSV Insert Error: Row $row_num DB error: " . implode(" ", $stmt_insert->errorInfo())); }
                        } else { $fail_duplicate++; }
                    } catch (PDOException $e) { $fail_other++; error_log("CSV DB Error: Row $row_num Check duplicate fail: " . $e->getMessage()); }
                    // -------------------------------
                }
                fclose($file);

                // --- Construct Final Message ---
                $msg_parts = [];
                if ($success > 0) $msg_parts[] = "$success records added.";
                if ($fail_duplicate > 0) $msg_parts[] = "$fail_duplicate skipped (duplicates).";
                if ($fail_subject > 0) $msg_parts[] = "$fail_subject skipped (subject not allowed).";
                if ($fail_invalid_student > 0) $msg_parts[] = "$fail_invalid_student skipped (invalid Student ID).";
                if ($fail_other > 0) $msg_parts[] = "$fail_other failed (invalid data/DB error - check logs).";
                if (!empty($msg_parts)) {
                    $alert_type = ($success > 0 && $fail_subject == 0 && $fail_invalid_student == 0 && $fail_other == 0) ? 'alert-success' : 'alert-warning';
                    $msg = "<div class='alert $alert_type'>Upload: " . implode(' ', $msg_parts) . "</div>";
                } elseif ($row_num <= 1) { $msg = "<div class='alert alert-info'>CSV empty or only header.</div>"; }
                else { $msg = "<div class='alert alert-info'>No valid records processed. Check CSV.</div>"; }
                // -----------------------------
            }
        }
    } elseif (isset($_FILES['csv_file'])) { /* ... Handle Upload Errors ... */
        $upload_errors = [ UPLOAD_ERR_INI_SIZE => 'Ini size err.', UPLOAD_ERR_FORM_SIZE => 'Form size err.', UPLOAD_ERR_PARTIAL => 'Partial upload.', UPLOAD_ERR_NO_FILE => 'No file.', UPLOAD_ERR_NO_TMP_DIR => 'No tmp dir.', UPLOAD_ERR_CANT_WRITE => 'Cant write.', UPLOAD_ERR_EXTENSION => 'Ext stopped upload.', ];
        $error_code = $_FILES['csv_file']['error'];
        $msg = "<div class='alert alert-danger'>Upload err: " . ($upload_errors[$error_code] ?? 'Unknown code ' . $error_code) . "</div>";
    }
}
?>

<!-- Form HTML for Bulk Upload -->
<?php if (!empty($msg)) echo $msg; ?>

<?php if ($role == 'teacher' && empty($allowed_subjects) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <div class="alert alert-warning">You are not assigned any subjects for bulk upload.</div>
<?php else: ?>
    <form enctype="multipart/form-data" method="post" style="max-width:500px;" class="card p-4 shadow-sm">
        <div class="mb-3">
            <label class="form-label fw-bold">Select CSV File</label>
            <input type="file" class="form-control" name="csv_file" required accept=".csv">
            <small class="text-muted d-block mt-1">Format: student_id, subject, date (YYYY-MM-DD), status (present/absent). Header ignored.</small>
            <?php if ($role == 'teacher' && !empty($allowed_subjects)): ?>
                <small class="text-primary fw-bold d-block mt-1">Allowed subjects: <?= htmlspecialchars(implode(', ', $allowed_subjects)) ?></small>
            <?php elseif ($role == 'admin'): ?>
                <small class="text-primary fw-bold d-block mt-1">Admin: All subjects are allowed.</small>
            <?php endif; ?>
        </div>
        <button class="btn btn-danger w-100" type="submit" name="bulk_upload" <?= ($role == 'teacher' && empty($allowed_subjects)) ? 'disabled' : '' ?>>Upload Attendance</button>
    </form>
<?php endif; ?>

<?php
// --- Error Reporting & Session ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// -------------------------------

// --- Check Login & Role (MUST be Admin) ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
// ------------------------------------------

// --- Variables & DB Connection ---
$theme = $_SESSION['theme'] ?? 'light';
$language = $_SESSION['language'] ?? 'en';

require '../../config/db.php';
if (!isset($pdo)) { die("Database connection failed."); }

include '../sidebar.php'; // Include sidebar

// --- Flash Messages & Errors ---
$flash_message = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']);
$page_error = null;

// --- Data for Dropdowns ---
$teachers = $departments = $programs = $sections = $subjects = [];
try {
    $teachers = $pdo->query("SELECT id, username FROM users WHERE role='teacher' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id, d.id as department_id
                             FROM sections sec
                             JOIN programs p ON sec.program_id = p.id
                             JOIN departments d ON p.department_id = d.id
                             ORDER BY d.name, p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $page_error = "Error fetching data for forms: " . $e->getMessage();
    error_log("Assign Faculty - Fetch Form Data Error: " . $e->getMessage());
}
// --- End Data Fetch ---

// --- Handle POST Requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- ASSIGN FACULTY ---
    if (isset($_POST['assign_faculty'])) {
        $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
        $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
        $section_id = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
        $academic_year = trim($_POST['academic_year'] ?? date("Y")."-".(date("Y")+1)); // Default to current academic year format

        if (!$teacher_id || !$subject_id || !$section_id || empty($academic_year)) {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Please select Teacher, Section, Subject, and Academic Year.'];
        } else {
            try {
                // Check if assignment already exists
                $stmt_check = $pdo->prepare("SELECT id FROM faculty_assignments WHERE teacher_user_id = ? AND subject_id = ? AND section_id = ? AND academic_year = ?");
                $stmt_check->execute([$teacher_id, $subject_id, $section_id, $academic_year]);
                if ($stmt_check->fetch()) {
                     $_SESSION['flash_message'] = ['type' => 'warning', 'text' => 'This assignment already exists for the selected academic year.'];
                } else {
                    // Insert new assignment
                    $stmt_insert = $pdo->prepare("INSERT INTO faculty_assignments (teacher_user_id, subject_id, section_id, academic_year) VALUES (?, ?, ?, ?)");
                    if ($stmt_insert->execute([$teacher_id, $subject_id, $section_id, $academic_year])) {
                        $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Faculty assigned successfully!'];
                    } else {
                        $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Failed to assign faculty. Database error.'];
                        error_log("Assign Faculty Error: " . implode(" ", $stmt_insert->errorInfo()));
                    }
                }
            } catch (PDOException $e) {
                 $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Database Error: ' . $e->getMessage()];
                 error_log("Assign Faculty DB Error: " . $e->getMessage());
            }
        }
        // Redirect to clear POST data and show message
        header("Location: assign_faculty.php");
        exit;
    }

    // --- REMOVE ASSIGNMENT ---
    elseif (isset($_POST['remove_assignment'], $_POST['assignment_id'])) {
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);

        if (!$assignment_id) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Invalid assignment ID.'];
        } else {
            try {
                $stmt_delete = $pdo->prepare("DELETE FROM faculty_assignments WHERE id = ?");
                if ($stmt_delete->execute([$assignment_id])) {
                     $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Assignment removed successfully.'];
                } else {
                     $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Failed to remove assignment. Database error.'];
                     error_log("Remove Assignment Error: " . implode(" ", $stmt_delete->errorInfo()));
                }
            } catch (PDOException $e) {
                 $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Database Error: ' . $e->getMessage()];
                 error_log("Remove Assignment DB Error: " . $e->getMessage());
            }
        }
         // Redirect to clear POST data and show message
        header("Location: assign_faculty.php");
        exit;
    }
}
// --- End POST Handling ---

// --- Fetch Current Assignments for Display ---
$current_assignments = [];
if (!$page_error) { // Only fetch if initial data load was ok
    try {
        $sql_assignments = "SELECT
                                fa.id as assignment_id,
                                fa.academic_year,
                                u.username as teacher_name,
                                subj.name as subject_name,
                                sec.year as section_year,
                                sec.section_name,
                                p.name as program_name,
                                d.name as department_name
                            FROM faculty_assignments fa
                            JOIN users u ON fa.teacher_user_id = u.id AND u.role = 'teacher'
                            JOIN subjects subj ON fa.subject_id = subj.id
                            JOIN sections sec ON fa.section_id = sec.id
                            JOIN programs p ON sec.program_id = p.id
                            JOIN departments d ON p.department_id = d.id
                            ORDER BY d.name, p.name, sec.year, sec.section_name, subj.name, u.username";
        $stmt_assignments = $pdo->query($sql_assignments);
        $current_assignments = $stmt_assignments->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $page_error = "Error fetching current assignments: " . $e->getMessage();
        error_log("Assign Faculty - Fetch Assignments Error: " . $e->getMessage());
    }
}
// --- End Fetch Current Assignments ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Faculty</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .main-content { margin-left: 230px; padding: 2rem; min-height: 100vh; }
        .card-header { background-color: #ffeaea; color: #d32f2f; font-weight: bold; }
        .table th { background-color: #f1f1f1; }
        .table td, .table th { vertical-align: middle; font-size: 0.9rem; padding: 0.6rem 0.75rem; }
        .btn-gbu-red { background-color: #d32f2f; border-color: #d32f2f; color: #fff; }
        .btn-gbu-red:hover { background-color: #b71c1c; border-color: #a31515; }
        .action-btn { padding: 0.3rem 0.6rem; font-size: 0.8rem; }
        .form-label { margin-bottom: 0.2rem; font-weight: 500; }
        .form-select-sm, .form-control-sm { font-size: 0.875rem; }
        #program_id option, #section_id option { display: none; } /* Hide initially for cascading */
        #program_id option[value=""], #section_id option[value=""] { display: block; } /* Always show default */
        @media (max-width: 767px) { .main-content { margin-left: 0; padding: 1rem; } }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">

<div class="main-content">
    <h2 class="mb-4" style="color:#d32f2f;font-weight:700;">Assign Faculty to Sections/Subjects</h2>

    <?php // Display Flash Message
        if ($flash_message): ?>
        <div class="alert alert-<?= htmlspecialchars($flash_message['type']) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flash_message['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php // Display critical page errors
        if ($page_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($page_error) ?></div>
    <?php endif; ?>

    <?php // --- Assignment Form --- ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header">Assign New</div>
        <div class="card-body">
             <?php if($page_error): ?>
                <p class="text-danger">Cannot display form due to data loading error.</p>
            <?php else: ?>
                <form method="post" action="assign_faculty.php">
                    <input type="hidden" name="assign_faculty" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="teacher_id" class="form-label">Teacher <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="teacher_id" name="teacher_id" required>
                                <option value="">-- Select Teacher --</option>
                                <?php foreach($teachers as $teacher): ?>
                                    <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                             <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                             <select class="form-select form-select-sm" id="subject_id" name="subject_id" required>
                                 <option value="">-- Select Subject --</option>
                                 <?php foreach($subjects as $subject): ?>
                                     <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['name']) ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>

                        <div class="col-md-4">
                            <label for="department_id_filter" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="department_id_filter" required> <?php // This ONLY filters, not submitted ?>
                                <option value="">-- Select Department --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                             <label for="program_id_filter" class="form-label">Program <span class="text-danger">*</span></label>
                             <select class="form-select form-select-sm" id="program_id_filter" required> <?php // This ONLY filters, not submitted ?>
                                 <option value="">-- Select Program --</option>
                                 <?php foreach($programs as $prog): ?>
                                     <option value="<?= $prog['id'] ?>" data-dept="<?= $prog['department_id'] ?>"><?= htmlspecialchars($prog['name']) ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="col-md-4">
                             <label for="section_id" class="form-label">Section <span class="text-danger">*</span></label>
                             <select class="form-select form-select-sm" id="section_id" name="section_id" required> <?php // This one IS submitted ?>
                                 <option value="">-- Select Section --</option>
                                 <?php foreach($sections as $sec): ?>
                                     <option value="<?= $sec['id'] ?>" data-prog="<?= $sec['program_id'] ?>">
                                         Yr <?= htmlspecialchars($sec['year']) ?> - Sec <?= htmlspecialchars($sec['section_name']) ?> (<?= htmlspecialchars($sec['program_name']) ?>)
                                     </option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="col-md-4">
                              <label for="academic_year" class="form-label">Academic Year <span class="text-danger">*</span></label>
                              <input type="text" class="form-control form-control-sm" id="academic_year" name="academic_year" value="<?= date("Y")."-".(date("Y")+1) ?>" required pattern="\d{4}-\d{4}" title="Format: YYYY-YYYY (e.g., 2024-2025)">
                         </div>
                         <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-gbu-red">Assign Faculty</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php // --- Current Assignments Table --- ?>
    <div class="card shadow-sm">
        <div class="card-header">Current Assignments</div>
        <div class="card-body p-0">
             <?php if($page_error): ?>
                <p class="text-danger p-3">Cannot display assignments due to data loading error.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Subject</th>
                                <th>Department</th>
                                <th>Program</th>
                                <th>Section</th>
                                <th>Year</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($current_assignments)): ?>
                                <tr><td colspan="7" class="text-center text-muted p-4">No assignments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($current_assignments as $assignment): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($assignment['teacher_name']) ?></td>
                                        <td><?= htmlspecialchars($assignment['subject_name']) ?></td>
                                        <td><?= htmlspecialchars($assignment['department_name']) ?></td>
                                        <td><?= htmlspecialchars($assignment['program_name']) ?></td>
                                        <td>Yr <?= htmlspecialchars($assignment['section_year']) ?> - Sec <?= htmlspecialchars($assignment['section_name']) ?></td>
                                        <td><?= htmlspecialchars($assignment['academic_year']) ?></td>
                                        <td>
                                            <form method="post" action="assign_faculty.php" onsubmit="return confirm('Are you sure you want to remove this assignment?');" style="display: inline;">
                                                <input type="hidden" name="remove_assignment" value="1">
                                                <input type="hidden" name="assignment_id" value="<?= $assignment['assignment_id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm action-btn" title="Remove Assignment">
                                                    <i class="fas fa-trash-alt"></i> Remove
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const deptSelect = document.getElementById('department_id_filter');
    const progSelect = document.getElementById('program_id_filter');
    const secSelect = document.getElementById('section_id'); // The actual select element to submit

    // Function to filter options
    function filterOptions(targetSelect, attribute, value) {
        if (!targetSelect) return;
        const options = targetSelect.options;
        let firstMatchVisible = false;
        options[0].style.display = 'block'; // Ensure '-- Select --' is visible
        options[0].selected = true; // Default to '-- Select --'

        for (let i = 1; i < options.length; i++) { // Start from 1
            const optionAttrVal = options[i].getAttribute(attribute);
            if (!value || optionAttrVal === value) { // If no parent selected or it matches
                options[i].style.display = 'block';
                if (!firstMatchVisible) {
                    // options[i].selected = true; // Optionally auto-select the first match
                    firstMatchVisible = true;
                }
            } else {
                options[i].style.display = 'none';
            }
        }
         // If no matches were found (other than default), keep default selected
        if (!firstMatchVisible) {
             options[0].selected = true;
        }
    }

    // Department -> Program
    if (deptSelect && progSelect) {
        deptSelect.addEventListener('change', function() {
            filterOptions(progSelect, 'data-dept', this.value);
            // Trigger change on program to filter sections
            progSelect.dispatchEvent(new Event('change'));
        });
        // Initial filter based on default (which is likely empty)
        filterOptions(progSelect, 'data-dept', deptSelect.value);
    }

    // Program -> Section
    if (progSelect && secSelect) {
        progSelect.addEventListener('change', function() {
            filterOptions(secSelect, 'data-prog', this.value);
        });
        // Initial filter based on default program selection
        filterOptions(secSelect, 'data-prog', progSelect.value);
    }

});
</script>

</body>
</html>

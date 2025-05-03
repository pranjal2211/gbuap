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
$logged_in_admin_id = $_SESSION['user_id'];

require '../../config/db.php';
if (!isset($pdo)) { die("Database connection failed."); }

include '../sidebar.php'; // Include sidebar

// --- Configuration ---
$allowed_roles = ['admin', 'hod', 'teacher', 'student', 'parent'];
$flash_message = $_SESSION['flash_message'] ?? null; // Get flash message
unset($_SESSION['flash_message']); // Clear flash message after retrieving
$page_error = null; // For critical errors
$edit_user_data = null; // Store data for the user being edited

// --- Determine Action ---
$action = $_GET['action'] ?? 'list'; // Default action is 'list'
$user_id_to_edit = ($action === 'edit' && isset($_GET['id'])) ? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) : null;
$user_id_to_delete = ($action === 'delete' && isset($_GET['id'])) ? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) : null;

// --- Data for Forms (Dropdowns) ---
$departments = $programs = $sections = $parents = [];
try {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $sections = $pdo->query("SELECT sec.id, sec.year, sec.section_name, p.name as program_name, p.id as program_id FROM sections sec JOIN programs p ON sec.program_id = p.id ORDER BY p.name, sec.year, sec.section_name")->fetchAll(PDO::FETCH_ASSOC);
    $parents = $pdo->query("SELECT id, username, email FROM users WHERE role='parent' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $page_error = "Error fetching data for forms: " . $e->getMessage();
    error_log("Manage Users - Fetch Form Data Error: " . $e->getMessage());
}
// --- End Data for Forms ---

// --- Helper Function: Check Last Admin ---
function isLastAdmin($pdo, $userIdBeingModified) {
    try {
        $stmt_check_last = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $stmt_check_last->execute();
        $admin_count = $stmt_check_last->fetchColumn();

        $stmt_is_admin = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt_is_admin->execute([$userIdBeingModified]);
        $current_role = $stmt_is_admin->fetchColumn();

        return ($admin_count <= 1 && $current_role === 'admin');
    } catch (PDOException $e) {
        error_log("isLastAdmin Check Error: " . $e->getMessage());
        return true; // Fail safe: assume it IS the last admin if DB error occurs
    }
}
// --- End Helper ---


// --- Handle POST Requests (Add/Update/Delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- ADD USER ---
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        $dept_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: null;
        $prog_id = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT) ?: null;
        $sec_id = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT) ?: null;
        $parent_id = filter_input(INPUT_POST, 'parent_id', FILTER_VALIDATE_INT) ?: null;

        // Basic Validation
        if (empty($username) || !$email || empty($password) || !in_array($role, $allowed_roles)) {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Invalid input. Please check all required fields.'];
        } else {
             // Role-specific validation
            if (($role === 'hod' || $role === 'teacher') && !$dept_id) {
                 $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Department is required for HOD/Teacher roles.'];
            } elseif ($role === 'student' && (!$prog_id || !$sec_id)) { // Parent is optional for student
                 $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Program and Section are required for Student role.'];
            } else {
                try {
                    // Check if email or username already exists
                    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
                    $stmt_check->execute([$email, $username]);
                    if ($stmt_check->fetch()) {
                         $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Username or Email already exists.'];
                    } else {
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                        // Prepare insert statement
                        $sql = "INSERT INTO users (username, email, password, role, department_id, program_id, section_id, parent_id, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        $stmt_insert = $pdo->prepare($sql);

                        // Execute insert
                        if ($stmt_insert->execute([$username, $email, $hashed_password, $role, $dept_id, $prog_id, $sec_id, $parent_id])) {
                            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'User added successfully!'];
                            header("Location: manage_users.php"); // Redirect to list view
                            exit;
                        } else {
                            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Failed to add user. Database error.'];
                            error_log("Admin Add User Error: " . implode(" ", $stmt_insert->errorInfo()));
                        }
                    }
                } catch (PDOException $e) {
                     $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Database Error: ' . $e->getMessage()];
                     error_log("Admin Add User DB Error: " . $e->getMessage());
                }
            }
        }
        // Redirect back to add form on error to show message
        header("Location: manage_users.php?action=add");
        exit;
    }

    // --- UPDATE USER ---
    elseif (isset($_POST['update_user'], $_POST['user_id'])) {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $username = trim($_POST['username'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? ''; // Optional new password
        $role = $_POST['role'] ?? '';
        $dept_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: null;
        $prog_id = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT) ?: null;
        $sec_id = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT) ?: null;
        $parent_id = filter_input(INPUT_POST, 'parent_id', FILTER_VALIDATE_INT) ?: null;

        // Basic Validation
        if (!$user_id || empty($username) || !$email || !in_array($role, $allowed_roles)) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Invalid input for update.'];
        }
        // Prevent admin from changing their OWN role here (can be done via profile/special interface)
        elseif ($user_id == $logged_in_admin_id && $role !== 'admin') {
              $_SESSION['flash_message'] = ['type' => 'warning', 'text' => 'You cannot change your own role here.'];
        }
        // Prevent changing role AWAY from admin if it's the last one
        elseif ($role !== 'admin' && isLastAdmin($pdo, $user_id)) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Cannot remove the last administrator role.'];
        } else {
             // Role-specific validation
             if (($role === 'hod' || $role === 'teacher') && !$dept_id) {
                  $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Department is required for HOD/Teacher roles.'];
             } elseif ($role === 'student' && (!$prog_id || !$sec_id)) {
                  $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Program and Section are required for Student role.'];
             } else {
                try {
                    // Check if new email/username conflicts with ANOTHER user
                    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
                    $stmt_check->execute([$email, $username, $user_id]);
                    if ($stmt_check->fetch()) {
                         $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Username or Email already exists for another user.'];
                    } else {
                         // Build update query
                         $sql_parts = ["username = ?", "email = ?", "role = ?", "department_id = ?", "program_id = ?", "section_id = ?", "parent_id = ?"];
                         $params = [$username, $email, $role, $dept_id, $prog_id, $sec_id, $parent_id];

                         // Handle optional password update
                         if (!empty($password)) {
                             $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                             $sql_parts[] = "password = ?";
                             $params[] = $hashed_password;
                         }

                         $params[] = $user_id; // Add user ID for WHERE clause

                         $sql = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = ?";
                         $stmt_update = $pdo->prepare($sql);

                         if ($stmt_update->execute($params)) {
                             $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'User updated successfully!'];
                             header("Location: manage_users.php"); // Redirect to list view
                             exit;
                         } else {
                             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Failed to update user. Database error.'];
                             error_log("Admin Update User Error: " . implode(" ", $stmt_update->errorInfo()));
                         }
                    }
                } catch (PDOException $e) {
                    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Database Error: ' . $e->getMessage()];
                    error_log("Admin Update User DB Error: " . $e->getMessage());
                }
            }
        }
        // Redirect back to edit form on error
        header("Location: manage_users.php?action=edit&id=" . $user_id);
        exit;
    }

     // --- DELETE USER ---
    elseif (isset($_POST['delete_user'], $_POST['user_id'])) {
        $user_id_to_delete = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$user_id_to_delete) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Invalid user ID for deletion.'];
        } elseif ($user_id_to_delete == $logged_in_admin_id) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'You cannot delete your own account.'];
        } elseif (isLastAdmin($pdo, $user_id_to_delete)) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Cannot delete the last administrator.'];
        } else {
            try {
                // Potential future checks: reassign related data or prevent deletion if critical relations exist.
                // For now, simple delete:
                $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                if ($stmt_delete->execute([$user_id_to_delete])) {
                    $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'User deleted successfully.'];
                } else {
                    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Failed to delete user. Database error.'];
                     error_log("Admin Delete User Error: " . implode(" ", $stmt_delete->errorInfo()));
                }
            } catch (PDOException $e) {
                 $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Database Error on delete: ' . $e->getMessage()];
                 error_log("Admin Delete User DB Error: " . $e->getMessage());
            }
        }
        header("Location: manage_users.php"); // Redirect back to list
        exit;
    }
}
// --- End POST Handling ---


// --- Fetch Data For Display (List/Edit View) ---
$users = [];
$filter_role = $_GET['filter_role'] ?? '';
$filter_dept = $_GET['filter_dept'] ?? '';

if ($action === 'list') {
    try {
        $sql = "SELECT u.id, u.username, u.email, u.role, u.created_at, d.name as department_name
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id";
        $conditions = [];
        $params = [];
        if (!empty($filter_role) && in_array($filter_role, $allowed_roles)) {
            $conditions[] = "u.role = ?";
            $params[] = $filter_role;
        }
        if (!empty($filter_dept)) {
            $conditions[] = "u.department_id = ?";
            $params[] = $filter_dept;
        }
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY u.role ASC, u.username ASC";

        $stmt_users = $pdo->prepare($sql);
        $stmt_users->execute($params);
        $users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $page_error = "Error fetching user list: " . $e->getMessage();
        error_log("Admin Fetch Users Error: " . $e->getMessage());
    }
} elseif ($action === 'edit' && $user_id_to_edit) {
    try {
         $stmt_edit = $pdo->prepare("SELECT * FROM users WHERE id = ?");
         $stmt_edit->execute([$user_id_to_edit]);
         $edit_user_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);
         if (!$edit_user_data) {
             $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'User not found for editing.'];
             header("Location: manage_users.php");
             exit;
         }
    } catch (PDOException $e) {
         $page_error = "Error fetching user for edit: " . $e->getMessage();
         error_log("Admin Fetch User Edit Error: " . $e->getMessage());
         $action = 'list'; // Fallback to list view on error
    }
}
// --- End Fetch Data ---


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .main-content { margin-left: 230px; padding: 2rem; min-height: 100vh; }
        .card-header { background-color: #ffeaea; color:rgb(17, 5, 248); font-weight: bold; }
        .table th { background-color: #f1f1f1; color: #333; }
        .table td, .table th { vertical-align: middle; font-size: 0.9rem; }
        .action-buttons a, .action-buttons button { margin-right: 5px; }
        .badge { font-size: 0.85rem; }
        .form-section { margin-bottom: 1.5rem; }
        .conditional-field { display: none; } /* Initially hide */
        @media (max-width: 767px) { .main-content { margin-left: 0; padding: 1rem; } }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">

<div class="main-content">
    <h2 class="mb-4" style="color:#d32f2f;font-weight:700;">User Management</h2>

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


    <?php // --- ADD USER FORM --- ?>
    <?php if ($action === 'add'): ?>
        <div class="card shadow-sm">
            <div class="card-header">Add New User</div>
            <div class="card-body">
                <form method="post" action="manage_users.php" id="addUserForm">
                    <input type="hidden" name="add_user" value="1">
                    <div class="row g-3">
                        <div class="col-md-6 form-section">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="username" name="username" required>
                        </div>
                        <div class="col-md-6 form-section">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control form-control-sm" id="email" name="email" required>
                        </div>
                        <div class="col-md-6 form-section">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-sm" id="password" name="password" required>
                        </div>
                        <div class="col-md-6 form-section">
                            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="role" name="role" required>
                                <option value="">-- Select Role --</option>
                                <?php foreach ($allowed_roles as $role_option): ?>
                                    <option value="<?= $role_option ?>"><?= ucfirst($role_option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php /* --- Conditional Fields --- */ ?>
                        <div class="col-md-6 form-section conditional-field" id="department-field">
                            <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="department_id" name="department_id">
                                <option value="">-- Select Department --</option>
                                <?php foreach($departments as $dept): ?> <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 form-section conditional-field" id="program-field">
                            <label for="program_id" class="form-label">Program <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="program_id" name="program_id">
                                <option value="">-- Select Program --</option>
                                <?php foreach($programs as $prog): ?> <option value="<?= $prog['id'] ?>" data-dept="<?= $prog['department_id'] ?>"><?= htmlspecialchars($prog['name']) ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 form-section conditional-field" id="section-field">
                            <label for="section_id" class="form-label">Section <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="section_id" name="section_id">
                                <option value="">-- Select Section --</option>
                                <?php foreach($sections as $sec): ?> <option value="<?= $sec['id'] ?>" data-prog="<?= $sec['program_id'] ?>"><?= htmlspecialchars($sec['program_name']) ?> Yr<?= $sec['year'] ?> Sec<?= htmlspecialchars($sec['section_name']) ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 form-section conditional-field" id="parent-field">
                            <label for="parent_id" class="form-label">Link to Parent (Optional)</label>
                            <select class="form-select form-select-sm" id="parent_id" name="parent_id">
                                <option value="">-- Select Parent --</option>
                                <?php foreach($parents as $parent): ?> <option value="<?= $parent['id'] ?>"><?= htmlspecialchars($parent['username']) ?> (<?= htmlspecialchars($parent['email']) ?>)</option> <?php endforeach; ?>
                            </select>
                        </div>
                        <?php /* --- End Conditional Fields --- */ ?>

                    </div>
                    <hr class="my-4">
                    <div class="d-flex justify-content-end gap-2">
                         <a href="manage_users.php" class="btn btn-gbu-secondary action-btn">Cancel</a>
                         <button type="submit" class="btn btn-gbu-red action-btn">Add User</button>
                    </div>
                </form>
            </div>
        </div>

    <?php // --- EDIT USER FORM --- ?>
    <?php elseif ($action === 'edit' && $edit_user_data): ?>
         <div class="card shadow-sm">
            <div class="card-header">Edit User: <?= htmlspecialchars($edit_user_data['username']) ?> (ID: <?= $edit_user_data['id'] ?>)</div>
            <div class="card-body">
                <form method="post" action="manage_users.php" id="editUserForm">
                    <input type="hidden" name="update_user" value="1">
                    <input type="hidden" name="user_id" value="<?= $edit_user_data['id'] ?>">
                     <div class="row g-3">
                        <div class="col-md-6 form-section">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="username" name="username" value="<?= htmlspecialchars($edit_user_data['username']) ?>" required>
                        </div>
                        <div class="col-md-6 form-section">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control form-control-sm" id="email" name="email" value="<?= htmlspecialchars($edit_user_data['email']) ?>" required>
                        </div>
                        <div class="col-md-6 form-section">
                            <label for="password" class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control form-control-sm" id="password" name="password">
                        </div>
                        <div class="col-md-6 form-section">
                            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="role" name="role" required <?= ($edit_user_data['id'] == $logged_in_admin_id || isLastAdmin($pdo, $edit_user_data['id'])) ? 'disabled' : '' ?>> <?php // Disable if self or last admin ?>
                                <?php foreach ($allowed_roles as $role_option): ?>
                                    <option value="<?= $role_option ?>" <?= ($edit_user_data['role'] == $role_option) ? 'selected' : '' ?>><?= ucfirst($role_option) ?></option>
                                <?php endforeach; ?>
                            </select>
                             <?php if ($edit_user_data['id'] == $logged_in_admin_id): ?> <small class="text-muted">Cannot change own role.</small> <?php endif; ?>
                             <?php if (isLastAdmin($pdo, $edit_user_data['id']) && $edit_user_data['role'] === 'admin'): ?> <small class="text-danger">Cannot change role of last admin.</small> <?php endif; ?>
                        </div>

                        <?php /* --- Conditional Fields --- */ ?>
                         <div class="col-md-6 form-section conditional-field" id="department-field">
                            <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="department_id" name="department_id">
                                <option value="">-- Select Department --</option>
                                <?php foreach($departments as $dept): ?> <option value="<?= $dept['id'] ?>" <?= ($edit_user_data['department_id'] == $dept['id'])?'selected':'' ?>><?= htmlspecialchars($dept['name']) ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 form-section conditional-field" id="program-field">
                            <label for="program_id" class="form-label">Program <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="program_id" name="program_id">
                                <option value="">-- Select Program --</option>
                                <?php foreach($programs as $prog): ?> <option value="<?= $prog['id'] ?>" data-dept="<?= $prog['department_id'] ?>" <?= ($edit_user_data['program_id'] == $prog['id'])?'selected':'' ?>><?= htmlspecialchars($prog['name']) ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 form-section conditional-field" id="section-field">
                            <label for="section_id" class="form-label">Section <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="section_id" name="section_id">
                                <option value="">-- Select Section --</option>
                                <?php foreach($sections as $sec): ?> <option value="<?= $sec['id'] ?>" data-prog="<?= $sec['program_id'] ?>" <?= ($edit_user_data['section_id'] == $sec['id'])?'selected':'' ?>><?= htmlspecialchars($sec['program_name']) ?> Yr<?= $sec['year'] ?> Sec<?= htmlspecialchars($sec['section_name']) ?></option> <?php endforeach; ?>
                            </select>
                        </div>
                         <div class="col-md-6 form-section conditional-field" id="parent-field">
                            <label for="parent_id" class="form-label">Link to Parent (Optional)</label>
                            <select class="form-select form-select-sm" id="parent_id" name="parent_id">
                                <option value="">-- Select Parent --</option>
                                <?php foreach($parents as $parent): ?> <option value="<?= $parent['id'] ?>" <?= ($edit_user_data['parent_id'] == $parent['id'])?'selected':'' ?>><?= htmlspecialchars($parent['username']) ?> (<?= htmlspecialchars($parent['email']) ?>)</option> <?php endforeach; ?>
                            </select>
                        </div>
                         <?php /* --- End Conditional Fields --- */ ?>

                    </div>
                    <hr class="my-4">
                    <div class="d-flex justify-content-end gap-2">
                         <a href="manage_users.php" class="btn btn-gbu-secondary action-btn">Cancel</a>
                         <button type="submit" class="btn btn-gbu-red action-btn">Update User</button>
                    </div>
                </form>
            </div>
        </div>

    <?php // --- LIST USERS VIEW (DEFAULT) --- ?>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
             <h4 class="mb-0">User List</h4>
             <a href="manage_users.php?action=add" class="btn btn-gbu-red btn-sm action-btn">
                 <i class="fas fa-plus me-1"></i> Add New User
             </a>
        </div>

        <?php // --- Filter Form --- ?>
        <form method="get" action="manage_users.php" class="row g-2 mb-4 align-items-end bg-light p-3 rounded border">
            <div class="col-md-4">
                <label for="filter_role" class="form-label mb-1"><small>Filter by Role:</small></label>
                <select name="filter_role" id="filter_role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                     <?php foreach ($allowed_roles as $role_option): ?>
                        <option value="<?= $role_option ?>" <?= ($filter_role == $role_option) ? 'selected' : '' ?>><?= ucfirst($role_option) ?></option>
                     <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                 <label for="filter_dept" class="form-label mb-1"><small>Filter by Department:</small></label>
                 <select name="filter_dept" id="filter_dept" class="form-select form-select-sm">
                     <option value="">All Departments</option>
                      <?php foreach($departments as $dept): ?> <option value="<?= $dept['id'] ?>" <?= ($filter_dept == $dept['id'])?'selected':'' ?>><?= htmlspecialchars($dept['name']) ?></option> <?php endforeach; ?>
                 </select>
            </div>
             <div class="col-md-2">
                 <button type="submit" class="btn btn-secondary btn-sm w-100">Filter</button>
            </div>
             <div class="col-md-2">
                 <a href="manage_users.php" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
            </div>
        </form>
        <?php // --- End Filter Form --- ?>

        <div class="card shadow-sm">
            <div class="card-body p-0"> <?php // Remove padding for full-width table ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0"> <?php // Removed table-bordered ?>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="7" class="text-center text-muted p-4">No users found matching filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user_item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user_item['id']) ?></td>
                                    <td><?= htmlspecialchars($user_item['username']) ?></td>
                                    <td><?= htmlspecialchars($user_item['email']) ?></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst(htmlspecialchars($user_item['role'])) ?></span></td>
                                    <td><?= htmlspecialchars($user_item['department_name'] ?? 'N/A') ?></td>
                                    <td><?= date("d M Y", strtotime($user_item['created_at'])) ?></td>
                                    <td class="action-buttons">
                                        <a href="manage_users.php?action=edit&id=<?= $user_item['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit User">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($user_item['id'] != $logged_in_admin_id && !isLastAdmin($pdo, $user_item['id'])): ?>
                                            <?php // Use a small form for delete to use POST ?>
                                            <form method="post" action="manage_users.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete user <?= htmlspecialchars(addslashes($user_item['username'])) ?>? This cannot be undone.');">
                                                <input type="hidden" name="delete_user" value="1">
                                                <input type="hidden" name="user_id" value="<?= $user_item['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete User">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                             <button class="btn btn-sm btn-outline-secondary" disabled title="Cannot delete self or last admin">
                                                 <i class="fas fa-trash-alt"></i>
                                             </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Function to show/hide conditional fields based on selected role
    function handleRoleChange() {
        const roleSelect = document.getElementById('role');
        const selectedRole = roleSelect ? roleSelect.value : null;

        const deptField = document.getElementById('department-field');
        const progField = document.getElementById('program-field');
        const secField = document.getElementById('section-field');
        const parentField = document.getElementById('parent-field');

        // Hide all conditional fields initially
        if(deptField) deptField.style.display = 'none';
        if(progField) progField.style.display = 'none';
        if(secField) secField.style.display = 'none';
        if(parentField) parentField.style.display = 'none';

        // Get the required inputs inside the fields (if they exist)
        const deptInput = deptField ? deptField.querySelector('select, input') : null;
        const progInput = progField ? progField.querySelector('select, input') : null;
        const secInput = secField ? secField.querySelector('select, input') : null;
        // Parent is optional, no need to make it required

        // Remove 'required' attribute from all conditional inputs initially
        if (deptInput) deptInput.required = false;
        if (progInput) progInput.required = false;
        if (secInput) secInput.required = false;

        // Show fields based on role and set 'required'
        if (selectedRole === 'hod' || selectedRole === 'teacher') {
            if(deptField) deptField.style.display = 'block';
            if (deptInput) deptInput.required = true;
        } else if (selectedRole === 'student') {
            if(progField) progField.style.display = 'block';
            if(secField) secField.style.display = 'block';
            if(parentField) parentField.style.display = 'block'; // Parent is optional
             if (progInput) progInput.required = true;
             if (secInput) secInput.required = true;
        }
    }

    // --- Cascading Dropdowns for Add/Edit Forms ---
    const editProgSelect = document.getElementById('program_id');
    const editSecSelect = document.getElementById('section_id');
    const editDeptSelect = document.getElementById('department_id'); // Needed if program depends on dept

    function filterOptions(targetSelect, attribute, value) {
        if (!targetSelect) return;
        const options = targetSelect.options;
        let firstVisibleSelected = false;
        options[0].style.display = 'block'; // Ensure "-- Select --" is visible
        options[0].selected = true; // Default to "-- Select --"

        for (let i = 1; i < options.length; i++) {
            const optionAttrVal = options[i].getAttribute(attribute);
            if (!value || optionAttrVal === value) {
                options[i].style.display = 'block';
                // If this option's value matches the original value (for edit form), select it
                if(options[i].value === targetSelect.getAttribute('data-current-value')) {
                    options[i].selected = true;
                    firstVisibleSelected = true;
                }
            } else {
                options[i].style.display = 'none';
            }
        }
         // If the pre-selected value wasn't found or is hidden, keep "-- Select --" selected
        if (!firstVisibleSelected) {
             options[0].selected = true;
        }
    }

    // Setup for Edit form (if present)
    if (editProgSelect) editProgSelect.setAttribute('data-current-value', '<?= $edit_user_data['program_id'] ?? '' ?>');
    if (editSecSelect) editSecSelect.setAttribute('data-current-value', '<?= $edit_user_data['section_id'] ?? '' ?>');
    // Note: Department doesn't cascade FROM anything in this form layout

    // Add listeners
    const roleSelectElement = document.getElementById('role');
    if (roleSelectElement) {
        roleSelectElement.addEventListener('change', handleRoleChange);
        // Initial call to set fields based on default/selected role on page load
        handleRoleChange();
    }
     // Cascading for Program -> Section
     if (editProgSelect && editSecSelect) {
        editProgSelect.addEventListener('change', function() {
             editSecSelect.setAttribute('data-current-value', ''); // Clear current value on parent change
            filterOptions(editSecSelect, 'data-prog', this.value);
        });
        // Initial filter on load (for edit form)
        filterOptions(editSecSelect, 'data-prog', editProgSelect.value);
    }
     // Cascading for Department -> Program (if needed, but not strictly required by UI)
     if (editDeptSelect && editProgSelect) {
         editDeptSelect.addEventListener('change', function() {
              editProgSelect.setAttribute('data-current-value', '');
              filterOptions(editProgSelect, 'data-dept', this.value);
              // Since Program changed, re-filter Section
              if(editSecSelect) {
                  editSecSelect.setAttribute('data-current-value', '');
                   filterOptions(editSecSelect, 'data-prog', editProgSelect.value);
              }
         });
          // Initial filter on load (for edit form)
          filterOptions(editProgSelect, 'data-dept', editDeptSelect.value, false); // false = don't trigger section reset yet
          // Now filter section based on the potentially pre-selected program
           if(editSecSelect) filterOptions(editSecSelect, 'data-prog', editProgSelect.value);
     }


</script>

</body>
</html>

<?php
// Use session_status() check - safer than !isset($_SESSION)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']); // Get current script filename
$theme = $_SESSION['theme'] ?? 'light';
$language = $_SESSION['language'] ?? 'en';
$role = $_SESSION['role'] ?? ''; // Get current user's role

// --- Determine Correct Dashboard Link based on Role ---
// Assuming sidebar.php is included from a file one level inside 'modules' (e.g., modules/dashboard/dashboard.php)
// links should point relative to the 'modules' directory using '../'
$dashboard_link = '../dashboard/dashboard.php'; // Default for student/parent
if ($role === 'teacher') {
    $dashboard_link = '../dashboard/dashboard_teacher.php';
} elseif ($role === 'admin') { // Dean is 'admin'
    $dashboard_link = '../dashboard/dashboard_admin.php';
} elseif ($role === 'hod') { // Add HOD dashboard if one exists
    // Assuming HODs use a specific dashboard, otherwise remove this elseif
     $dashboard_link = '../dashboard/dashboard_hod.php';
    // If HODs use the same as admin, change the elseif above to:
    // } elseif ($role === 'admin' || $role === 'hod') {
    //    $dashboard_link = '../dashboard/dashboard_admin.php'; // Or specific HOD dashboard
}


// --- Define Link Paths ---
$attendance_link = '../attendence/attendence.php';
$report_link = '../reports/generate.php'; // This is the UI page
$notify_link = '../notifications/notify.php';
$manage_users_link = '../dashboard/manage_users.php'; // Admin: User Management
$assign_faculty_link = '../dashboard/assign_faculty.php'; // Admin: Faculty Assignment page (NEW - choose a suitable location)
$logout_link = '../auth/logout.php';
$logo_path = '../../assets/gbu-logo.png'; // Path relative to where sidebar.php is included

?>
<nav class="sidebar" style="background:#fff;min-height:100vh;padding:2.5rem 1.3rem 2.5rem 1.3rem;position:fixed;left:0;top:0;bottom:0;width:230px;z-index:100;display:flex;flex-direction:column;align-items:flex-start;border:1px solid #e4e7ed;box-shadow:0 2px 24px 0 rgba(0,0,0,0.04);">
    <a href="<?= htmlspecialchars($dashboard_link) ?>" style="display: block; margin-left: auto; margin-right: auto; margin-bottom: 2.2rem;">
        <img src="<?= htmlspecialchars($logo_path) ?>" class="logo" alt="GBU Logo" style="width:120px;">
    </a>
    <div class="sidebar-title mb-4" style="font-size:1.1rem;font-weight:700;color:#d32f2f;margin-bottom:2.2rem;letter-spacing:0.01em;text-align:center;width:100%;">ATTENDANCE PORTAL</div>
    <ul class="nav flex-column w-100">
        <li>
            <a class="nav-link<?php
                // Active check for various dashboards
                $is_dashboard_active = false;
                if (($role === 'admin' && $current_page == 'dashboard_admin.php') ||
                    ($role === 'teacher' && $current_page == 'dashboard_teacher.php') ||
                    ($role === 'hod' && $current_page == 'dashboard_hod.php') || // Check HOD dashboard
                    (in_array($role, ['student', 'parent']) && $current_page == 'dashboard.php')) {
                    $is_dashboard_active = true;
                }
                if ($is_dashboard_active) echo ' active';
               ?>" href="<?= htmlspecialchars($dashboard_link) ?>">
               <i class="fa fa-home"></i> Dashboard
            </a>
        </li>
        <li>
            <a class="nav-link<?php if($current_page == 'attendence.php') echo ' active'; ?>" href="<?= htmlspecialchars($attendance_link) ?>">
                <i class="fa fa-calendar-check"></i> Attendance
            </a>
        </li>
        <li>
            <a class="nav-link<?php if($current_page == 'generate.php') echo ' active'; ?>" href="<?= htmlspecialchars($report_link) ?>">
                <i class="fa fa-chart-line"></i> Report
            </a>
        </li>
        <li>
            <a class="nav-link<?php if($current_page == 'notify.php') echo ' active'; ?>" href="<?= htmlspecialchars($notify_link) ?>">
                <i class="fa fa-bell"></i> Notifications
            </a>
        </li>

        <?php // --- Conditional Links for Admin (Dean) ONLY --- ?>
        <?php if ($role === 'admin'): ?>
        <li>
            <a class="nav-link<?php if($current_page == 'manage_users.php') echo ' active'; ?>" href="<?= htmlspecialchars($manage_users_link) ?>">
                <i class="fa fa-users-cog"></i> Users
            </a>
        </li>
        <li>
            <a class="nav-link<?php if($current_page == 'assign_faculty.php') echo ' active'; ?>" href="<?= htmlspecialchars($assign_faculty_link) ?>">
                <i class="fa fa-chalkboard-teacher"></i> Assignments <?php // Using "Assignments" as shorter name ?>
            </a>
        </li>
        <?php endif; ?>
        <?php // ---------------------------------------------- ?>

        <li>
            <a class="nav-link" href="<?= htmlspecialchars($logout_link) ?>">
                <i class="fa fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
    <div class="sidebar-footer" style="margin-top:auto;width:100%;font-size:0.7rem;color:#d32f2f;text-align:center;padding-top:1.5rem;border-top:1px solid #e4e7ed;font-weight:BOLD;">
        &copy; <?= date("Y") ?> Gautam Buddha University
    </div>
</nav>

<!-- Styles and Font Awesome (Copied directly from your code, no changes) -->
<style>
    body { font-family: 'Helvetica', Arial, sans-serif; }
    .main-content { margin-left: 230px; padding: 2.5rem 3vw 2rem 3vw; } /* Keep original margin */
    .nav-link {
        color: #23272b !important; /* black */
        background: none;
        margin: 0.4rem 0;
        font-size: 1.08rem;
        border-radius: 0.8rem;
        display: flex;
        align-items: center;
        width: 100%;
        padding: 0.7rem 1rem;
        font-weight: 500;
        letter-spacing: 0.01em;
        text-decoration: none;
        transition: background 0.2s, color 0.2s;
    }
    .nav-link i {
        margin-right: 1rem;
        font-size: 1.2rem;
        opacity: 0.7;
        width: 20px; /* Fixed width */
        text-align: center;
    }
    .nav-link.active, .nav-link:focus {
        background: #ffe5e5 !important; /* light red */
        color: #000000 !important;
        font-weight: 600;
        box-shadow: 0 2px 8px rgba(211,47,47,0.04);
    }
     .nav-link.active i, .nav-link:focus i {
         opacity: 1.0;
     }
    .nav-link:hover {
        background: #f6f8fa;
        color: #d32f2f !important;
    }
    .nav-link:hover i {
        opacity: 1.0;
    }
    ul.nav { padding-left: 0; }
    ul.nav li { list-style: none; }
    .sidebar-title { text-align: center; width: 100%; } /* Added width 100% */
    .sidebar-footer { color: #b0b8c1; }
     @media (max-width: 767px) { /* Minimal responsive adjustment if needed */
        .main-content { margin-left: 0; padding: 1rem; }
        .sidebar {
              width: 230px; /* Keep original width unless you add toggle logic */
        }
    }
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

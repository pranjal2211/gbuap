<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../auth/login.php');
    exit;
}
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'] ?? null;

require '../../config/db.php';
include '../sidebar.php';

$theme = $_SESSION['theme'] ?? 'light';
$msg = '';
$send_form_msg = '';
$students = $teachers = $hods = $parents = $deans = [];

// Fetch users for dropdowns
try {
    if ($role === 'admin') {
        $hods = $pdo->query("SELECT id, username FROM users WHERE role='hod' ORDER BY username ASC")->fetchAll();
        $teachers = $pdo->query("SELECT id, username FROM users WHERE role='teacher' ORDER BY username ASC")->fetchAll();
        $students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username ASC")->fetchAll();
        $parents = $pdo->query("SELECT id, username FROM users WHERE role='parent' ORDER BY username ASC")->fetchAll();
    } elseif ($role === 'hod') {
        $deans = $pdo->query("SELECT id, username FROM users WHERE role='admin' ORDER BY username ASC")->fetchAll();
        $students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username ASC")->fetchAll();
        $parents = $pdo->query("SELECT id, username FROM users WHERE role='parent' ORDER BY username ASC")->fetchAll();
        // Only teachers in HOD's department, but for "all" only, not specific
        if ($department_id !== null && is_numeric($department_id)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role='teacher' AND department_id = ? ORDER BY username ASC");
            $stmt->execute([$department_id]);
            $teacher_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $teacher_ids = [];
        }
    } elseif ($role === 'teacher') {
        $students = $pdo->query("SELECT id, username FROM users WHERE role='student' ORDER BY username ASC")->fetchAll();
        if ($department_id !== null && is_numeric($department_id)) {
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE role='hod' AND department_id = ? ORDER BY username ASC");
            $stmt->execute([$department_id]);
            $hods = $stmt->fetchAll();
        } else {
            $hods = [];
        }
    } elseif ($role === 'student' || $role === 'parent') {
        $teachers = $pdo->query("SELECT id, username FROM users WHERE role='teacher' ORDER BY username ASC")->fetchAll();
    }
} catch (PDOException $e) {
    $msg = "<div class='alert alert-danger'>Error fetching user lists: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// --- Handle Sending Notification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $recipient_ids = $_POST['recipient_ids'] ?? [];
    $message = trim($_POST['message']);
    $type = $_POST['type'] ?? 'custom';
    $sender_id = $user_id;
    $sender_role = $role;
    $success_count = 0;
    $error = false;

    if (empty($message)) {
        $send_form_msg = "<div class='alert alert-warning'>Message cannot be empty.</div>";
    } elseif (empty($recipient_ids)) {
        $send_form_msg = "<div class='alert alert-warning'>Please select at least one recipient.</div>";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, sender_role, message, type) VALUES (?, ?, ?, ?, ?)");
            foreach ($recipient_ids as $rid) {
                // Dean logic
                if ($role === 'admin') {
                    if ($rid === 'all_hods') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='hod'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { if ($uid != $sender_id) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; } }
                    } elseif ($rid === 'all_teachers') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='teacher'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { if ($uid != $sender_id) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; } }
                    } elseif ($rid === 'all_students') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='student'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    } elseif ($rid === 'all_parents') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='parent'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    } elseif (is_numeric($rid)) { // Only specific HODs
                        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='hod'");
                        $stmt_check->execute([$rid]);
                        if ($stmt_check->fetchColumn()) {
                            $stmt->execute([$rid, $sender_id, $sender_role, $message, $type]); $success_count++;
                        }
                    }
                }
                // HOD logic
                elseif ($role === 'hod') {
                    if ($rid === 'all_teachers') {
                        foreach ($teacher_ids as $uid) {
                            $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]);
                            $success_count++;
                        }
                    } elseif ($rid === 'all_students') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='student'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    } elseif ($rid === 'all_parents') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='parent'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    } elseif ($rid === 'dean') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    }
                    // No specific teacher option for HOD
                }
                // Teacher logic (unchanged)
                elseif ($role === 'teacher') {
                    if ($rid === 'all_students') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='student'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    } elseif ($rid === 'hod') {
                        if ($department_id !== null && is_numeric($department_id)) {
                            $stmt_hod = $pdo->prepare("SELECT id FROM users WHERE role='hod' AND department_id = ?");
                            $stmt_hod->execute([$department_id]);
                            $recipients = $stmt_hod->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                        }
                    } elseif (is_numeric($rid)) { // Only specific student
                        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student'");
                        $stmt_check->execute([$rid]);
                        if ($stmt_check->fetchColumn()) {
                            $stmt->execute([$rid, $sender_id, $sender_role, $message, $type]); $success_count++;
                        }
                    }
                }
                // Student logic (unchanged)
                elseif ($role === 'student') {
                    if ($rid === 'all_teachers') {
                        $recipients = $pdo->query("SELECT id FROM users WHERE role='teacher'")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($recipients as $uid) { $stmt->execute([$uid, $sender_id, $sender_role, $message, $type]); $success_count++; }
                    } elseif (is_numeric($rid)) { // Only specific teacher
                        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='teacher'");
                        $stmt_check->execute([$rid]);
                        if ($stmt_check->fetchColumn()) {
                            $stmt->execute([$rid, $sender_id, $sender_role, $message, $type]); $success_count++;
                        }
                    }
                }
                // Parent logic (unchanged)
                elseif ($role === 'parent') {
                    if (is_numeric($rid)) {
                        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='teacher'");
                        $stmt_check->execute([$rid]);
                        if ($stmt_check->fetchColumn()) {
                            $stmt->execute([$rid, $sender_id, $sender_role, $message, $type]); $success_count++;
                        }
                    }
                }
            }
            $send_form_msg = "<div class='alert alert-success'>Notification sent to $success_count user(s).</div>";
        } catch (PDOException $e) {
            $send_form_msg = "<div class='alert alert-danger'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8fbf9; }
        .main-content { margin-left: 230px; padding: 2.5rem 3vw 2rem 3vw; min-height: 100vh; }
        .tab-btns { margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.7rem;}
        .tab-btns .btn { border-radius: 2rem; padding: 0.5rem 1.2rem; }
        .tab-btns .btn.active, .tab-btns .btn:active { background:rgb(1, 34, 245) !important; color: #fff !important; box-shadow: 0 2px 8px rgba(211,47,47,0.08); }
        .tab-content { background: #fff; border-radius: 1.2rem; padding: 1.5rem; box-shadow: 0 4px 16px rgba(0,0,0,0.04); margin-bottom: 1.5rem;}
        .notif-list { list-style:none; padding:0; margin:0; }
        .notif-list li { background: #fff; border-radius: 0.7rem; box-shadow: 0 2px 8px rgba(211,47,47,0.07); margin-bottom: 1rem; padding: 0.9rem 1.2rem; border-left: 4px solid #e0e0e0; transition: background 0.2s; position: relative; }
        .notif-list li.unread { background: #ffeaea; border-left-color:rgb(0, 29, 248); }
        .notif-list li.notif-info { background: #e3f2fd; border-left-color: #1e88e5; font-style: italic; color: #555; }
        .notif-list li.notif-empty { background: none; box-shadow: none; border: none; padding: 1rem 0; }
        .notif-time { font-size: 0.85rem; color: #777; position: absolute; top: 0.5rem; right: 1rem; }
        @media (max-width: 767px) {
            .main-content { margin-left: 0; padding: 1rem; }
            .tab-btns .btn { flex-grow: 1; }
            .notif-list li { padding: 0.7rem 1rem; }
            .notif-time { position: static; display: block; margin-top: 0.3rem; margin-left: 0; text-align: right; }
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">
<div class="main-content">
    <h2 class="mb-4" style="color:#d32f2f;font-weight:700;">Notifications</h2>
    <?php if (!empty($msg)) echo $msg; ?>
    <?php if (!empty($send_form_msg)) echo $send_form_msg; ?>
    <div class="tab-btns">
        <button class="btn btn-outline-danger active" id="sendTabBtn" onclick="showTab('send')">Send</button>
        <button class="btn btn-outline-danger" id="receivedTabBtn" onclick="showTab('received')">Received</button>
        <button class="btn btn-outline-danger" id="sentTabBtn" onclick="showTab('sent')">Sent</button>
    </div>
    <!-- SEND TAB -->
    <div class="tab-content" id="sendTab">
        <h4>Send Notification</h4>
        <form method="post">
            <input type="hidden" name="send_notification" value="1">
            <div class="mb-3">
                <label class="form-label fw-bold">Recipient(s)</label>
                <select class="form-select" name="recipient_ids[]" multiple required>
                    <option value="">Select...</option>
                    <?php if ($role === 'admin'): ?>
                        <option value="all_hods">All HODs</option>
                        <option value="all_teachers">All Teachers</option>
                        <option value="all_students">All Students</option>
                        <option value="all_parents">All Parents</option>
                        <optgroup label="Specific HOD">
                            <?php foreach($hods as $h): ?>
                                <option value="<?= $h['id'] ?>">HOD: <?= htmlspecialchars($h['username']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php elseif ($role === 'hod'): ?>
                        <option value="all_teachers">All Teachers</option>
                        <option value="all_students">All Students</option>
                        <option value="all_parents">All Parents</option>
                        <option value="dean">Dean</option>
                        <!-- No specific teacher option for HOD -->
                    <?php elseif ($role === 'teacher'): ?>
                        <option value="all_students">All Students</option>
                        <option value="hod">HOD (of your dept)</option>
                        <optgroup label="Specific Student">
                            <?php foreach($students as $s): ?>
                                <option value="<?= $s['id'] ?>">Student: <?= htmlspecialchars($s['username']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php elseif ($role === 'student'): ?>
                        <option value="all_teachers">All Teachers</option>
                        <optgroup label="Specific Teacher">
                            <?php foreach($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>">Teacher: <?= htmlspecialchars($t['username']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php elseif ($role === 'parent'): ?>
                        <optgroup label="Specific Teacher">
                            <?php foreach($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>">Teacher: <?= htmlspecialchars($t['username']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <small class="text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple.</small>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Message</label>
                <textarea name="message" class="form-control" rows="3" placeholder="Enter your message..." required></textarea>
            </div>
            <button class="btn btn-danger" type="submit">Send Notification</button>
        </form>
    </div>
    <!-- RECEIVED TAB -->
    <div class="tab-content" id="receivedTab" style="display:none;">
        <h4>Received Notifications</h4>
        <ul class="notif-list" id="received-list"><li class="notif-empty">Loading...</li></ul>
    </div>
    <!-- SENT TAB -->
    <div class="tab-content" id="sentTab" style="display:none;">
        <h4>Sent Notifications</h4>
        <ul class="notif-list" id="sent-list"><li class="notif-empty">Loading...</li></ul>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        let currentTab = 'send';
        function showTab(tab) {
            currentTab = tab;
            $('#sendTab').toggle(tab === 'send');
            $('#receivedTab').toggle(tab === 'received');
            $('#sentTab').toggle(tab === 'sent');
            $('#sendTabBtn').toggleClass('active', tab === 'send');
            $('#receivedTabBtn').toggleClass('active', tab === 'received');
            $('#sentTabBtn').toggleClass('active', tab === 'sent');
            if (tab === 'received') loadNotifications('received');
            if (tab === 'sent') loadNotifications('sent');
        }
        function loadNotifications(mode) {
            if (mode === 'received') {
                $.get('fetch_notifications.php?mode=received', function(data) { $('#received-list').html(data); })
                    .fail(function() { $('#received-list').html('<li class="notif-empty text-danger">Error loading notifications.</li>'); });
            } else if (mode === 'sent') {
                $.get('fetch_notifications.php?mode=sent', function(data) { $('#sent-list').html(data); })
                    .fail(function() { $('#sent-list').html('<li class="notif-empty text-danger">Error loading sent notifications.</li>'); });
            }
        }
        $(document).ready(function(){
            showTab('send');
        });
    </script>
</div>
</body>
</html>

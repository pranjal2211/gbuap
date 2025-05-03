<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../auth/login.php');
    exit;
}
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
require '../../config/db.php';
include '../sidebar.php';

$theme = $_SESSION['theme'] ?? 'light';
$date = date("l, F j, Y");
$student_id = null;
$display_username = $username;
$page_error = null;
$subjects = [];
$attendance_data = [];
$trend_labels = [];
$bar_chart_data = [];

if ($role === 'student') {
    $student_id = $user_id;
} elseif ($role === 'parent') {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE parent_id = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$user_id]);
    $child = $stmt->fetch();
    if ($child) {
        $student_id = $child['id'];
        $display_username = $username . " (Viewing for " . htmlspecialchars($child['username']) . ")";
    } else {
        $page_error = "No student record linked to this parent account.";
    }
} else {
    header('Location: ../auth/logout.php');
    exit;
}

if ($student_id && !$page_error) {
    // Get all subjects for which this student has attendance records
    $stmt_subjects = $pdo->prepare("
        SELECT DISTINCT s.id, s.name 
        FROM attendance a
        JOIN subjects s ON a.subject_id = s.id
        WHERE a.student_id = ?
        ORDER BY s.name ASC
    ");
    $stmt_subjects->execute([$student_id]);
    $subjects_arr = $stmt_subjects->fetchAll(PDO::FETCH_ASSOC);
    $subjects = [];
    foreach ($subjects_arr as $row) {
        $subjects[$row['id']] = $row['name'];
    }

    // KPIs and trends
    $current_date_sql = date('Y-m-d');
    foreach ($subjects as $sid => $subject) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ?");
        $stmt->execute([$student_id, $sid]);
        $classes_held = $stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND status = 'present'");
        $stmt->execute([$student_id, $sid]);
        $present = $stmt->fetchColumn() ?: 0;

        $semester = ($classes_held > 0) ? round(($present / $classes_held) * 100) : 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND YEARWEEK(date, 1) = YEARWEEK(?, 1)");
        $stmt->execute([$student_id, $sid, $current_date_sql]);
        $week_classes_held = $stmt->fetchColumn() ?: 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND status = 'present' AND YEARWEEK(date, 1) = YEARWEEK(?, 1)");
        $stmt->execute([$student_id, $sid, $current_date_sql]);
        $week_present = $stmt->fetchColumn() ?: 0;
        $week = ($week_classes_held > 0) ? round(($week_present / $week_classes_held) * 100) : 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?");
        $stmt->execute([$student_id, $sid, $current_date_sql]);
        $today_classes_held_subj = $stmt->fetchColumn() ?: 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND status = 'present' AND date = ?");
        $stmt->execute([$student_id, $sid, $current_date_sql]);
        $today_present_subj = $stmt->fetchColumn() ?: 0;
        $today = ($today_classes_held_subj > 0) ? round(($today_present_subj / $today_classes_held_subj) * 100) : 0;

        $classes_today = $today_classes_held_subj > 0 ? 1 : 0;

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days", strtotime($current_date_sql)));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?");
            $stmt->execute([$student_id, $sid, $day]);
            $held = $stmt->fetchColumn() ?: 0;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND subject_id = ? AND status = 'present' AND date = ?");
            $stmt->execute([$student_id, $sid, $day]);
            $present = $stmt->fetchColumn() ?: 0;
            $trend[] = ($held > 0) ? round(($present / $held) * 100) : 0;
        }

        $attendance_data[$subject] = [
            "semester" => $semester, "today" => $today, "week" => $week,
            "classes_today" => $classes_today, "trend" => $trend
        ];
        $bar_chart_data[$subject] = $semester;
    }

    // Aggregate "All Subjects"
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $total_classes_held = $stmt->fetchColumn() ?: 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = 'present'");
    $stmt->execute([$student_id]);
    $total_present = $stmt->fetchColumn() ?: 0;
    $semester = ($total_classes_held > 0) ? round(($total_present / $total_classes_held) * 100) : 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND YEARWEEK(date, 1) = YEARWEEK(?, 1)");
    $stmt->execute([$student_id, $current_date_sql]);
    $week_classes_held = $stmt->fetchColumn() ?: 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = 'present' AND YEARWEEK(date, 1) = YEARWEEK(?, 1)");
    $stmt->execute([$student_id, $current_date_sql]);
    $week_present = $stmt->fetchColumn() ?: 0;
    $week = ($week_classes_held > 0) ? round(($week_present / $week_classes_held) * 100) : 0;

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT subject_id) FROM attendance WHERE student_id = ? AND date = ?");
    $stmt->execute([$student_id, $current_date_sql]);
    $subjects_today_count = $stmt->fetchColumn() ?: 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = 'present' AND date = ?");
    $stmt->execute([$student_id, $current_date_sql]);
    $today_present_agg = $stmt->fetchColumn() ?: 0;
    $today = ($subjects_today_count > 0) ? round(($today_present_agg / $subjects_today_count) * 100) : 0;

    $agg_trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days", strtotime($current_date_sql)));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND date = ?");
        $stmt->execute([$student_id, $day]);
        $held = $stmt->fetchColumn() ?: 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = 'present' AND date = ?");
        $stmt->execute([$student_id, $day]);
        $present = $stmt->fetchColumn() ?: 0;
        $agg_trend[] = ($held > 0) ? round(($present / $held) * 100) : 0;
    }

    $attendance_data['All Subjects'] = [
        "semester" => $semester,
        "today" => $today,
        "week" => $week,
        "classes_today" => $subjects_today_count,
        "trend" => $agg_trend
    ];

    for ($i = 6; $i >= 0; $i--) {
        $trend_labels[] = date('M d', strtotime("-$i days", strtotime($current_date_sql)));
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f8fbf9; }
        .main-content { margin-left: 230px; min-height: 100vh; padding: 2.5rem 3vw 2rem 3vw;}
        .greeting { font-size: 2rem; font-weight: 700; color:rgb(23, 6, 254); }
        .date { font-size: 1.1rem; color: #8a8a8a; }
        .subject-dropdown { min-width: 220px; }
        .cards-row { display: flex; gap: 1.6rem; margin-bottom: 2.5rem; }
        .stat-card { background: #fff; border-radius: 1.2rem; box-shadow: 0 2px 12px 0 rgba(0,0,0,0.04); padding: 1.5rem 1.3rem 1.3rem 1.3rem; min-width: 180px; flex: 1 1 0; display: flex; flex-direction: column; align-items: flex-start; }
        .stat-card .card-title { font-size: 1.03rem; font-weight: 500; color: #8a8a8a; margin-bottom: 0.8rem; letter-spacing: 0.01em; }
        .stat-card .card-value { font-size: 2.4rem; font-weight: 700; color:rgb(19, 3, 245); display: flex; align-items: baseline; }
        .stat-card .percent-sign { font-size: 1.2rem; margin-left: 0.18em; color:rgb(26, 10, 252); font-weight: 600; }
        .trend-row { display: flex; gap: 1.6rem; }
        .widget { background: #fff; border-radius: 1.2rem; box-shadow: 0 2px 12px 0 rgba(0,0,0,0.04); padding: 1.8rem 1.5rem; min-width: 320px; flex: 1 1 0; display: flex; flex-direction: column; }
        .widget-header { font-size: 1.13rem; font-weight: 700; color:rgb(9, 24, 243); margin-bottom: 1.2rem; }
        @media (max-width: 1200px) { .cards-row, .trend-row { flex-direction: column; gap: 1.2rem; } }
        @media (max-width: 767px) { .main-content { margin-left: 0; padding: 1rem; } .stat-card { min-width: 160px; } }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">
<div class="main-content">
    <?php if ($page_error): ?>
        <div class="alert alert-danger"><?= $page_error ?></div>
    <?php elseif ($student_id): ?>
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <div class="greeting">Welcome <?= htmlspecialchars($display_username) ?>!</div>
                <div class="date"><?= $date ?></div>
            </div>
            <div>
                <select id="subjectDropdown" class="form-select subject-dropdown">
                    <option value="All Subjects">All Subjects</option>
                    <?php foreach(array_keys($attendance_data) as $subject): ?>
                        <?php if ($subject !== 'All Subjects'): ?>
                            <option value="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars($subject) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="cards-row">
            <div class="stat-card">
                <div class="card-title">Semester Attendance</div>
                <div class="card-value" id="semesterAtt"><?= $attendance_data['All Subjects']['semester'] ?? 0 ?><span class="percent-sign">%</span></div>
            </div>
            <div class="stat-card">
                <div class="card-title">This Week</div>
                <div class="card-value" id="weekAtt"><?= $attendance_data['All Subjects']['week'] ?? 0 ?><span class="percent-sign">%</span></div>
            </div>
            <div class="stat-card">
                <div class="card-title">Today</div>
                <div class="card-value" id="todayAtt"><?= $attendance_data['All Subjects']['today'] ?? 0 ?><span class="percent-sign">%</span></div>
            </div>
            <div class="stat-card">
                <div class="card-title">Classes Today</div>
                <div class="card-value" id="classesToday"><?= $attendance_data['All Subjects']['classes_today'] ?? 0 ?></div>
            </div>
        </div>
        <div class="trend-row">
            <div class="widget">
                <div class="widget-header">Attendance Trend (Last 7 Days)</div>
                <canvas id="attendanceTrendChart" style="height: 180px;"></canvas>
            </div>
            <div class="widget">
                <div class="widget-header">Attendance % by Subject</div>
                <canvas id="attendanceBarChart" style="height: 180px;"></canvas>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No attendance data available to display for this user.</div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const attendanceData = <?= json_encode($attendance_data ?? ['All Subjects' => ['semester'=>0, 'today'=>0, 'week'=>0, 'classes_today'=>0, 'trend'=>array_fill(0,7,0)]]) ?>;
    const trendLabels = <?= json_encode($trend_labels ?? []) ?>;
    const barChartData = <?= json_encode($bar_chart_data ?? []) ?>;
    const subjectDropdown = document.getElementById('subjectDropdown');
    const semesterAttEl = document.getElementById('semesterAtt');
    const todayAttEl = document.getElementById('todayAtt');
    const weekAttEl = document.getElementById('weekAtt');
    const classesTodayEl = document.getElementById('classesToday');
    let trendChart, barChart;

    function renderTrend(subject) {
        const safeSubject = attendanceData[subject] ? subject : 'All Subjects';
        const data = attendanceData[safeSubject]['trend'];
        if (trendChart) trendChart.destroy();
        const ctx = document.getElementById('attendanceTrendChart');
        if (!ctx) return;
        trendChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: subject,
                    data: data,
                    borderColor: '#d32f2f',
                    backgroundColor: 'rgba(211,47,47,0.09)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#d32f2f'
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: '#e0e0e0' }, beginAtZero: true, max: 100 }
                }
            }
        });
    }

    function renderBarChart() {
        if (barChart) barChart.destroy();
        const ctx = document.getElementById('attendanceBarChart');
        if (!ctx) return;
        barChart = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: Object.keys(barChartData),
                datasets: [{
                    label: 'Semester Attendance %',
                    data: Object.values(barChartData),
                    backgroundColor: '#d32f2f'
                }]
            },
            options: {
                plugins: { legend: { display: false }},
                scales: {
                    x: { grid: { display: false }},
                    y: { beginAtZero: true, max: 100, grid: { color: '#e0e0e0' }}
                }
            }
        });
    }

    function updateSubjectDetails(subject) {
        const safeSubject = attendanceData[subject] ? subject : 'All Subjects';
        const stats = attendanceData[safeSubject];
        if (semesterAttEl) semesterAttEl.innerHTML = stats['semester'] + '<span class="percent-sign">%</span>';
        if (todayAttEl) todayAttEl.innerHTML = stats['today'] + '<span class="percent-sign">%</span>';
        if (weekAttEl) weekAttEl.innerHTML = stats['week'] + '<span class="percent-sign">%</span>';
        if (classesTodayEl) classesTodayEl.textContent = stats['classes_today'];
        renderTrend(safeSubject);
        if (subject === 'All Subjects') renderBarChart();
        else if (barChart) barChart.destroy();
    }

    if (subjectDropdown) {
        subjectDropdown.addEventListener('change', function() {
            updateSubjectDetails(this.value);
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        if (attendanceData['All Subjects']) {
            updateSubjectDetails('All Subjects');
        }
    });
</script>
</body>
</html>

<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    header('Location: ../auth/login.php');
    exit;
}
$theme = $_SESSION['theme'] ?? 'light';
require '../../config/db.php';
include '../sidebar.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$date = date("l, F j, Y");

// Fetch all subjects taught by this teacher (subject_id and name)
$stmt = $pdo->prepare(
    "SELECT DISTINCT s.id, s.name 
    FROM attendance a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.marked_by = ?"
);
$stmt->execute([$user_id]);
$subjects_arr = $stmt->fetchAll(PDO::FETCH_ASSOC);

$subjects = [];
foreach ($subjects_arr as $row) {
    $subjects[$row['id']] = $row['name'];
}

// Fallback if no attendance yet
if (empty($subjects)) {
    $subjects = [0 => "No Subjects"];
}

// KPIs and trends
$teacher_subjects = [];
$trend_labels = [];
for ($i = 29; $i >= 0; $i--) $trend_labels[] = date('M d', strtotime("-$i days"));

foreach ($subjects as $sid => $subject) {
    // Unique students for this subject
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM attendance WHERE subject_id = ? AND marked_by = ?");
    $stmt->execute([$sid, $user_id]);
    $students = $stmt->fetchColumn();

    // Classes conducted today
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT date) FROM attendance WHERE subject_id = ? AND marked_by = ? AND date = CURDATE()");
    $stmt->execute([$sid, $user_id]);
    $classes_today = $stmt->fetchColumn();

    // Attendance marked today - only present students
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE subject_id = ? AND marked_by = ? AND date = CURDATE() AND status = 'present'");
    $stmt->execute([$sid, $user_id]);
    $attendance_marked = $stmt->fetchColumn();

    // Average attendance %
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present FROM attendance WHERE subject_id = ? AND marked_by = ?");
    $stmt->execute([$sid, $user_id]);
    $row = $stmt->fetch();
    $avg_attendance = ($row['total'] > 0) ? round(($row['present'] / $row['total']) * 100) : 0;

    // Trend (last 30 days)
    $trend = [];
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present FROM attendance WHERE subject_id = ? AND marked_by = ? AND date = ?");
        $stmt->execute([$sid, $user_id, $day]);
        $row = $stmt->fetch();
        $trend[] = ($row['total'] > 0) ? round(($row['present'] / $row['total']) * 100) : 0;
    }

    $teacher_subjects[$subject] = [
        "students" => $students,
        "classes_today" => $classes_today,
        "attendance_marked" => $attendance_marked,
        "avg_attendance" => $avg_attendance,
        "trend" => $trend
    ];
}
$teacher_subject_names = array_keys($teacher_subjects);

// Calculate overall KPIs for all subjects
function overall($teacher_subjects, $field) {
    $sum = 0;
    foreach ($teacher_subjects as $s) $sum += $s[$field];
    return $sum;
}
function overall_avg($teacher_subjects, $field) {
    $sum = 0; $count = 0;
    foreach ($teacher_subjects as $s) { $sum += $s[$field]; $count++; }
    return $count ? round($sum / $count) : 0;
}

// Top 5 student performers (across all subjects)
$stmt = $pdo->prepare("
    SELECT u.username, ROUND(SUM(a.status = 'present')/COUNT(*)*100,1) as percent
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    WHERE a.marked_by = ?
    GROUP BY a.student_id
    HAVING COUNT(*) > 0
    ORDER BY percent DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$top_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 & FontAwesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f6f8fa; font-family: 'Helvetica', Arial, sans-serif; }
        .main-content { margin-left: 230px; padding: 2.5rem 3vw 2rem 3vw;}
        .top-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;}
        .greeting { font-size: 2rem; font-weight: 700; color:rgb(0, 17, 253); margin-bottom: 0.2rem;}
        .date { font-size: 1.05rem; color: #888; margin-bottom: 1.5rem;}
        .subject-dropdown { min-width: 260px; font-family: 'Helvetica', Arial, sans-serif;}
        .cards-row { display: flex; gap: 1.3rem; margin-bottom: 2.3rem;}
        .stat-card { flex: 1 1 120px; background: #fff; border-radius: 1.2rem; padding: 1.2rem 1.1rem 1.1rem 1.1rem; min-width: 120px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); display: flex; flex-direction: column; align-items: flex-start; border:1px solid #e4e7ed;}
        .stat-card .card-title { font-size: 0.97rem; font-weight: 500; color: #5b5b5b; margin-bottom: 0.7rem; letter-spacing: 0.01em;}
        .stat-card .card-value { font-size: 2.1rem; font-weight: 700; color:rgb(0, 8, 251); display: flex; align-items: baseline;}
        .trend-assign-row { display: flex; gap: 1.2rem; margin-bottom: 0.5rem;}
        .widget { background: #fff; border-radius: 1.2rem; padding: 1.2rem; box-shadow: 0 4px 16px rgba(0,0,0,0.04); margin-bottom: 1.2rem; flex: 1 1 0; border:1px solid #e4e7ed;}
        .widget-header { font-size: 1.1rem; font-family: 'Helvetica', Arial, sans-serif; font-weight: 600; margin-bottom: 1.2rem; color:rgb(25, 0, 251);}
        @media (max-width: 1200px) { .cards-row { flex-direction: column; } .trend-assign-row { flex-direction: column; } }
        @media (max-width: 767px) { .main-content { padding: 1rem; } .stat-card { min-width: 160px; } }
    </style>
</head>
<body>
<div class="main-content">
    <div class="top-row">
        <div>
            <div class="greeting">Welcome <?= htmlspecialchars($username) ?>!</div>
            <div class="date"><?= $date ?></div>
        </div>
        <div>
            <select id="subjectDropdown" class="form-select subject-dropdown">
                <option value="all_subjects">All Subjects</option>
                <?php foreach(array_keys($teacher_subjects) as $subject): ?>
                    <option value="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars($subject) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="cards-row">
        <div class="stat-card">
            <div class="card-title">Total Students</div>
            <div class="card-value" id="studentsCard"><?= overall($teacher_subjects, 'students') ?></div>
        </div>
        <div class="stat-card">
            <div class="card-title">Classes Conducted Today</div>
            <div class="card-value" id="classesCard"><?= overall($teacher_subjects, 'classes_today') ?></div>
        </div>
        <div class="stat-card">
            <div class="card-title">Attendance Marked Today (Present)</div>
            <div class="card-value" id="attendanceCard"><?= overall($teacher_subjects, 'attendance_marked') ?></div>
        </div>
        <div class="stat-card">
            <div class="card-title">Average Attendance %</div>
            <div class="card-value" id="attendanceAvgCard"><?= overall_avg($teacher_subjects, 'avg_attendance') ?>%</div>
        </div>
    </div>
    <div class="trend-assign-row">
        <div class="widget" style="min-width:320px;">
            <div class="widget-header">Attendance Trend (Last 30 Days)</div>
            <canvas id="attendanceTrendChart" style="height: 180px;"></canvas>
        </div>
        <div class="widget" style="min-width:320px;">
            <div class="widget-header">Top 5 Student Performers</div>
            <canvas id="topStudentsChart" style="height: 180px;"></canvas>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const teacherSubjects = <?= json_encode($teacher_subjects) ?>;
    const teacherSubjectNames = <?= json_encode(array_keys($teacher_subjects)) ?>;
    const trendLabels = <?= json_encode($trend_labels) ?>;
    const topStudents = <?= json_encode($top_students) ?>;
    let trendChart, barChart;

    function combineStats() {
        let totalStudents = 0, totalClasses = 0, totalAttendance = 0, totalAvg = 0, count = 0;
        let trend = Array(trendLabels.length).fill(0);
        teacherSubjectNames.forEach((subj) => {
            totalStudents += parseInt(teacherSubjects[subj]['students']);
            totalClasses += parseInt(teacherSubjects[subj]['classes_today']);
            totalAttendance += parseInt(teacherSubjects[subj]['attendance_marked']);
            totalAvg += parseInt(teacherSubjects[subj]['avg_attendance']);
            count++;
            teacherSubjects[subj]['trend'].forEach((val, i) => {
                trend[i] += parseInt(val);
            });
        });
        trend = trend.map(val => count > 0 ? Math.round(val / count) : 0);
        return {
            students: totalStudents,
            classes_today: totalClasses,
            attendance_marked: totalAttendance,
            avg_attendance: count ? Math.round(totalAvg / count) : 0,
            trend: trend
        };
    }

    function renderTrend(subject) {
        let data, label;
        if (subject === 'all_subjects') {
            data = combineStats().trend;
            label = "All Subjects";
        } else {
            data = teacherSubjects[subject]['trend'];
            label = subject;
        }
        if (trendChart) trendChart.destroy();
        const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: label,
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
                plugins: { legend: { display: false }},
                scales: {
                    x: { grid: { display: false }},
                    y: { grid: { color: '#e0e0e0' }, beginAtZero: false }
                }
            }
        });
    }

    function renderTopStudents() {
        if (barChart) barChart.destroy();
        const ctx = document.getElementById('topStudentsChart').getContext('2d');
        barChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: topStudents.map(s => s.username),
                datasets: [{
                    label: 'Attendance %',
                    data: topStudents.map(s => s.percent),
                    backgroundColor: '#d32f2f'
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false }},
                scales: {
                    x: { beginAtZero: true, max: 100, grid: { color: '#e0e0e0' }},
                    y: { grid: { display: false }}
                }
            }
        });
    }

    function updateKPIs(subject) {
        let stats;
        if (subject === 'all_subjects') {
            stats = combineStats();
        } else {
            stats = teacherSubjects[subject];
        }
        document.getElementById('studentsCard').textContent = stats.students;
        document.getElementById('classesCard').textContent = stats.classes_today;
        document.getElementById('attendanceCard').textContent = stats.attendance_marked;
        document.getElementById('attendanceAvgCard').textContent = stats.avg_attendance + '%';
        renderTrend(subject);
    }

    document.getElementById('subjectDropdown').addEventListener('change', function() {
        updateKPIs(this.value);
    });

    // Initial render
    updateKPIs('all_subjects');
    renderTopStudents();
</script>
</body>
</html>

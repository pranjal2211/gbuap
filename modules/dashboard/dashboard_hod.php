<?php
// --- Error Reporting & Session ---
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();
// -------------------------------

// --- Check Login & Role (MUST be HOD) ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'hod') { header('Location: ../auth/login.php'); exit; }
$hod_user_id = $_SESSION['user_id'];
// ------------------------------------------

// --- Variables & DB Connection ---
$theme = $_SESSION['theme'] ?? 'light'; $language = $_SESSION['language'] ?? 'en';
$hod_name = $_SESSION['username'] ?? "HOD User"; $date = date("l, F j, Y"); // Format like image
require '../../config/db.php'; if (!isset($pdo)) die("Database connection failed.");
include '../sidebar.php';
// -----------------------------

// --- Data Initialization ---
$hod_department_id = null; $hod_department_name = "N/A";
$overall_dept_percentage = 0; $total_dept_records = 0;
$program_summary = []; // Keyed by Program Name, will be sorted
$program_trend_data = []; $trend_labels = [];
$top_student_labels_abbr = []; $top_student_values = []; $top_student_details = [];
$error_msg = null;

// --- Helper Function ---
function getAbbreviation($name) { $words = explode(' ', trim($name)); $abbr = ''; $count = 0; foreach ($words as $word) { if (!empty($word) && $count < 3) { $abbr .= strtoupper($word[0]); $count++; } } return $abbr ?: 'N/A'; }
// ------------------------------------

// --- Fetch HOD's Department & Data ---
try {
    $stmt_hod_dept = $pdo->prepare("SELECT d.id, d.name FROM users u JOIN departments d ON u.department_id = d.id WHERE u.id = ? AND u.role = 'hod'");
    $stmt_hod_dept->execute([$hod_user_id]); $hod_dept_info = $stmt_hod_dept->fetch(PDO::FETCH_ASSOC);
    if (!$hod_dept_info || !$hod_dept_info['id']) throw new Exception("HOD profile not associated with a department.");
    $hod_department_id = $hod_dept_info['id']; $hod_department_name = $hod_dept_info['name'];

    // --- Fetch KPIs ---
    $stmt_overall_dept = $pdo->prepare("SELECT COUNT(a.id) as total_records, SUM(a.status = 'present') as present_count FROM attendance a JOIN sections sec ON a.section_id = sec.id JOIN programs p ON sec.program_id = p.id WHERE p.department_id = ?");
    $stmt_overall_dept->execute([$hod_department_id]); $overall_dept_counts = $stmt_overall_dept->fetch(PDO::FETCH_ASSOC);
    $total_dept_records = $overall_dept_counts['total_records'] ?? 0; $total_dept_present = $overall_dept_counts['present_count'] ?? 0;
    $overall_dept_percentage = ($total_dept_records > 0) ? round(($total_dept_present / $total_dept_records) * 100) : 0;

    // Initialize ALL programs in the dept first
    $stmt_progs = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? ORDER BY name");
    $stmt_progs->execute([$hod_department_id]); $programs_in_dept = $stmt_progs->fetchAll(PDO::FETCH_ASSOC);
    $prog_id_to_name = array_column($programs_in_dept, 'name', 'id');
    foreach ($prog_id_to_name as $id => $name) { $program_summary[$name] = ['id' => $id, 'total' => 0, 'present' => 0, 'percentage' => 0]; $program_trend_data[$name] = array_fill(0, 30, 0); }

    // Calculate percentages ONLY for programs with attendance data
    $sql_overall_prog = "SELECT p.name as program_name, COUNT(a.id) as total_records, SUM(a.status = 'present') as present_count FROM programs p JOIN sections sec ON p.id = sec.program_id JOIN attendance a ON sec.id = a.section_id WHERE p.department_id = ? GROUP BY p.id, p.name HAVING COUNT(a.id) > 0";
    $stmt_overall_prog = $pdo->prepare($sql_overall_prog); $stmt_overall_prog->execute([$hod_department_id]);
    while ($row = $stmt_overall_prog->fetch(PDO::FETCH_ASSOC)) { $name = $row['program_name']; if (isset($program_summary[$name])) { $total = (int)$row['total_records']; $present = (int)$row['present_count']; $program_summary[$name]['total'] = $total; $program_summary[$name]['present'] = $present; $program_summary[$name]['percentage'] = ($total > 0) ? round(($present / $total) * 100) : 0; } }

    // *** SORT Programs by Percentage DESCENDING ***
    uasort($program_summary, function($a, $b) { return $b['percentage'] <=> $a['percentage']; });

    // --- Fetch Trend Data ---
    $end_date = date('Y-m-d'); $start_date = date('Y-m-d', strtotime('-29 days'));
    $sql_trend_prog = "SELECT p.name as program_name, a.date, COUNT(a.id) as total_records, SUM(a.status = 'present') as present_count FROM attendance a JOIN sections sec ON a.section_id = sec.id JOIN programs p ON sec.program_id = p.id WHERE p.department_id = ? AND a.date BETWEEN ? AND ? GROUP BY p.id, p.name, a.date ORDER BY a.date ASC, p.name ASC";
    $stmt_trend_prog = $pdo->prepare($sql_trend_prog); $stmt_trend_prog->execute([$hod_department_id, $start_date, $end_date]);
    $raw_trend_data = []; while ($row = $stmt_trend_prog->fetch(PDO::FETCH_ASSOC)) { $raw_trend_data[$row['program_name']][$row['date']] = ($row['total_records'] > 0) ? round(($row['present_count'] / $row['total_records']) * 100) : 0; }
    $current_check_date = new DateTime($start_date); $end_date_obj = new DateTime($end_date); $interval = new DateInterval('P1D'); $day_index = 0; $trend_labels = [];
    while ($current_check_date <= $end_date_obj && $day_index < 30) { $date_str = $current_check_date->format('Y-m-d'); $trend_labels[] = $date_str; foreach ($prog_id_to_name as $name) { if (!isset($program_trend_data[$name])) { $program_trend_data[$name] = array_fill(0, 30, 0); } $program_trend_data[$name][$day_index] = $raw_trend_data[$name][$date_str] ?? 0; } $current_check_date->add($interval); $day_index++; }

    // --- Fetch Top Students ---
    $sql_top_students = "SELECT u.username as student_name, sec.year as section_year, sec.section_name, (SUM(a.status = 'present') * 100.0 / COUNT(a.id)) as percentage FROM attendance a JOIN users u ON a.student_id = u.id JOIN sections sec ON u.section_id = sec.id JOIN programs p ON sec.program_id = p.id WHERE p.department_id = ? AND u.role = 'student' GROUP BY a.student_id, u.username, sec.year, sec.section_name HAVING COUNT(a.id) > 5 ORDER BY percentage DESC, COUNT(a.id) DESC LIMIT 5";
    $stmt_top_students = $pdo->prepare($sql_top_students); $stmt_top_students->execute([$hod_department_id]); $top_students = $stmt_top_students->fetchAll(PDO::FETCH_ASSOC);
    foreach ($top_students as $student) { $full_name = $student['student_name']; $top_student_labels_abbr[] = getAbbreviation($full_name); $top_student_values[] = round($student['percentage']); $top_student_details[] = ['name' => $full_name, 'year' => $student['section_year'], 'section' => $student['section_name'], 'percentage' => round($student['percentage'], 1)]; }

} catch (PDOException $e) { $error_msg = "Database Error: " . htmlspecialchars($e->getMessage()); error_log("HOD Dashboard DB Error: " . $e->getMessage());
} catch (Exception $e) { $error_msg = "Error: " . htmlspecialchars($e->getMessage()); error_log("HOD Dashboard General Error: " . $e->getMessage()); }
// ---------------------------------------

// *** Separate Top 3 Programs (based on sorted $program_summary) ***
$top_programs = array_slice($program_summary, 0, 3, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HOD Dashboard - <?= htmlspecialchars($hod_department_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
         /* --- UI Styles Inspired by Dean Dashboard Image - DEFINITELY No Shadows --- */
        :root { --gbu-red:rgb(4, 20, 251); --light-red-bg: #ffeaea; --border-color: #dee2e6; /* Slightly darker border */ --text-muted: #6c757d; --text-dark: #212529; --light-grey-bg: #f8f9fa;}
        body { background-color: var(--light-grey-bg); font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"; /* System Font Stack */ }
        .main-content { margin-left: 230px; padding: 2rem; transition: margin-left 0.2s ease-in-out; min-height: 100vh;}
        .greeting { font-size: 1.8rem; font-weight: 500; color: rgb(5, 1, 253);} /* Less bold */
        .department-info { font-size: 1rem; font-weight: 400; color: var(--text-muted); margin-bottom: 0.4rem;}
        .date { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 2rem;}

        .stat-card {
            background-color: #fff; border: 1px solid var(--border-color); border-radius: 0.375rem; /* Bootstrap default */
            padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; flex-direction: column;
            height: 100%; box-shadow: none !important; /* NO SHADOW */ min-height: 100px; /* Adjusted height */
            position: relative;
            /* Remove left border by default */
            border-left: 1px solid var(--border-color) !important;
        }
        /* Special style for overall */
        .stat-card.overall-dept { background-color: var(--light-red-bg); border-color: #f5c6cb; border-left: 1px solid #f5c6cb !important; /* Match bg border */ }

        .stat-card-title {
            font-size: 0.75rem; /* Smaller title like image */ color: var(--text-muted); font-weight: 500; /* Normal weight */
            margin-bottom: 0.5rem; /* Less space between title and value */ text-transform: uppercase; letter-spacing: 0.5px;
            line-height: 1.3; white-space: normal; overflow-wrap: break-word; word-wrap: break-word; hyphens: auto;
        }
        .stat-card-value {
            font-size: 2.5rem; /* Slightly smaller than before */ font-weight: 600; /* Slightly less bold */ color: var(--gbu-red);
            line-height: 1; text-align: left; /* VALUE LEFT */
            margin-top: auto; /* Push value to bottom */
            padding-top: 0.25rem; /* Ensure some space if title is short */
        }
        .stat-card-value .percent-sign { font-size: 1.2rem; font-weight: 500; margin-left: 0.1em; }

        .chart-container {
            background-color: #fff; border: 1px solid var(--border-color); border-radius: 0.375rem;
            padding: 1.5rem; height: 400px; /* Adjusted height */ display: flex; flex-direction: column; margin-bottom: 1.5rem;
            box-shadow: none !important; /* NO SHADOW */
        }
        .chart-header { font-size: 1.1rem; font-weight: 500; color: var(--text-dark); margin-bottom: 1rem; }
        .chart-canvas-container { flex-grow: 1; position: relative; }
        canvas { max-width: 100%; max-height: 100%; }

        @media (max-width: 991px) { .main-content { margin-left: 0; padding: 1.5rem; } }
        @media (max-width: 767px) {
             .main-content { padding: 1rem; } .chart-container { height: 320px; padding: 1rem; }
             .stat-card-value { font-size: 2rem;} .greeting {font-size: 1.5rem;}
             .stat-card { min-height: 90px; padding: 0.75rem 1rem; }
             .stat-card-title { font-size: 0.7rem; }
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">

<div class="main-content">
    <div class="mb-4">
        <div class="greeting">Welcome HOD <?= htmlspecialchars($hod_name) ?>!</div>
        <div class="department-info">Department of <strong><?= htmlspecialchars($hod_department_name) ?></strong></div>
        <div class="date"><?= $date ?></div>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= $error_msg ?></div>
    <?php else: ?>
        <?php // --- Top KPI Cards Row (Top 3 Programs Left, Overall Right) --- ?>
        <div class="row">
             <?php $col_class = 'col-xl-3 col-md-6'; $program_cards_shown = 0; ?>
             <?php // Cards 1, 2, 3 : Top 3 Program Averages ?>
             <?php if (!empty($top_programs)): ?>
                 <?php foreach ($top_programs as $prog_name => $data): ?>
                     <div class="<?= $col_class ?>">
                         <div class="card stat-card top-program h-100"> <?php // Gets red line ?>
                             <div class="stat-card-title" title="<?= htmlspecialchars($prog_name) ?> AVERAGE"><?= htmlspecialchars($prog_name) ?> AVERAGE</div>
                             <div class="stat-card-value"><?= $data['percentage'] ?><span class="percent-sign">%</span></div>
                         </div>
                     </div>
                     <?php $program_cards_shown++; ?>
                 <?php endforeach; ?>
            <?php endif; ?>
             <?php // Fill empty slots if less than 3 programs ?>
             <?php for ($i = $program_cards_shown; $i < 3; $i++): ?>
                 <div class="<?= $col_class ?>"><div class="card stat-card h-100" style="border: 1px dashed var(--border-color); background: #fdfdfd;"></div></div>
             <?php endfor; ?>
             <?php // Card 4: Overall Department Average (No Red Line Class) ?>
             <div class="<?= $col_class ?>">
                <div class="card stat-card overall-dept h-100">
                    <div class="stat-card-title">OVERALL DEPARTMENT AVERAGE</div>
                    <div class="stat-card-value"><?= $overall_dept_percentage ?><span class="percent-sign">%</span></div>
                </div>
            </div>
        </div>

        <?php // --- NO "Other Programs" Section - Removed to match image --- ?>

        <?php // --- Charts Row --- ?>
        <div class="row mt-4"> <?php // Always add margin now ?>
            <div class="col-lg-7">
                <div class="card chart-container">
                    <div class="chart-header">Course Attendance Trend (Last 30 Days)</div>
                    <div class="chart-canvas-container"><canvas id="courseTrendChart"></canvas></div>
                </div>
            </div>
            <div class="col-lg-5">
                 <div class="card chart-container">
                    <div class="chart-header">Top 5 Student Performers (Dept.)</div>
                     <div class="chart-canvas-container"><canvas id="topStudentsChart"></canvas></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // --- (JavaScript code remains the same as the previous correct version) ---
    const programTrendData = <?= json_encode($program_trend_data ?? []) ?>;
    const trendLabels = <?= json_encode($trend_labels ?? []) ?>;
    const topStudentLabelsAbbr = <?= json_encode($top_student_labels_abbr ?? []) ?>;
    const topStudentValues = <?= json_encode($top_student_values ?? []) ?>;
    const topStudentDetails = <?= json_encode($top_student_details ?? []) ?>;
    function generateDistinctColors(count) { const colors = []; const baseHue = 0; const hueStep = 360 / (count <= 1 ? 2 : count+1); for (let i = 0; i < count; i++) { colors.push(`hsl(${(baseHue + (i+1)*hueStep) % 360}, 65%, 50%)`); } return colors; }
    const programNames = Object.keys(programTrendData); const programColors = generateDistinctColors(programNames.length); const programColorMap = programNames.reduce((map, name, index) => { map[name] = programColors[index % programColors.length]; return map; }, {});
    function renderFallbackMessage(canvasId, message) { const canvas = document.getElementById(canvasId); if (canvas) { const ctx = canvas.getContext('2d'); ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.fillStyle = '#6c757d'; ctx.textAlign = 'center'; ctx.font = '14px Arial'; ctx.fillText(message, canvas.width / 2, canvas.height / 2); } }
    document.addEventListener('DOMContentLoaded', function() {
        const defaultLineChartOptions = { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index', }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 15, usePointStyle: true, font: {size: 11} } }, tooltip: { position: 'nearest', itemSort: (a, b) => b.raw - a.raw, bodySpacing: 4, padding: 8 } }, scales: { y: { beginAtZero: true, max: 100, ticks: { stepSize: 20, callback: function(value) { return value + '%'; } } }, x: { type: 'time', time: { unit: 'day', tooltipFormat: 'MMM d, yyyy', displayFormats: { day: 'MMM d' } }, grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10, font: {size: 11} } } } };
        const defaultBarChartOptions = { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            scales: { x: { beginAtZero: true, max: 100, title: { display: true, text: 'Overall Attendance %' }, ticks: { callback: function(value) { return value + '%'; } } }, y: { grid: { display: false }, ticks: { font: { weight: 'bold' } } } },
            plugins: { legend: { display: false }, tooltip: { displayColors: false, bodySpacing: 4, padding: 8, callbacks: { title: function(context) { return null; }, label: function(context) { const index = context.dataIndex; const details = topStudentDetails[index]; if (details) { return `${details.name} (Yr ${details.year}, Sec ${details.section}): ${details.percentage}%`; } return context.parsed.x + '%'; } } } }
        };
        const trendCtx = document.getElementById('courseTrendChart'); if (trendCtx && typeof programTrendData === 'object' && Object.keys(programTrendData).length > 0 && Array.isArray(trendLabels) && trendLabels.length > 0) { const datasets = Object.keys(programTrendData).filter(progName => programTrendData[progName] && programTrendData[progName].length === trendLabels.length).map(progName => ({ label: progName, data: programTrendData[progName].map((value, index) => ({ x: new Date(trendLabels[index]).getTime(), y: value })), borderColor: programColorMap[progName] || '#888', borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, tension: 0.1 })); if (datasets.length > 0) { new Chart(trendCtx.getContext('2d'), { type: 'line', data: { datasets: datasets }, options: defaultLineChartOptions }); } else { renderFallbackMessage('courseTrendChart', "Insufficient data."); } } else if(trendCtx) { renderFallbackMessage('courseTrendChart', "No course trend data."); }
        const barCtx = document.getElementById('topStudentsChart'); if (barCtx && Array.isArray(topStudentLabelsAbbr) && topStudentLabelsAbbr.length > 0 && Array.isArray(topStudentValues) && topStudentValues.length === topStudentLabelsAbbr.length) { const barColor = 'rgba(211, 47, 47, 0.75)'; const barBorderColor = 'rgba(211, 47, 47, 1)'; new Chart(barCtx.getContext('2d'), { type: 'bar', data: { labels: topStudentLabelsAbbr, datasets: [{ label: 'Overall Attendance %', data: topStudentValues, backgroundColor: barColor, borderColor: barBorderColor, borderWidth: 1, borderRadius: 3 }] }, options: defaultBarChartOptions }); } else if(barCtx) { renderFallbackMessage('topStudentsChart', "No student data."); }
    });
</script>

</body>
</html>

<?php
// --- Error Reporting, Session, Role Check, DB Connection, Sidebar Include (Keep as is) ---
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: ../auth/login.php'); exit; }
$theme = $_SESSION['theme'] ?? 'light'; $language = $_SESSION['language'] ?? 'en';
$admin_name = $_SESSION['username'] ?? "Admin User"; $date = date("l, F j, Y");
require '../../config/db.php'; if (!isset($pdo)) { die("Database connection failed."); }
include '../sidebar.php';

// --- Data Initialization ---
$department_summary = []; $department_trend_data = []; $trend_labels = [];
$bar_chart_labels_abbr = []; $bar_chart_values = []; $error_msg = null; $overall_ict_percentage = 0;
$dept_abbreviations = ['Computer Science & Engineering' => 'CSE', 'Information Technology' => 'IT', 'Electronics & Communication' => 'ECE'];

// --- Fetch Data for Admin Dashboard ---
try {
    // 1. Get Department Info
    $stmt_depts = $pdo->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $stmt_depts->fetchAll(PDO::FETCH_ASSOC);
    if (!$departments) { throw new Exception("No departments found."); }
    $dept_id_to_name = array_column($departments, 'name', 'id');

    // Initialize arrays correctly
    foreach ($dept_id_to_name as $id => $name) {
        $department_summary[$name] = ['id' => $id, 'total' => 0, 'present' => 0, 'percentage' => 0];
        $department_trend_data[$name] = array_fill(0, 30, 0); // Ensure 30 zeros exist
    }

    // 2. Calculate Overall Department Attendance % (Keep as is)
    $sql_overall = "SELECT d.name as dept_name, COUNT(a.id) as total_records, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count FROM departments d LEFT JOIN programs p ON d.id = p.department_id LEFT JOIN sections sec ON p.id = sec.program_id LEFT JOIN attendance a ON sec.id = a.section_id GROUP BY d.id, d.name";
    $stmt_overall = $pdo->query($sql_overall);
    $total_dept_records = 0; $total_dept_present = 0; $valid_dept_count = 0;
    while ($row = $stmt_overall->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['dept_name'];
        if (isset($department_summary[$name])) {
            $total = (int)$row['total_records']; $present = (int)$row['present_count'];
            $department_summary[$name]['total'] = $total; $department_summary[$name]['present'] = $present;
            $department_summary[$name]['percentage'] = ($total > 0) ? round(($present / $total) * 100) : 0;
            if ($total > 0) { $total_dept_records += $total; $total_dept_present += $present; $valid_dept_count++; }
        }
    }
    $overall_ict_percentage = ($total_dept_records > 0) ? round(($total_dept_present / $total_dept_records) * 100) : 0;

    // 3. Calculate Monthly Trend Data (Keep as is - logic is sound, needs data)
    $end_date = date('Y-m-d'); $start_date = date('Y-m-d', strtotime('-29 days'));
    $sql_trend = "SELECT d.name as dept_name, a.date, COUNT(a.id) as total_records, SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count FROM attendance a JOIN sections sec ON a.section_id = sec.id JOIN programs p ON sec.program_id = p.id JOIN departments d ON p.department_id = d.id WHERE a.date BETWEEN ? AND ? GROUP BY d.id, d.name, a.date ORDER BY a.date ASC";
    $stmt_trend = $pdo->prepare($sql_trend); $stmt_trend->execute([$start_date, $end_date]);
    $raw_trend_data = [];
    while ($row = $stmt_trend->fetch(PDO::FETCH_ASSOC)) { $raw_trend_data[$row['dept_name']][$row['date']] = ($row['total_records'] > 0) ? round(($row['present_count'] / $row['total_records']) * 100) : 0; }

    $current_check_date = new DateTime($start_date); $end_date_obj = new DateTime($end_date); $interval = new DateInterval('P1D'); $day_index = 0;
    $trend_labels = []; // Reset trend labels
    while ($current_check_date <= $end_date_obj && $day_index < 30) {
        $date_str = $current_check_date->format('Y-m-d'); $trend_labels[] = $current_check_date->format('M d');
        foreach ($dept_id_to_name as $name) {
            // Ensure key exists before assignment
             if (!isset($department_trend_data[$name])) { $department_trend_data[$name] = array_fill(0, 30, 0); }
            $department_trend_data[$name][$day_index] = $raw_trend_data[$name][$date_str] ?? 0;
        }
        $current_check_date->add($interval); $day_index++;
    }

    // 4. Prepare Bar Chart Data (Keep as is)
    $bar_chart_data_temp = [];
    foreach ($department_summary as $name => $data) { $bar_chart_data_temp[] = ['name' => $name, 'percentage' => $data['percentage']]; }
    usort($bar_chart_data_temp, function ($a, $b) { return $a['percentage'] <=> $b['percentage']; });
    $bar_chart_labels_abbr = []; $bar_chart_values = [];
    foreach($bar_chart_data_temp as $item) {
        $full_name = $item['name'];
        $bar_chart_labels_abbr[] = $dept_abbreviations[$full_name] ?? $full_name;
        $bar_chart_values[] = $item['percentage'];
    }

} catch (PDOException $e) { $error_msg = "Database Error: " . htmlspecialchars($e->getMessage()); error_log("Admin Dashboard DB Error: " . $e->getMessage());
} catch (Exception $e) { $error_msg = "Error: " . htmlspecialchars($e->getMessage()); }
// ---------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - School of ICT</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
         /* --- Keep the refined CSS from previous response --- */
         body { background-color:rgb(214, 218, 193); font-family: 'Helvetica', Arial, sans-serif; }
        .main-content { margin-left: 230px; padding: 2rem; transition: margin-left 0.2s ease-in-out; min-height: 100vh;}
        .greeting { font-size: 1.75rem; font-weight: 600; color: rgb(13, 0, 250);}
        .date { font-size: 0.95rem; color: #6c757d; margin-bottom: 1.5rem;}

        .stat-card {
            background-color: #fff; border: none; border-radius: 0.8rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05); padding: 1.25rem;
            margin-bottom: 1.5rem; display: flex; flex-direction: column; height: 100%;
        }
        .stat-card-title { font-size: 0.9rem; color: #6c757d; font-weight: 500; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;}
        .stat-card-value { font-size: 2.25rem; font-weight: 700; color:rgb(4, 51, 240); margin-top: auto; line-height: 1; }
        .stat-card-value .percent-sign { font-size: 1.1rem; font-weight: 600; color:rgb(7, 35, 247); margin-left: 0.15em; }
        .stat-card.overall { background-color: #ffeaea; }

        .chart-container {
            background-color: #fff; border: none; border-radius: 0.8rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05); padding: 1.5rem;
            height: 420px; display: flex; flex-direction: column; margin-bottom: 1.5rem;
        }
        .chart-header { font-size: 1.1rem; font-weight: 600; color: #343a40; margin-bottom: 1rem; }
        .chart-canvas-container { flex-grow: 1; position: relative; }
        canvas { max-width: 100%; max-height: 100%; }

        @media (max-width: 767px) {
             .main-content { margin-left: 0; padding: 1rem; }
             .chart-container { height: 350px; }
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">

<div class="main-content">
    <div class="mb-4">
        <div class="greeting">Welcome <?= htmlspecialchars($admin_name) ?>!</div>
        <div class="date"><?= $date ?></div>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= $error_msg ?></div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($department_summary as $dept_name => $data): ?>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card h-100">
                        <div class="stat-card-title"><?= htmlspecialchars($dept_name) ?> Attendance</div>
                        <div class="stat-card-value"><?= $data['percentage'] ?><span class="percent-sign">%</span></div>
                    </div>
                </div>
            <?php endforeach; ?>
             <div class="col-xl-3 col-md-6">
                <div class="card stat-card overall h-100">
                    <div class="stat-card-title">Overall ICT Average</div>
                    <div class="stat-card-value"><?= $overall_ict_percentage ?><span class="percent-sign">%</span></div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-lg-7">
                <div class="card chart-container">
                    <div class="chart-header">Department Attendance Trend (Last 30 Days)</div>
                    <div class="chart-canvas-container">
                         <canvas id="deptTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                 <div class="card chart-container">
                    <div class="chart-header">Department Attendance Comparison (%)</div>
                     <div class="chart-canvas-container">
                        <canvas id="deptBarChart"></canvas>
                     </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    const deptSummary = <?= json_encode($department_summary ?? []) ?>;
    const deptTrendData = <?= json_encode($department_trend_data ?? []) ?>;
    const trendLabels = <?= json_encode($trend_labels ?? []) ?>;
    const barChartLabels = <?= json_encode($bar_chart_labels_abbr ?? []) ?>;
    const barChartValues = <?= json_encode($bar_chart_values ?? []) ?>;

    const abbreviationColors = {'CSE': 'rgba(211, 47, 47, 0.8)', 'IT': 'rgba(25, 118, 210, 0.8)', 'ECE': 'rgba(56, 142, 60, 0.8)'};
    const abbreviationBorderColors = {'CSE': 'rgb(211, 47, 47)','IT': 'rgb(25, 118, 210)','ECE': 'rgb(56, 142, 60)'};
    const departmentColors = {'Computer Science & Engineering': 'rgba(211, 47, 47, 0.8)', 'Information Technology': 'rgba(25, 118, 210, 0.8)', 'Electronics & Communication': 'rgba(56, 142, 60, 0.8)'};
    const departmentBorderColors = {'Computer Science & Engineering': 'rgb(211, 47, 47)', 'Information Technology': 'rgb(25, 118, 210)', 'Electronics & Communication': 'rgb(56, 142, 60)'};

    function renderFallbackMessage(canvasId, message) { /* Keep as is */ }

    document.addEventListener('DOMContentLoaded', function() {
        const defaultLineChartOptions = {
             responsive: true, maintainAspectRatio: false,
             plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } } },
             scales: { y: { beginAtZero: true, max: 100, ticks: { stepSize: 20 } }, x: { grid: { display: false } } }
        };
         const defaultBarChartOptions = {
             responsive: true, maintainAspectRatio: false,
             scales: {
                 y: { beginAtZero: true, max: 100, title: { display: true, text: 'Attendance %' } },
                 x: { grid: { display: false }, ticks: { font: { weight: 'bold' } } } // <<< BOLD X-AXIS TICKS
             },
             plugins: { legend: { display: false } }
         };

        // --- Trend Line Chart ---
        const trendCtx = document.getElementById('deptTrendChart');
        if (trendCtx && typeof deptTrendData === 'object' && Object.keys(deptTrendData).length > 0 && Array.isArray(trendLabels) && trendLabels.length > 0) {
            const datasets = Object.keys(deptTrendData)
                .filter(deptName => deptTrendData[deptName] && deptTrendData[deptName].length === trendLabels.length)
                .map(deptName => ({
                    label: deptName,
                    data: deptTrendData[deptName],
                    borderColor: departmentBorderColors[deptName] || `rgb(${Math.random()*200}, ${Math.random()*200}, ${Math.random()*200})`,
                    backgroundColor: (departmentColors[deptName] || `rgba(${Math.random()*200}, ${Math.random()*200}, ${Math.random()*200}, 0.1)`).replace('0.8', '0.1'),
                    tension: 0.3, fill: false, pointRadius: 2, pointHoverRadius: 4, borderWidth: 2
                }));
            if (datasets.length > 0) { new Chart(trendCtx.getContext('2d'), { type: 'line', data: { labels: trendLabels, datasets: datasets }, options: defaultLineChartOptions }); }
            else { renderFallbackMessage('deptTrendChart', "Insufficient data for trend line."); }
        } else if(trendCtx) { renderFallbackMessage('deptTrendChart', "No trend data available."); }

        // --- Bar Chart ---
        const barCtx = document.getElementById('deptBarChart');
        if (barCtx && Array.isArray(barChartLabels) && barChartLabels.length > 0 && Array.isArray(barChartValues) && barChartValues.length === barChartLabels.length) {
             new Chart(barCtx.getContext('2d'), {
                type: 'bar',
                data: { labels: barChartLabels, datasets: [{ label: 'Overall Attendance %', data: barChartValues, backgroundColor: barChartLabels.map(abbr => abbreviationColors[abbr] || 'rgba(150, 150, 150, 0.7)'), borderColor: barChartLabels.map(abbr => abbreviationBorderColors[abbr] || 'rgb(100, 100, 100)'), borderWidth: 1, borderRadius: 4, barPercentage: 0.6, categoryPercentage: 0.7 }] },
                options: defaultBarChartOptions // Using updated options with bold ticks
            });
        } else if(barCtx) { renderFallbackMessage('deptBarChart', "No comparison data available."); }
    });
</script>

</body>
</html>

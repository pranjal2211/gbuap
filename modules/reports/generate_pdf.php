<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

require '../../config/db.php';
$base_dir = dirname(__DIR__, 2);
require_once($base_dir . '/libs/fpdf.php');
require_once($base_dir . '/libs/mc_table.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) { header('Location: ../auth/login.php'); exit; }
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// --- Custom PDF class for beautiful report ---
class MyReportPDF extends PDF_MC_Table {
    public $logoPath = "gbu-logo.png";
    public $universityName = "Gautam Buddha University";
    public $subtitle = "An Ultimate Destination to Higher Learning";

    function Header() {
        $this->SetY(13);
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, ($this->w - 21) / 2, $this->GetY(), 21);
            $this->SetY($this->GetY() + 21 + 2);
        } else {
            $this->SetY($this->GetY() + 20);
        }
        $this->SetFont('helvetica','B',17);
        $this->SetTextColor(211,77,77);
        $this->Cell(0,8, $this->universityName, 0, 1, 'C');
        $this->SetFont('helvetica','',12);
        $this->SetTextColor(0,0,0);
        $this->Cell(0,7, $this->subtitle, 0, 1, 'C');
        $this->Ln(2);
        $this->SetDrawColor(211,77,77);
        $this->SetLineWidth(0.8);
        $this->Line(20, $this->GetY(), $this->w - 20, $this->GetY());
        $this->Ln(3);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica','I',9);
        $this->SetTextColor(120,120,120);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
    function SectionTitle($title) {
        $this->Ln(7);
        $this->SetFont('helvetica','B',12);
        $this->SetFillColor(255,220,220);
        $this->SetTextColor(0,0,0);
        $this->Cell(0,11, "  $title", 0, 1, 'L', true);
        $this->SetFont('helvetica','',11);
        $this->SetTextColor(0,0,0);
        $this->Ln(2);
    }
    function ProfileTable($profileArr) {
        $this->SetFont('helvetica','',11);
        $this->SetFillColor(245,245,245);
        $i = 0;
        foreach ($profileArr as $k => $v) {
            $this->Cell(45,9,$k,0,0,'R', true);
            $this->Cell(0,9,$v,0,1,'L', false);
            $i++;
        }
        $this->Ln(2);
    }
    function QuerySummary($summaryArr) {
        $this->SetFont('helvetica','',11);
        $this->SetFillColor(245,245,245);
        foreach ($summaryArr as $k => $v) {
            $this->Cell(55,9,$k,0,0,'R', true);
            $this->Cell(0,9,$v,0,1,'L', false);
        }
        $this->Ln(2);
    }
    // Table with wrapped text, proper row height, borders, padding, full width, alternating row color, black cell text, abbreviate long text
    function FancyTable($header, $data, $aligns = [], $maxLens = [], $widths = []) {
    $margin = 13;
    $this->SetX($margin);

    // Use provided widths, or default for 5/3 columns
    if (empty($widths)) {
        $widths = count($header) == 5
            ? [38, 48, 48, 28, 18]
            : [90, 40, 30]; // For student/parent
    }
    $n = count($header);

    // Header
    $this->SetFont('helvetica','B',11);
    $this->SetFillColor(211,77,77);
    $this->SetTextColor(255,255,255);
    $this->SetDrawColor(211,77,77);
    $this->SetLineWidth(.5);
    $this->SetX($margin);
    for ($i = 0; $i < $n; $i++)
        $this->Cell($widths[$i],10,$header[$i],1,0,'C',true);
    $this->Ln();

    // Data rows
    $this->SetFont('helvetica','',11);
    $fill = false;
    foreach ($data as $row) {
        $this->SetX($margin);
        for ($i = 0; $i < $n; $i++) {
            $txt = $row[$i];
            if (isset($maxLens[$i]) && $maxLens[$i] > 0 && mb_strlen($txt) > $maxLens[$i]) {
                $txt = mb_substr($txt,0,$maxLens[$i]-3).'...';
            }
            $a = isset($aligns[$i]) ? $aligns[$i] : 'L';
            $this->SetFillColor($fill ? 250 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $this->SetTextColor(0,0,0);

            // Use MultiCell only for Subject (i==0), else Cell
            if ($i == 0 && $n == 3) {
                $x = $this->GetX(); $y = $this->GetY();
                $this->Rect($x, $y, $widths[$i], 9, 'DF');
                $this->SetXY($x+2, $y+2);
                $this->MultiCell($widths[$i]-4, 5, $txt, 0, $a);
                $this->SetXY($x+$widths[$i], $y);
            } else {
                $this->Cell($widths[$i],9,$txt,1,0,$a,true);
            }
        }
        $this->Ln(9);
        $fill = !$fill;
    }
    $this->Ln(2);
}

    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',$txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c==' ')
                $sep = $i;
            $l += $cw[$c];
            if($l>$wmax) {
                if($sep==-1) {
                    if($i==$j)
                        $i++;
                } else
                    $i = $sep+1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
        }
        return $nl;
    }
    function CheckPageBreak($h) {
        if($this->GetY()+$h>$this->PageBreakTrigger)
            $this->AddPage($this->CurOrientation);
    }
}

try {
    $pdf = new MyReportPDF('P','mm','A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',11);

    // --- Fetch Profile ---
    $stmt_user_profile = $pdo->prepare("SELECT username, email, role, department_id FROM users WHERE id = ?");
    $stmt_user_profile->execute([$user_id]);
    $logged_in_user = $stmt_user_profile->fetch();
    if (!$logged_in_user) throw new Exception("Generator profile not found.");

    // --- Section 1: Profile ---
    $pdf->SectionTitle('Profile');
    $profileArr = [
        'Username:' => htmlspecialchars($logged_in_user['username']),
        'Email:' => htmlspecialchars($logged_in_user['email']),
        'Role:' => ucfirst(htmlspecialchars($logged_in_user['role'])),
        'Generated On:' => date('Y-m-d H:i:s')
    ];
    $pdf->ProfileTable($profileArr);

    // --- Section 2: Query Summary ---
    $pdf->SectionTitle('Query Summary');
    $summaryArr = [];
    $f_dept = $_GET['filter_dept'] ?? '';
    $f_prog = $_GET['filter_prog'] ?? '';
    $f_sec = $_GET['filter_sec'] ?? '';
    $f_subj = $_GET['filter_subj'] ?? '';
    $f_percentage = $_GET['filter_percentage'] ?? 'below75';
    if ($role === 'admin' || $role === 'hod' || $role === 'teacher') {
        if ($f_dept) $summaryArr['Department'] = $f_dept;
        if ($f_prog) $summaryArr['Program'] = $f_prog;
        if ($f_sec) $summaryArr['Section'] = $f_sec;
        if ($f_subj) $summaryArr['Subject'] = $f_subj;
        $summaryArr['Attendance %'] = ($f_percentage=='below75' ? '< 75%' : ($f_percentage=='above75' ? '≥ 75%' : 'All'));
    } else {
        $summaryArr['Report Type'] = ($role === 'student') ? 'Subjects Below 75%' : 'Child\'s Subjects Below 75%';
    }
    $pdf->QuerySummary($summaryArr);

    // --- Section 3: Detailed Records (always new page) ---
    $pdf->AddPage();
    $pdf->SectionTitle('Detailed Records');

    // --- Data Fetching ---
    $records = [];
    if ($role === 'admin') {
        // ... your existing admin/dean logic and table structure here ...
        // (do not change anything for admin)
        if ($role === 'admin') {
    // Build dynamic SQL and params based on filters (just like your web page)
    $sql = "SELECT u.username as student_name, subj.name as subject_name, 
                CONCAT(p.name,' Yr',sec.year,' Sec',sec.section_name) as class, 
                COUNT(a.id) as total, 
                SUM(a.status='present') as present
            FROM users u
            JOIN sections sec ON u.section_id = sec.id
            JOIN programs p ON sec.program_id = p.id
            JOIN attendance a ON a.student_id = u.id
            JOIN subjects subj ON a.subject_id = subj.id
            JOIN departments d ON p.department_id = d.id
            WHERE u.role = 'student'";
    $params = [];
    if ($f_dept) { $sql .= " AND d.id = ?"; $params[] = $f_dept; }
    if ($f_prog) { $sql .= " AND p.id = ?"; $params[] = $f_prog; }
    if ($f_sec) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
    if ($f_subj) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
    $sql .= " GROUP BY u.id, subj.id";
    if ($f_percentage == 'below75') $sql .= " HAVING total > 0 AND (present/total)*100 < 75";
    elseif ($f_percentage == 'above75') $sql .= " HAVING total > 0 AND (present/total)*100 >= 75";
    else $sql .= " HAVING total > 0";
    $sql .= " ORDER BY u.username, subj.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $header = ['Student', 'Subject', 'Class', 'Attendance', '%'];
$aligns = ['L', 'L', 'L', 'C', 'C'];
$widths = [38, 48, 48, 28, 18];
$maxLens = [20, 22, 22, 8, 6];


    $data = [];
    foreach($records as $row) {
        $perc = ($row['total'] > 0) ? round(($row['present']/$row['total'])*100,2) : 0;
        $data[] = [
            $row['student_name'],
            $row['subject_name'],
            $row['class'],
            $row['present'].'/'.$row['total'],
            $perc.'%'
        ];
    }
    $pdf->FancyTable($header, $data, $aligns, $maxLens, $widths);
}

    }
    elseif ($role === 'hod') {
        $department_id = $logged_in_user['department_id'];
        $sql = "SELECT u.username as student_name, subj.name as subject_name, CONCAT(p.name,' Yr',sec.year,' Sec',sec.section_name) as class, COUNT(a.id) as total, SUM(a.status='present') as present
            FROM users u
            JOIN sections sec ON u.section_id = sec.id
            JOIN programs p ON sec.program_id = p.id
            JOIN attendance a ON a.student_id = u.id
            JOIN subjects subj ON a.subject_id = subj.id
            WHERE u.role = 'student' AND p.department_id = ?";
        $params = [$department_id];
        if ($f_prog) { $sql .= " AND p.id = ?"; $params[] = $f_prog; }
        if ($f_sec) { $sql .= " AND sec.id = ?"; $params[] = $f_sec; }
        if ($f_subj) { $sql .= " AND subj.id = ?"; $params[] = $f_subj; }
        $sql .= " GROUP BY u.id, subj.id";
        if ($f_percentage == 'below75') $sql .= " HAVING total > 0 AND (present/total)*100 < 75";
        elseif ($f_percentage == 'above75') $sql .= " HAVING total > 0 AND (present/total)*100 >= 75";
        else $sql .= " HAVING total > 0";
        $sql .= " ORDER BY u.username, subj.name";
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $header = ['Student', 'Subject', 'Class', 'Attendance', '%'];
$aligns = ['L', 'L', 'L', 'C', 'C'];
$widths = [38, 48, 48, 28, 18];
$maxLens = [20, 22, 22, 8, 6];

        $data = [];
        foreach($records as $row) {
            $perc = ($row['total'] > 0) ? round(($row['present']/$row['total'])*100,2) : 0;
            $data[] = [
                $row['student_name'],
                $row['subject_name'],
                $row['class'],
                $row['present'].'/'.$row['total'],
                $perc.'%'
            ];
        }
        $pdf->FancyTable($header, $data, $aligns, $maxLens, $widths);
    }
    elseif ($role === 'teacher') {
        $stmt_assign = $pdo->prepare("SELECT subject_id, section_id FROM faculty_assignments WHERE teacher_user_id = ?");
        $stmt_assign->execute([$user_id]);
        $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);
        $subject_section = [];
        foreach ($assignments as $a) $subject_section[] = "(a.subject_id = {$a['subject_id']} AND a.section_id = {$a['section_id']})";
        if (!$subject_section) { $records = []; }
        else {
            $where = implode(' OR ', $subject_section);
            $sql = "SELECT u.username as student_name, subj.name as subject_name, CONCAT(p.name,' Yr',sec.year,' Sec',sec.section_name) as class, COUNT(a.id) as total, SUM(a.status='present') as present
                FROM users u
                JOIN attendance a ON a.student_id = u.id
                JOIN subjects subj ON a.subject_id = subj.id
                JOIN sections sec ON a.section_id = sec.id
                JOIN programs p ON sec.program_id = p.id
                WHERE u.role = 'student' AND ($where)";
            if ($f_sec) $sql .= " AND sec.id = ".intval($f_sec);
            if ($f_subj) $sql .= " AND subj.id = ".intval($f_subj);
            $sql .= " GROUP BY u.id, subj.id";
            if ($f_percentage == 'below75') $sql .= " HAVING total > 0 AND (present/total)*100 < 75";
            elseif ($f_percentage == 'above75') $sql .= " HAVING total > 0 AND (present/total)*100 >= 75";
            else $sql .= " HAVING total > 0";
            $sql .= " ORDER BY u.username, subj.name";
            $records = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        $header = ['Student', 'Subject', 'Class', 'Attendance', '%'];
$aligns = ['L', 'L', 'L', 'C', 'C'];
$maxLens = [20, 22, 22, 8, 6];


        $data = [];
        foreach($records as $row) {
            $perc = ($row['total'] > 0) ? round(($row['present']/$row['total'])*100,2) : 0;
            $data[] = [
                $row['student_name'],
                $row['subject_name'],
                $row['class'],
                $row['present'].'/'.$row['total'],
                $perc.'%'
            ];
        }
        $pdf->FancyTable($header, $data, $aligns, $maxLens);
    }
    elseif ($role === 'student') {
        $sql = "SELECT subj.name as subject_name, COUNT(a.id) as total, SUM(a.status='present') as present
            FROM attendance a
            JOIN subjects subj ON a.subject_id = subj.id
            WHERE a.student_id = ?
            GROUP BY a.subject_id, subj.name
            HAVING total > 0 AND (present/total)*100 < 75";
        $stmt = $pdo->prepare($sql); $stmt->execute([$user_id]); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $header = ['Subject', 'Attendance', '%'];
$aligns = ['L', 'C', 'C'];
$widths = [90, 70, 20]; // Adjust as needed for your page size and margins




        $data = [];
        foreach($records as $row) {
            $perc = ($row['total'] > 0) ? round(($row['present']/$row['total'])*100,2) : 0;
            $data[] = [
                $row['subject_name'],
                $row['present'].'/'.$row['total'],
                $perc.'%'
            ];
        }
        $pdf->FancyTable($header, $data, $aligns, $maxLens = [40, 10, 6], $widths);
    }
    elseif ($role === 'parent') {
        $stmt_child = $pdo->prepare("SELECT id FROM users WHERE parent_id = ? AND role = 'student' LIMIT 1");
        $stmt_child->execute([$user_id]);
        $child = $stmt_child->fetch(PDO::FETCH_ASSOC);
        if ($child) {
            $sql = "SELECT subj.name as subject_name, COUNT(a.id) as total, SUM(a.status='present') as present
                FROM attendance a
                JOIN subjects subj ON a.subject_id = subj.id
                WHERE a.student_id = ?
                GROUP BY a.subject_id, subj.name
                HAVING total > 0 AND (present/total)*100 < 75";
            $stmt = $pdo->prepare($sql); $stmt->execute([$child['id']]); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $header = ['Subject', 'Attendance', '%'];
$aligns = ['L', 'C', 'C'];
$widths = [90, 70, 20]; // Adjust as needed for your page size and margins

            $data = [];
            foreach($records as $row) {
                $perc = ($row['total'] > 0) ? round(($row['present']/$row['total'])*100,2) : 0;
                $data[] = [
                    $row['subject_name'],
                    $row['present'].'/'.$row['total'],
                    $perc.'%'
                ];
            }
            $pdf->FancyTable($header, $data, $aligns, $maxLens = [40, 10, 6], $widths);

        }
    }

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    $filename_to_use = "Report_" . date('YmdHis') . ".pdf";
    $pdf->Output('D', $filename_to_use);

} catch (Throwable $e) {
    header('Content-Type: text/plain');
    echo "Error generating PDF: " . $e->getMessage();
}
exit;
?>

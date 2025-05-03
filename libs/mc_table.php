<?php

// require_once(__DIR__ . '/fpdf.php'); // Always use require_once for safety

class PDF_MC_Table extends FPDF {

    protected $widths;
    protected $aligns;

    function SetWidths($w) {
        $this->widths = $w;
    }

    function SetAligns($a) {
        $this->aligns = $a;
    }

    function Row($data) {
        $nb = 0;
        for($i=0;$i<count($data);$i++)
            $nb = max($nb,$this->NbLines($this->widths[$i],$data[$i]));
        $h = 5*$nb;
        $this->CheckPageBreak($h);
        for($i=0;$i<count($data);$i++) {
            $w = $this->widths[$i];
            $a = isset($this->aligns[$i]) ? $this->aligns[$i] : 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->Rect($x,$y,$w,$h);
            $this->MultiCell($w,5,$data[$i],0,$a);
            $this->SetXY($x+$w,$y);
        }
        $this->Ln($h);
    }

    function CheckPageBreak($h) {
        if($this->GetY()+$h>$this->PageBreakTrigger)
            $this->AddPage($this->CurOrientation);
    }

    function NbLines($w, $txt) {
        if(!isset($this->CurrentFont))
            $this->Error('No font has been set');
        $cw = $this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',(string)$txt);
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

    // --- Your custom formatting methods below ---

    function SectionTitle($title) {
        $this->SetFont('helvetica','B',13);
        $this->Cell(0,10,$title,0,1,'L');
        $this->Ln(2);
        $this->SetFont('helvetica','',11);
    }

    function ProfileTable($profileArr) {
        $this->SetFont('helvetica','',11);
        foreach ($profileArr as $k => $v) {
            $this->Cell(40,8,$k,0,0,'R');
            $this->Cell(0,8,$v,0,1,'L');
        }
        $this->Ln(2);
    }

    function GenericTable($header, $data, $widths, $aligns) {
        $this->SetFont('helvetica','B',11);
        for ($i = 0; $i < count($header); $i++) {
            $this->Cell($widths[$i],8,$header[$i],1,0,'C');
        }
        $this->Ln();
        $this->SetFont('helvetica','',11);
        foreach ($data as $row) {
            for ($i = 0; $i < count($row); $i++) {
                $this->Cell($widths[$i],8,$row[$i],1,0,$aligns[$i]);
            }
            $this->Ln();
        }
        $this->Ln(2);
    }
    function FancyTable($header, $data, $aligns = [], $maxLens = []) {
    $margin = 13;
    $tableWidth = $this->w - 2 * $margin;
    $this->SetX($margin);

    // Fixed column widths for neatness
    $widths = [38, 48, 48, 28, 18]; // Student, Subject, Class, Attendance, %
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
            // Abbreviate text if too long for the cell
            if (isset($maxLens[$i]) && $maxLens[$i] > 0 && mb_strlen($txt) > $maxLens[$i]) {
                $txt = mb_substr($txt,0,$maxLens[$i]-3).'...';
            }
            $a = isset($aligns[$i]) ? $aligns[$i] : 'L';
            $this->SetFillColor($fill ? 250 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $this->SetTextColor(0,0,0);

            // Use MultiCell only for Subject and Class, else Cell
            if ($i == 1 || $i == 2) {
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


}

?>

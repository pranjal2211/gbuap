<?php
require '../../config/db.php';
$prog_id = $_GET['program_id'] ?? 0;
if ($prog_id) {
    // You can customize this for your programs
    echo '<option value="">-- Select Year --</option>';
    for($i=1;$i<=5;$i++) echo '<option value="'.$i.'">'.$i.' Year</option>';
}

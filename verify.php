<?php
$input = 'password123'; // the password you are entering in the login form
$hash = '$2y$10$fS/gpd57fNFsQCV25scmj.0ubpYYCgnkX2z5CF3WEz3FEaYr6dFyK'; // paste your hash here
if (password_verify($input, $hash)) {
    echo "Password matches!";
} else {
    echo "Password does NOT match!";
}
?>
<?php
$input = 'password123'; // the password you are entering in the login form
$hash = '$2y$10$fS/gpd57fNFsQCV25scmj.0ubpYYCgnkX2z5CF3WEz3FEaYr6dFyK'; // paste your hash here
if (password_verify($input, $hash)) {
    echo "Password matches!";
} else {
    echo "Password does NOT match!";
}
?>

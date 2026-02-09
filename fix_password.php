<?php
// fix_password.php
require_once 'config/db.php';

echo "<h1>Fix Password for User 1001</h1>";

$username = '1001';
// Hash for 'password123' (taken from debug output to ensure consistency)
$newHash = '$2y$10$GKQJ9S.wUcnEvqS9GUDG8.xXsCngiR7IuYnpqp.qU0g2ghlXzN8mu';

// Update lrnph_users
$sql = "UPDATE lrnph_users SET password = ? WHERE username = ?";
$params = [$newHash, $username];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo "Update failed: " . print_r(sqlsrv_errors(), true);
} else {
    $rowsAffected = sqlsrv_rows_affected($stmt);
    echo "Update executed. Rows affected: " . $rowsAffected . "<br>";
    if ($rowsAffected > 0) {
        echo "<h2 style='color:green'>SUCCESS: Password for 1001 reset to 'password123'</h2>";
        echo "<p>You can now <a href='login.php'>Login</a>.</p>";
    } else {
        echo "<h2 style='color:orange'>WARNING: No rows updated. User 1001 might not exist or password was already set.</h2>";
    }
}
?>
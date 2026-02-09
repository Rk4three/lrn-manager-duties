<?php
// fix_password.php
require_once 'config/db.php';

echo "<h1>Fix Password for User 1001</h1>";

$username = '1001';
$password = 'password123';

// Generate a fresh bcrypt hash for 'password123'
$newHash = password_hash($password, PASSWORD_DEFAULT);

echo "<p><strong>Generated Hash:</strong> $newHash</p>";

// Update ALL users in lrnph_users
$sql = "UPDATE lrnph_users SET password = ?";
$params = [$newHash];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo "Update failed: " . print_r(sqlsrv_errors(), true);
} else {
    $rowsAffected = sqlsrv_rows_affected($stmt);
    echo "Update executed. Rows affected: " . $rowsAffected . "<br>";
    if ($rowsAffected > 0) {
        echo "<h2 style='color:green'>SUCCESS: Password for user $username reset to '$password'</h2>";

        // Verify the hash works
        echo "<h3>Verification Test:</h3>";
        if (password_verify($password, $newHash)) {
            echo "<p style='color:green'>✓ Password verification test PASSED</p>";
        } else {
            echo "<p style='color:red'>✗ Password verification test FAILED</p>";
        }

        echo "<p>You can now <a href='login.php'>Login</a>.</p>";
    } else {
        echo "<h2 style='color:orange'>WARNING: No rows updated. User 1001 might not exist or password was already set.</h2>";
    }
}
?>
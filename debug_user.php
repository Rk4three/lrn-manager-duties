<?php
// debug_user.php
require_once 'config/db.php';

echo "<h1>Debug User: 1001</h1>";

$username = '1001';
$password = 'password123';

// 1. Check lrnph_users
echo "<h2>1. Checking lrnph_users</h2>";
$sql = "SELECT * FROM lrnph_users WHERE username = ?";
$stmt = sqlsrv_query($conn, $sql, [$username]);
if ($stmt === false) {
    echo "Query failed: " . print_r(sqlsrv_errors(), true);
} else {
    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($user) {
        echo "User found in lrnph_users:<br>";
        echo "<pre>" . print_r($user, true) . "</pre>";

        // Verify Password
        if (password_verify($password, $user['password'])) {
            echo "<strong style='color:green'>Password Verify: MATCH</strong><br>";
        } else {
            echo "<strong style='color:red'>Password Verify: FAIL</strong><br>";
            echo "Hash in DB: " . $user['password'] . "<br>";
            echo "Hash of input: " . password_hash($password, PASSWORD_DEFAULT) . "<br>";
        }
    } else {
        echo "User '1001' NOT FOUND in lrnph_users.<br>";
    }
}

// 2. Check lrn_master_list
echo "<h2>2. Checking lrn_master_list</h2>";
// Note: In login_action.php, we join on: lu.username = ml.BiometricsID COLLATE DATABASE_DEFAULT
$sql = "SELECT * FROM lrn_master_list WHERE BiometricsID = ?";
$stmt = sqlsrv_query($conn, $sql, [$username]);
if ($stmt === false) {
    echo "Query failed: " . print_r(sqlsrv_errors(), true);
} else {
    $ml = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($ml) {
        echo "User found in lrn_master_list:<br>";
        echo "<pre>" . print_r($ml, true) . "</pre>";
        $fullName = $ml['FirstName'] . ' ' . $ml['LastName'];
        echo "Calculated FullName: <strong>$fullName</strong><br>";
    } else {
        echo "User '1001' NOT FOUND in lrn_master_list (BiometricsID columns).<br>";
    }
}

// 3. Check DM_Users (Authorization)
if (isset($fullName)) {
    echo "<h2>3. Checking DM_Users (Authorization)</h2>";
    $sql = "SELECT * FROM DM_Users WHERE Name = ?";
    $stmt = sqlsrv_query($conn, $sql, [$fullName]);
    if ($stmt === false) {
        echo "Query failed: " . print_r(sqlsrv_errors(), true);
    } else {
        $dm = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($dm) {
            echo "User found in DM_Users:<br>";
            echo "<pre>" . print_r($dm, true) . "</pre>";
            if ($dm['IsActive']) {
                echo "<strong style='color:green'>Authorization: OK</strong>";
            } else {
                echo "<strong style='color:red'>Authorization: FAIL (IsActive=0)</strong>";
            }
        } else {
            echo "<strong style='color:red'>User '$fullName' NOT FOUND in DM_Users.</strong><br>";
            echo "This causes 'Not Authorized' error.<br>";

            echo "<h3>Current DM_Users:</h3>";
            $allDetails = dbQuery("SELECT * FROM DM_Users");
            echo "<pre>" . print_r($allDetails, true) . "</pre>";
        }
    }
}
?>
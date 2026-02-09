<?php
// auth/login_action.php
session_start();

// 1. Connection Configuration
// 1. Connection Configuration
require_once '../config/db.php';

if (!$conn) {
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
}

// ---------------------------------------------------------
// HELPER: Legacy Password Checker
// ---------------------------------------------------------
function checkLegacyPassword($inputPassword, $storedHash)
{
    if (md5($inputPassword) === $storedHash)
        return true;
    if (sha1($inputPassword) === $storedHash)
        return true;
    if (strtoupper(md5($inputPassword)) === strtoupper($storedHash))
        return true;
    if ($inputPassword === $storedHash)
        return true;
    return false;
}
// ---------------------------------------------------------

// 3. Handle Login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // ALLOW TEMP ADMIN LOGIN (Bypass main auth)
    if ($username === 'temp_admin' && $password === 'admin123') {
        $_SESSION['user_id'] = 'TEMP001';
        $_SESSION['full_name'] = 'Temp Admin';
        $_SESSION['dept'] = 'IT';
        $_SESSION['user_photo_id'] = 'TEMP001';
        $_SESSION['position_title'] = 'Acting Manager';
        $_SESSION['job_level'] = 'Manager';

        // Get DM_User details
        $dmCheckSql = "SELECT ID, Name, Role, IsSuperAdmin FROM DM_Users WHERE Name = 'Temp Admin'";
        $dmStmt = sqlsrv_query($conn, $dmCheckSql);

        if ($dmStmt && $dmUser = sqlsrv_fetch_array($dmStmt, SQLSRV_FETCH_ASSOC)) {
            $_SESSION['dm_user_id'] = $dmUser['ID'];
            $_SESSION['dm_name'] = $dmUser['Name'];
            $_SESSION['dm_department'] = 'IT';
            $_SESSION['dm_role'] = $dmUser['Role'];
            $_SESSION['is_super_admin'] = (bool) $dmUser['IsSuperAdmin'];

            header("Location: ../index.php");
            exit();
        }
    }

    // First, get user details from LRNPH.dbo.lrnph_users and LRNPH_E.dbo.lrn_master_list
    // Username entered = BiometricsID in lrn_master_list
    $sql = "SELECT lu.username, lu.password, lu.role, lu.empcode, 
            ml.FirstName + ' ' + ml.LastName as fullname,
            ml.Department,
            ml.PositionTitle,
            ml.EmployeeID,
            ml.BiometricsID
            FROM lrnph_users lu
            LEFT JOIN lrn_master_list ml 
            ON lu.username = ml.BiometricsID COLLATE DATABASE_DEFAULT
            WHERE lu.username = ?";

    // Use $connData (SA connection) for cross-database access
    $stmt = sqlsrv_query($connData, $sql, array($username));

    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    // If user not found in lrnph_users
    if (!$user) {
        header("Location: ../login.php?error=invalid_credentials");
        exit();
    }

    $fullName = $user['fullname'];
    $department = $user['Department'] ?? 'N/A';

    // Check if this user is an authorized Duty Manager by matching their full name
    $dmCheckSql = "SELECT ID, Name, Role, IsSuperAdmin 
                   FROM DM_Users 
                   WHERE Name = ? AND IsActive = 1";
    $dmStmt = sqlsrv_query($conn, $dmCheckSql, array($fullName));

    $dmUser = null;
    if ($dmStmt) {
        $dmUser = sqlsrv_fetch_array($dmStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($dmStmt);
    }

    // If user is not an authorized Duty Manager, reject them
    if (!$dmUser) {
        header("Location: ../login.php?error=not_authorized");
        exit();
    }

    // Verify password
    $loginSuccess = false;
    $needsRehash = false;
    $storedHash = $user['password'];

    if (password_verify($password, $storedHash)) {
        $loginSuccess = true;
    } elseif (checkLegacyPassword($password, $storedHash)) {
        $loginSuccess = true;
        $needsRehash = true;
    }

    // 4. Finalize Login
    if ($loginSuccess) {

        if ($needsRehash) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $updateSql = "UPDATE lrnph_users SET password = ? WHERE username = ?";
            sqlsrv_query($connData, $updateSql, array($newHash, $username));
        }

        $_SESSION['user_id'] = $user['empcode'];
        $_SESSION['full_name'] = $fullName;
        $_SESSION['dept'] = $department;
        $_SESSION['user_photo_id'] = $user['EmployeeID'] ?? $user['empcode'];

        $position = trim($user['PositionTitle'] ?? '');
        if (empty($position)) {
            $position = $user['role'] ?? 'Employee';
        }

        $_SESSION['position_title'] = $position;
        $_SESSION['job_level'] = $user['role'];

        // -----------------------------------------------------------
        // Duty Manager specific session data
        // -----------------------------------------------------------
        $_SESSION['dm_user_id'] = $dmUser['ID'];
        $_SESSION['dm_name'] = $dmUser['Name'];
        $_SESSION['dm_department'] = $department; // From lrn_master_list_test
        $_SESSION['dm_role'] = $dmUser['Role'];
        $_SESSION['is_super_admin'] = (bool) $dmUser['IsSuperAdmin'];
        // -----------------------------------------------------------

        header("Location: ../index.php");
        exit();

    } else {
        header("Location: ../login.php?error=invalid_credentials");
        exit();
    }
}
?>
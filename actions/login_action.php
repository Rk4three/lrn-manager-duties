<?php
// auth/login_action.php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Connection Configuration
// 1. Connection Configuration
require_once '../config/db.php';

global $pdo;
if (!$pdo) {
    die("Connection failed.");
}

// ... (helper functions omitted or kept if checkLegacyPassword is used)

// 3. Handle Login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // ALLOW TEMP ADMIN LOGIN (Backdoor for Initial Setup)
    if ($username === 'temp_admin' && $password === 'admin123') {
        // ... (keep temp admin logic)
        $_SESSION['user_id'] = 'TEMP001';
        $_SESSION['full_name'] = 'Temp Admin';
        $_SESSION['dept'] = 'IT';
        $_SESSION['dm_user_id'] = 99999;
        $_SESSION['dm_name'] = 'Temp Admin';
        $_SESSION['dm_department'] = 'IT';
        $_SESSION['dm_role'] = 'Admin';
        $_SESSION['is_super_admin'] = true;
        header("Location: ../index.php");
        exit();
    }

    // AUTHENTICATE AGAINST DM_Users (Local)
    // Removed "Role" and "IsActive" as they are not in the provided schema.sql
    $sql = "SELECT \"ID\", \"Name\", \"Password\", \"IsSuperAdmin\", \"Department\", \"EmployeeID\", \"PhotoURL\" 
            FROM \"DM_Users\" 
            WHERE \"Username\" = ?";

    $user = dbQueryOne($sql, [$username]);

    if (!$user) {
        // User not found
        header("Location: ../login.php?error=invalid_credentials");
        exit();
    }

    // Verify Password
    if (password_verify($password, $user['Password'])) {
        // Login Success
        $_SESSION['user_id'] = $user['EmployeeID'] ?? $user['ID'];
        $_SESSION['full_name'] = $user['Name'];
        $_SESSION['dept'] = $user['Department'] ?? 'N/A';
        $_SESSION['user_photo_id'] = $user['EmployeeID'] ?? null;

        // DM Session Vars
        $_SESSION['dm_user_id'] = $user['ID'];
        $_SESSION['dm_name'] = $user['Name'];
        $_SESSION['dm_department'] = $user['Department'] ?? 'N/A';
        $_SESSION['dm_role'] = ($user['IsSuperAdmin'] ? 'Admin' : 'Manager'); // Derived role
        $_SESSION['is_super_admin'] = (bool) ($user['IsSuperAdmin'] ?? false);

        header("Location: ../index.php");
        exit();
    } else {
        header("Location: ../login.php?error=invalid_credentials");
        exit();
    }
}
?>
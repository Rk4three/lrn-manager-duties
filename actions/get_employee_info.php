<?php
/**
 * Get Employee Information
 * Fetches employee photo URL and department info
 */
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$managerID = $_GET['manager_id'] ?? $_SESSION['dm_user_id'];
$managerName = $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'Unknown';

// Try to get EmployeeID and Department info
$employeeID = null;
$department = $_SESSION['dm_department'] ?? $_SESSION['dept'] ?? null;

// First, try to get EMPCODE from schedule.csv (EMPCODE = EmployeeID in lrn_master_list)
$csvPath = __DIR__ . '/../maintenance/schedule.csv';
if (file_exists($csvPath)) {
    $file = fopen($csvPath, 'r');
    $headers = fgetcsv($file);

    while (($row = fgetcsv($file)) !== false) {
        // Columns: 0=NO, 1=DEPT, 2=EMPCODE, 3=EMPLOYEE NAME
        $csvName = $row[3] ?? '';
        $csvEmpCode = trim($row[2] ?? ''); // EMPCODE
        $csvDept = trim($row[1] ?? '');

        // Match by name (case-insensitive partial match)
        if (!empty($csvName) && (stripos($csvName, $managerName) !== false || stripos($managerName, $csvName) !== false)) {
            // Found match in CSV
            if (!empty($csvEmpCode)) {
                $employeeID = $csvEmpCode; // EMPCODE = EmployeeID
            }
            if (!$department && !empty($csvDept)) {
                $department = $csvDept;
            }
            break;
        }
    }
    fclose($file);
}

// If we found EMPCODE, or if we just want to look up by ID/Name in DM_Users
// Refactored for PostgreSQL / Independent DB: Query DM_Users
$employeeID = null;
$department = null;

if ($managerID) {
    // Lookup by ID
    $user = dbQueryOne("SELECT \"EmployeeID\", \"Department\", \"Name\" FROM \"DM_Users\" WHERE \"ID\" = ?", [$managerID]);
    if ($user) {
        $employeeID = $user['EmployeeID'];
        $department = $user['Department'];
        // $managerName = $user['Name']; // Optional: update name from DB
    }
} elseif ($managerName) {
    // Lookup by Name (Fallback)
    $user = dbQueryOne("SELECT \"EmployeeID\", \"Department\" FROM \"DM_Users\" WHERE \"Name\" = ?", [$managerName]);
    if ($user) {
        $employeeID = $user['EmployeeID'];
        $department = $user['Department'];
    }
}


// Generate photo URL
$photoUrl = null;
if ($employeeID) {
    $baseUrl = "http://10.2.0.8/lrnph/emp_photos/";
    $formats = ['jpg', 'png', 'jpeg'];

    // We'll return the first format's URL and let the frontend handle fallback
    $photoUrl = $baseUrl . $employeeID . '.jpg';

    // Store in session for future use
    $_SESSION['employee_id'] = $employeeID;
}

// Update session if we found new info
if ($department && !isset($_SESSION['dm_department'])) {
    $_SESSION['dm_department'] = $department;
}

echo json_encode([
    'success' => true,
    'employee_id' => $employeeID,
    'department' => $department,
    'photo_url' => $photoUrl,
    'manager_name' => $managerName,
    'photo_fallback' => 'assets/img/mystery-man.png'
]);

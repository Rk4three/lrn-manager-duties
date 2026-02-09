<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['dm_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check for dates
if (!isset($_POST['dates']) || empty($_POST['dates']) || !is_array($_POST['dates'])) {
    echo json_encode(['success' => false, 'message' => 'No dates provided']);
    exit();
}

$dates = $_POST['dates'];
$placeholders = implode(',', array_fill(0, count($dates), '?'));

// Fetch managers who have entries on these dates
// We use DISTINCT to avoid duplicates
$sql = "
    SELECT DISTINCT 
        ManagerID, 
        ManagerName, 
        Department 
    FROM Manager_Calendar 
    WHERE EntryDate IN ($placeholders)
    ORDER BY ManagerName ASC
";

try {
    $managers = dbQuery($sql, $dates);

    // Check if dbQuery returned false (error)
    if ($managers === false) {
        throw new Exception("Database error");
    }

    // Format for frontend
    $result = [];
    foreach ($managers as $mgr) {
        $result[] = [
            'ID' => $mgr['ManagerID'],
            'Name' => $mgr['ManagerName'],
            'Department' => $mgr['Department']
        ];
    }

    echo json_encode(['success' => true, 'managers' => $result]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

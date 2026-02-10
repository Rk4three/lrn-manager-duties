<?php
/**
 * Batch Save Calendar Entries
 * Handles creating or updating multiple calendar entries at once
 * Supports assigning to multiple managers if Super Admin
 */
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$currentManagerID = $_SESSION['dm_user_id'];
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

// Get POST data
$dates = $_POST['dates'] ?? []; // Array of date strings 'YYYY-MM-DD'
$entryType = $_POST['entry_type'] ?? null;
$startHour = $_POST['start_hour'] ?? null;
$startMinute = $_POST['start_minute'] ?? null;
$startAMPM = $_POST['start_ampm'] ?? null;
$endHour = $_POST['end_hour'] ?? null;
$endMinute = $_POST['end_minute'] ?? null;
$endAMPM = $_POST['end_ampm'] ?? null;
$leaveNote = $_POST['leave_note'] ?? null;

// Target Managers (Array)
// If not provided or not super admin, default to current user
$targetManagerIDs = [];
if ($isSuperAdmin && isset($_POST['manager_ids']) && is_array($_POST['manager_ids']) && !empty($_POST['manager_ids'])) {
    $targetManagerIDs = $_POST['manager_ids'];
} else {
    // Default to self if no specific managers selected or not allowed
    // But check if a single manager_id was passed (backwards compatibility or single select)
    if ($isSuperAdmin && isset($_POST['manager_id']) && !empty($_POST['manager_id'])) {
        $targetManagerIDs = [$_POST['manager_id']];
    } else {
        $targetManagerIDs = [$currentManagerID];
    }
}

// Validate basic requirements
if (empty($dates) || !is_array($dates)) {
    echo json_encode(['success' => false, 'message' => 'No dates selected']);
    exit();
}
if (!$entryType) {
    echo json_encode(['success' => false, 'message' => 'Entry type is required']);
    exit();
}

// Build time strings
$startTime = null;
$endTime = null;

if ($entryType === 'WORK') {
    if (!$startHour || !$endHour) {
        echo json_encode(['success' => false, 'message' => 'Time configuration incomplete']);
        exit();
    }
    $startTime = $startHour . ':' . $startMinute . ' ' . $startAMPM;
    $endTime = $endHour . ':' . $endMinute . ' ' . $endAMPM;
}

$successCount = 0;
$failCount = 0;

// Connect to Master List for Info Lookup if needed
$connML = null;
if (isset($connData) && $connData) {
    $connML = $connData;
}

// Pre-fetch info for all target managers to minimize queries inside loop
// We need their Name, EmployeeID, and Department
$managersInfo = [];

if ($isSuperAdmin) {
    // If multiple managers, fetch their details from DM_Users
    $idsStr = implode(',', array_map('intval', $targetManagerIDs));
    $users = dbQuery("SELECT ID, Name, EmployeeID, Department FROM DM_Users WHERE ID IN ($idsStr)");

    // Map ID to Info
    if ($users) {
        foreach ($users as $u) {
            $managersInfo[$u['ID']] = [
                'Name' => $u['Name'],
                'EmployeeID' => $u['EmployeeID'],
                'Department' => $u['Department'] ?? 'N/A'
            ];
        }
    }
} else {
    // Self
    $managersInfo[$currentManagerID] = [
        'Name' => $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'Unknown',
        'EmployeeID' => $_SESSION['employee_id'] ?? null,
        'Department' => $_SESSION['dm_department'] ?? $_SESSION['dept'] ?? 'N/A'
    ];
}


// Process Loop
foreach ($targetManagerIDs as $targetID) {
    // Skip if valid info not found (shouldn't happen usually)
    if (!isset($managersInfo[$targetID]))
        continue;

    $mgrName = $managersInfo[$targetID]['Name'];
    $empID = $managersInfo[$targetID]['EmployeeID'];
    $dept = $managersInfo[$targetID]['Department'];

    foreach ($dates as $date) {
        // Check for existing entry
        $existing = dbQueryOne(
            "SELECT ID FROM Manager_Calendar WHERE ManagerID = ? AND EntryDate = ?",
            [$targetID, $date]
        );

        if ($existing) {
            // Update
            $sql = "UPDATE Manager_Calendar 
                    SET EntryType = ?, StartTime = ?, EndTime = ?, LeaveNote = ?, UpdatedAt = CURRENT_TIMESTAMP
                    WHERE ID = ?";
            $res = dbExecute($sql, [$entryType, $startTime, $endTime, $leaveNote, $existing['ID']]);
        } else {
            // Insert
            $sql = "INSERT INTO Manager_Calendar 
                    (ManagerID, ManagerName, EmployeeID, Department, EntryDate, EntryType, StartTime, EndTime, LeaveNote)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $res = dbExecute($sql, [
                $targetID,
                $mgrName,
                $empID,
                $dept,
                $date,
                $entryType,
                $startTime,
                $endTime,
                $leaveNote
            ]);
        }

        if ($res) {
            $successCount++;
        } else {
            $failCount++;
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => "Processed $successCount entries successfully." . ($failCount > 0 ? " ($failCount failed)" : "")
]);

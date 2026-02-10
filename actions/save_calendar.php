<?php
/**
 * Save or Update Calendar Entry
 * Handles both work schedules and leave entries
 */
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$managerID = $_SESSION['dm_user_id'];
$managerName = $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'Unknown';

// Allow Super Admin to override ManagerID
if (isset($_POST['manager_id']) && !empty($_POST['manager_id'])) {
    if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']) {
        $targetManagerID = $_POST['manager_id'];

        // Fetch target manager details to ensure name is correct
        $targetUser = dbQueryOne("SELECT \"Name\", \"Role\" FROM \"DM_Users\" WHERE \"ID\" = ?", [$targetManagerID]);
        if ($targetUser) {
            $managerID = $targetManagerID;
            $managerName = $targetUser['Name'];
            // Note: EmployeeID/Department might need to be fetched dynamically if not in DM_Users
            // DM_Users has Name, Role. Department might be in session or master list. 
            // For now, we use what we have or fetch if needed.
            // The existing code uses session vars for Dept/EmpID below. We might need to update that too.
        }
    }
}

// Get POST data
$entryID = $_POST['entry_id'] ?? null;
$entryDate = $_POST['entry_date'] ?? null;
$entryType = $_POST['entry_type'] ?? null; // 'WORK' or 'LEAVE'
$startHour = $_POST['start_hour'] ?? null;
$startMinute = $_POST['start_minute'] ?? null;
$startAMPM = $_POST['start_ampm'] ?? null;
$endHour = $_POST['end_hour'] ?? null;
$endMinute = $_POST['end_minute'] ?? null;
$endAMPM = $_POST['end_ampm'] ?? null;
$leaveNote = $_POST['leave_note'] ?? null;

// Validate required fields
if (!$entryDate || !$entryType) {
    echo json_encode(['success' => false, 'message' => 'Date and entry type are required']);
    exit();
}

// Validate entry type
if (!in_array($entryType, ['WORK', 'LEAVE'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid entry type']);
    exit();
}

// Build time strings for WORK entries
$startTime = null;
$endTime = null;
if ($entryType === 'WORK') {
    if (!$startHour || !$startMinute || !$startAMPM || !$endHour || !$endMinute || !$endAMPM) {
        echo json_encode(['success' => false, 'message' => 'Work schedule requires start and end times']);
        exit();
    }

    // Validate minute values
    if (!in_array($startMinute, ['00', '30']) || !in_array($endMinute, ['00', '30'])) {
        echo json_encode(['success' => false, 'message' => 'Minutes must be 00 or 30']);
        exit();
    }

    // Validate hour values
    if ($startHour < 1 || $startHour > 12 || $endHour < 1 || $endHour > 12) {
        echo json_encode(['success' => false, 'message' => 'Hour must be between 1 and 12']);
        exit();
    }

    $startTime = $startHour . ':' . $startMinute . ' ' . $startAMPM;
    $endTime = $endHour . ':' . $endMinute . ' ' . $endAMPM;
}

// Get employee info from session or database
// Get employee info (Default to session)
$employeeID = $_SESSION['employee_id'] ?? null;
$department = $_SESSION['dm_department'] ?? $_SESSION['dept'] ?? null;

// If we switched users (Super Admin override), try to fetch their specific info from Master List
if (isset($targetUser)) {
    // We already fetched properties from DM_Users in previous block, but DM_Users might not have EmployeeID/Department fully
    // Let's try to get it from lrn_master_list based on Name (since we rely on Name link)
    // Or if we can't search master list here easily (connData availability), we default to what we have or null.
    // However, DB connection to master list is usually available via $connData if we include db.php which we did.

    // We need to match Name to DM_Users to get accurate EmployeeID/Department
    // Assuming DM_Users has these columns now
    // Note: lrn_master_list logic removed as we don't have that connection anymore.
    // relying on DM_Users.
    $rowUser = dbQueryOne("SELECT \"EmployeeID\", \"Department\" FROM \"DM_Users\" WHERE \"Name\" = ?", [$managerName]);

    if ($rowUser) {
        $employeeID = $rowUser['EmployeeID'];
        $department = $rowUser['Department'];
    } else {
        // Fallback
        $employeeID = null;
        $department = 'N/A';
    }
}

// If updating, verify ownership
if ($entryID) {
    $existingEntry = dbQueryOne(
        "SELECT ManagerID FROM Manager_Calendar WHERE ID = ?",
        [$entryID]
    );

    if (!$existingEntry) {
        echo json_encode(['success' => false, 'message' => 'Entry not found']);
        exit();
    }

    if ($existingEntry['ManagerID'] != $managerID) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Cannot edit another manager\'s entry']);
        exit();
    }

    // Update existing entry
    $sql = "UPDATE Manager_Calendar 
            SET EntryType = ?, 
                StartTime = ?, 
                EndTime = ?, 
                LeaveNote = ?, 
                UpdatedAt = CURRENT_TIMESTAMP
            WHERE ID = ?";

    $result = dbExecute($sql, [$entryType, $startTime, $endTime, $leaveNote, $entryID]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Entry updated successfully', 'entry_id' => $entryID]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update entry']);
    }
} else {
    // Check if entry already exists for this date
    $existingEntry = dbQueryOne(
        "SELECT ID FROM Manager_Calendar WHERE ManagerID = ? AND EntryDate = ?",
        [$managerID, $entryDate]
    );

    if ($existingEntry) {
        // Update existing entry
        $sql = "UPDATE Manager_Calendar 
                SET EntryType = ?, 
                    StartTime = ?, 
                    EndTime = ?, 
                    LeaveNote = ?, 
                    UpdatedAt = CURRENT_TIMESTAMP
                WHERE ID = ?";

        $result = dbExecute($sql, [$entryType, $startTime, $endTime, $leaveNote, $existingEntry['ID']]);
        $entryID = $existingEntry['ID'];
    } else {
        // Insert new entry
        $sql = "INSERT INTO Manager_Calendar 
                (ManagerID, ManagerName, EmployeeID, Department, EntryDate, EntryType, StartTime, EndTime, LeaveNote)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $result = dbExecute($sql, [
            $managerID,
            $managerName,
            $employeeID,
            $department,
            $entryDate,
            $entryType,
            $startTime,
            $endTime,
            $leaveNote
        ]);

        $entryID = dbLastInsertId();
    }

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Entry saved successfully', 'entry_id' => $entryID]);
    } else {
        global $dbErrorMessage; // from db.php if available via wrapper?
        // Actually dbExecute returns false on error and logs to error_log.
        // We can't easily get the error message unless we change dbExecute to return it or set a global.
        // For now, generic error.
        echo json_encode(['success' => false, 'message' => 'Failed to save entry. Check server logs.']);
    }
}

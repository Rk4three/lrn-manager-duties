<?php
/**
 * Delete Calendar Entry
 * Managers can only delete their own entries
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
$entryID = $_POST['entry_id'] ?? null;

if (!$entryID) {
    echo json_encode(['success' => false, 'message' => 'Entry ID is required']);
    exit();
}

// Verify ownership
$entry = dbQueryOne(
    "SELECT ManagerID FROM Manager_Calendar WHERE ID = ?",
    [$entryID]
);

if (!$entry) {
    echo json_encode(['success' => false, 'message' => 'Entry not found']);
    exit();
}

$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if ($entry['ManagerID'] != $managerID && !$isSuperAdmin) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Cannot delete another manager\'s entry']);
    exit();
}

// Delete the entry
$result = dbExecute("DELETE FROM Manager_Calendar WHERE ID = ?", [$entryID]);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Entry deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete entry']);
}

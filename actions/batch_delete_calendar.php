<?php
/**
 * Batch Delete Calendar Entries
 * Handles deleting multiple calendar entries at once
 * Supports deleting for multiple managers or ALL managers if Super Admin
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

// Parameters
$dates = $_POST['dates'] ?? []; // Array of dates
$deleteScope = $_POST['delete_scope'] ?? 'self'; // 'self', 'specific', 'all'
$targetManagerIDs = $_POST['manager_ids'] ?? []; // Array of IDs, only used if scope is 'specific'

if (empty($dates) || !is_array($dates)) {
    echo json_encode(['success' => false, 'message' => 'No dates selected']);
    exit();
}

// Prepare Date Placeholders
$datePlaceholders = implode(',', array_fill(0, count($dates), '?'));
$params = $dates; // Base params start with dates (we'll prepend/append others as needed depending on query structure)

$sql = "";
$queryParams = [];

if ($isSuperAdmin) {
    if ($deleteScope === 'all') {
        // Delete ALL entries for these dates
        $sql = "DELETE FROM Manager_Calendar WHERE EntryDate IN ($datePlaceholders)";
        $queryParams = $dates;
    } elseif ($deleteScope === 'specific') {
        // Delete for specific managers
        if (empty($targetManagerIDs)) {
            echo json_encode(['success' => false, 'message' => 'No managers selected for deletion']);
            exit();
        }
        $mgrPlaceholders = implode(',', array_fill(0, count($targetManagerIDs), '?'));

        $sql = "DELETE FROM Manager_Calendar WHERE ManagerID IN ($mgrPlaceholders) AND EntryDate IN ($datePlaceholders)";

        // Params: Managers first, then Dates
        $queryParams = array_merge($targetManagerIDs, $dates);
    } else {
        // Default to self or single override
        // Check legacy/single override
        $targetID = $currentManagerID;
        if (isset($_POST['manager_id']) && !empty($_POST['manager_id'])) {
            $targetID = $_POST['manager_id'];
        }

        $sql = "DELETE FROM Manager_Calendar WHERE ManagerID = ? AND EntryDate IN ($datePlaceholders)";
        $queryParams = array_merge([$targetID], $dates);
    }
} else {
    // Normal User - Can only delete their own
    $sql = "DELETE FROM Manager_Calendar WHERE ManagerID = ? AND EntryDate IN ($datePlaceholders)";
    $queryParams = array_merge([$currentManagerID], $dates);
}

// Execute
$result = dbExecute($sql, $queryParams);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Selected entries deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete entries']);
}

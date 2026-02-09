<?php
/**
 * Get Calendar Data for a Manager
 * Returns all calendar entries for a specific month
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
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');

// Validate year and month
if (!is_numeric($year) || !is_numeric($month) || $month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid year or month']);
    exit();
}

// Get first and last day of the month
$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));

// Fetch calendar entries for this manager and month
$sql = "SELECT ID, ManagerID, ManagerName, EmployeeID, Department, 
               EntryDate, EntryType, StartTime, EndTime, LeaveNote,
               CreatedAt, UpdatedAt
        FROM Manager_Calendar
        WHERE ManagerID = ? 
        AND EntryDate >= ? 
        AND EntryDate <= ?
        ORDER BY EntryDate ASC";

$entries = dbQuery($sql, [$managerID, $firstDay, $lastDay]);

// Format the entries for frontend
$formattedEntries = [];
if ($entries) {
    foreach ($entries as $entry) {
        $formattedEntries[] = [
            'id' => $entry['ID'],
            'date' => $entry['EntryDate']->format('Y-m-d'),
            'type' => $entry['EntryType'],
            'start_time' => $entry['StartTime'],
            'end_time' => $entry['EndTime'],
            'leave_note' => $entry['LeaveNote'],
            'display_text' => $entry['EntryType'] === 'WORK'
                ? ($entry['StartTime'] . ' - ' . $entry['EndTime'])
                : $entry['LeaveNote']
        ];
    }
}

echo json_encode([
    'success' => true,
    'entries' => $formattedEntries,
    'month' => $month,
    'year' => $year
]);

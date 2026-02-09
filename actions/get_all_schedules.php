<?php
/**
 * Get All Managers' Schedules for a Month
 * Returns all calendar entries with manager info for team view
 */
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('m'));
$specificDay = $_GET['day'] ?? null; // Optional: for fetching a specific day

// Validate inputs
if ($month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid month']);
    exit();
}

// Calculate date range
$firstDay = sprintf('%04d-%02d-01', $year, $month);
$lastDay = date('Y-m-t', strtotime($firstDay));

// If specific day is requested
if ($specificDay) {
    $specificDate = sprintf('%04d-%02d-%02d', $year, $month, (int) $specificDay);
    $firstDay = $specificDate;
    $lastDay = $specificDate;
}

// Fetch all calendar entries for the month
$sql = "
    SELECT 
        ID,
        ManagerID,
        ManagerName,
        EntryDate,
        EntryType,
        StartTime,
        EndTime,
        LeaveNote,
        CreatedAt,
        EmployeeID,
        Department
    FROM Manager_Calendar
    WHERE EntryDate >= ? AND EntryDate <= ?
    ORDER BY EntryDate ASC, ManagerName ASC
";

$entries = dbQuery($sql, [$firstDay, $lastDay]);

if (!$entries) {
    echo json_encode([
        'success' => true,
        'entries' => [],
        'summary' => [],
        'count' => 0
    ]);
    exit();
}

// Process entries and group by date
$entriesByDate = [];
$summary = [];

foreach ($entries as $entry) {
    // Format date properly as string Y-m-d
    // The driver might return DateTime object or string depending on configuration
    // dbQuery helper suggests it returns array of associative arrays
    // Check if EntryDate is an object
    $dateKey = ($entry['EntryDate'] instanceof DateTime)
        ? $entry['EntryDate']->format('Y-m-d')
        : $entry['EntryDate'];

    // Initialize date array if not exists
    if (!isset($entriesByDate[$dateKey])) {
        $entriesByDate[$dateKey] = [];
        $summary[$dateKey] = [
            'work' => 0,
            'leave' => 0,
            'total' => 0
        ];
    }

    // Add entry to date
    $entriesByDate[$dateKey][] = [
        'id' => $entry['ID'],
        'manager_id' => $entry['ManagerID'],
        'manager_name' => $entry['ManagerName'],
        'employee_id' => $entry['EmployeeID'],
        'department' => $entry['Department'],
        'entry_type' => $entry['EntryType'],
        'start_time' => $entry['StartTime'],
        'end_time' => $entry['EndTime'],
        'leave_note' => $entry['LeaveNote'],
        'created_at' => ($entry['CreatedAt'] instanceof DateTime) ? $entry['CreatedAt']->format('Y-m-d H:i:s') : $entry['CreatedAt'],
        'photo_url' => $entry['EmployeeID'] ? "http://10.2.0.8/lrnph/emp_photos/{$entry['EmployeeID']}.jpg" : null
    ];

    // Update summary counts
    if ($entry['EntryType'] === 'WORK') {
        $summary[$dateKey]['work']++;
    } else {
        $summary[$dateKey]['leave']++;
    }
    $summary[$dateKey]['total']++;
}

// If requesting a specific day, return detailed entries
if ($specificDay) {
    $dateKey = sprintf('%04d-%02d-%02d', $year, $month, (int) $specificDay);
    $dayEntries = $entriesByDate[$dateKey] ?? [];

    echo json_encode([
        'success' => true,
        'date' => $dateKey,
        'entries' => $dayEntries,
        'count' => count($dayEntries),
        'work_count' => $summary[$dateKey]['work'] ?? 0,
        'leave_count' => $summary[$dateKey]['leave'] ?? 0
    ]);
    exit();
}

// Return full month summary
echo json_encode([
    'success' => true,
    'entries_by_date' => $entriesByDate,
    'summary' => $summary,
    'total_entries' => count($entries)
]);

<?php
/**
 * Database Connection Helper
 * Duty Manager Checklist Application
 */

// Connection Configuration
// Connection Configuration
$serverName = getenv('DB_SERVER');
$connectionOptions = [
    "UID" => getenv('DB_USER'),
    "PWD" => getenv('DB_PASS'),
    "Database" => getenv('DB_NAME'),
    "CharacterSet" => "UTF-8",
    "LoginTimeout" => 30
];

// Create connection
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Check connection
if ($conn === false) {
    $dbError = true;
    $dbErrorMessage = print_r(sqlsrv_errors(), true);
} else {
    $dbError = false;
    $dbErrorMessage = null;
}

// Data Connection (SA) for Cross-Database Access (LRNPH, LRNPH_E)
// Data Connection (SA) for Cross-Database Access (LRNPH, LRNPH_E)
// ERROR FIX: Azure Migration - Use same connection details as main DB for now
// The mock tables (lrn_master_list, lrnph_users) are in the SAME database on Azure.
$serverNameData = getenv('DB_SERVER');
$connectionOptionsData = [
    "UID" => getenv('DB_USER'),
    "PWD" => getenv('DB_PASS'),
    "Database" => getenv('DB_NAME'),
    "CharacterSet" => "UTF-8",
    "LoginTimeout" => 30
];

$connData = sqlsrv_connect($serverNameData, $connectionOptionsData);

if ($connData === false) {
    // Fallback or log error? For now just log, preventing immediate death if not needed immediately
    error_log("Data Connection (SA) failed: " . print_r(sqlsrv_errors(), true));
}

/**
 * Execute a query and return results
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @return array|false Results array or false on failure
 */
function dbQuery($sql, $params = [])
{
    global $conn;

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return false;
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = $row;
    }

    sqlsrv_free_stmt($stmt);
    return $results;
}

/**
 * Execute a query and return single row
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @return array|null Single row or null
 */
function dbQueryOne($sql, $params = [])
{
    global $conn;

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row;
}

/**
 * Execute an INSERT/UPDATE/DELETE query
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @return bool Success or failure
 */
function dbExecute($sql, $params = [])
{
    global $conn;

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return false;
    }

    sqlsrv_free_stmt($stmt);
    return true;
}

/**
 * Get last inserted ID
 * @return int|null Last inserted ID
 */
function dbLastInsertId()
{
    global $conn;

    $sql = "SELECT SCOPE_IDENTITY() AS ID";
    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return $row ? (int) $row['ID'] : null;
}

/**
 * Get current Philippines datetime
 * @return DateTime
 */
function getPhilippinesTime()
{
    $tz = new DateTimeZone('Asia/Manila');
    return new DateTime('now', $tz);
}

/**
 * Check if today is Sunday (Philippines time)
 * @return bool
 */
function isSunday()
{
    $now = getPhilippinesTime();
    return $now->format('w') == 0; // 0 = Sunday
}

/**
 * Get current Sunday date (for session tracking)
 * Returns current date if Sunday, or previous Sunday if not
 * @return string Date in Y-m-d format
 */
function getCurrentSundayDate()
{
    $now = getPhilippinesTime();
    $dayOfWeek = (int) $now->format('w');

    if ($dayOfWeek == 0) {
        return $now->format('Y-m-d');
    }

    // Get previous Sunday
    $now->modify('-' . $dayOfWeek . ' days');
    return $now->format('Y-m-d');
}


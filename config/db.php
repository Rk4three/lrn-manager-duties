<?php
/**
 * Database Connection Helper
 * Duty Manager Checklist Application
 * Refactored for PostgreSQL (PDO)
 */

// Connection Configuration
$host = getenv('DB_HOST') ?: 'db'; // Default to 'db' service in Docker
$db = getenv('DB_NAME') ?: 'manager_duties';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: 'password';
$port = getenv('DB_PORT') ?: '5432';

$dsn = "pgsql:host=$host;port=$port;dbname=$db";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $dbError = false;
    $dbErrorMessage = null;
} catch (\PDOException $e) {
    $dbError = true;
    $dbErrorMessage = $e->getMessage();
    $pdo = null;
}

/**
 * Execute a query and return results
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @return array|false Results array or false on failure
 */
function dbQuery($sql, $params = [])
{
    global $pdo;
    if (!$pdo)
        return false;

    // Convert SQL Server syntax to PostgreSQL if needed
    $sql = convertSqlSyntax($sql);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Query Error: " . $e->getMessage() . " SQL: " . $sql);
        return false;
    }
}

/**
 * Execute a query and return single row
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @return array|null Single row or null
 */
function dbQueryOne($sql, $params = [])
{
    global $pdo;
    if (!$pdo)
        return null;

    $sql = convertSqlSyntax($sql);

    // SQL Server TOP 1 -> PostgreSQL LIMIT 1
    // (Handled in convertSqlSyntax or manually here if needed, but fetch() gets one anyway)

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    } catch (PDOException $e) {
        error_log("QueryOne Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Execute an INSERT/UPDATE/DELETE query
 * @param string $sql SQL query
 * @param array $params Parameters for prepared statement
 * @return bool Success or failure
 */
function dbExecute($sql, $params = [])
{
    global $pdo;
    if (!$pdo)
        return false;

    $sql = convertSqlSyntax($sql);

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Execute Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get last inserted ID
 * @return int|null Last inserted ID
 */
function dbLastInsertId()
{
    global $pdo;
    if (!$pdo)
        return null;
    return (int) $pdo->lastInsertId();
}

/**
 * Helper to convert common T-SQL syntax to PgSQL
 * This is a basic converter; complex queries might need manual adjustment.
 */
function convertSqlSyntax($sql)
{
    // Replace TOP n with LIMIT n (Basic implementation)
    if (preg_match('/SELECT\s+TOP\s+(\d+)\s+(.+)/i', $sql, $matches)) {
        $limit = $matches[1];
        $rest = $matches[2];
        $sql = "SELECT $rest LIMIT $limit";
    }

    // Replace GETDATE() with CURRENT_TIMESTAMP
    $sql = str_ireplace('GETDATE()', 'CURRENT_TIMESTAMP', $sql);

    // Replace ISNULL() with COALESCE()
    $sql = str_ireplace('ISNULL(', 'COALESCE(', $sql);

    // Remove brackets [] used in T-SQL
    $sql = str_replace(['[', ']'], '"', $sql);

    // Auto-quote known mixed-case table names (if not already quoted)
    $tables = [
        'DM_Users',
        'DM_Schedules',
        'DM_Checklist_Items',
        'DM_Checklist_Sessions',
        'DM_Checklist_Entries',
        'DM_Checklist_Photos',
        'Manager_Calendar'
    ];
    foreach ($tables as $table) {
        // Match table name NOT already inside quotes
        $sql = preg_replace('/(?<!")\\b' . preg_quote($table, '/') . '\\b(?!")/', '"' . $table . '"', $sql);
    }

    // Auto-quote known mixed-case column names (if not already quoted)
    $columns = [
        'ID',
        'Name',
        'Username',
        'Password',
        'EmployeeID',
        'Department',
        'PhotoURL',
        'Role',
        'IsActive',
        'IsSuperAdmin',
        'CreatedAt',
        'ManagerID',
        'ScheduledDate',
        'Timeline',
        'Area',
        'TaskName',
        'SortOrder',
        'AC_Status',
        'RequiresTemperature',
        'ScheduleID',
        'SessionDate',
        'Status',
        'SubmittedAt',
        'SessionID',
        'ItemID',
        'Shift_Selection',
        'Coordinated',
        'Dept_In_Charge',
        'Remarks',
        'Temperature',
        'UpdatedAt',
        'FilePath',
        'MimeType',
        'UploadedAt',
        'ManagerName',
        'EntryDate',
        'EntryType',
        'StartTime',
        'EndTime',
        'LeaveNote'
    ];
    foreach ($columns as $col) {
        // Match column name NOT already inside quotes, using word boundary
        // We allow dot (.) to precede so that aliases like u.Name become u."Name"
        // But we still block if preceded by " or word char (to avoid partial matches like 'MyName')
        $sql = preg_replace('/(?<!["\w])\b' . preg_quote($col, '/') . '\b(?!")/', '"' . $col . '"', $sql);
    }

    return $sql;
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

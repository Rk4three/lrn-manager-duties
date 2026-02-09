<?php
echo "<h3>Database Connection Test</h3>";

$serverName = getenv('DB_SERVER');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

echo "<b>Server:</b> " . $serverName . "<br>";
echo "<b>Database:</b> " . $dbName . "<br>";
echo "<b>User:</b> " . $dbUser . "<br>";
// Do not print password

if (!$serverName || !$dbName || !$dbUser || !$dbPass) {
    echo "<p style='color:red'>ERROR: One or more environment variables are missing!</p>";
    exit;
}

$connectionOptions = array(
    "Database" => $dbName,
    "Uid" => $dbUser,
    "PWD" => $dbPass,
    "LoginTimeout" => 30 // Increased timeout
);

//Establishes the connection
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn) {
    echo "<p style='color:green'><b>SUCCESS: Connection established!</b></p>";
    echo "<pre>";
    print_r(sqlsrv_server_info($conn));
    echo "</pre>";
} else {
    echo "<p style='color:red'><b>FAILURE: Connection could not be established.</b></p>";
    echo "<pre>";
    die(print_r(sqlsrv_errors(), true));
    echo "</pre>";
}
?>
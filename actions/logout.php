<?php
// logout.php - End user session
session_start();
session_destroy();
header("Location: ../login.php");
exit();
?>
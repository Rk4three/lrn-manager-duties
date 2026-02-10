<?php
// checklist.php - Main Duty Manager Checklist Form
session_start();
// Start output buffering to catch any premature output like whitespace from includes
ob_start();

// Increase upload limits for large photos
ini_set('upload_max_filesize', '30M');
ini_set('post_max_size', '30M');
ini_set('memory_limit', '256M');

require_once 'config/db.php';

// Auto-submit expired checklists (past deadline)
require_once 'actions/auto_submit.php';
autoSubmitExpiredChecklists();

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    header("Location: login.php");
    exit();
}

$userName = $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'User';
$userDept = $_SESSION['dm_department'] ?? $_SESSION['dept'] ?? 'N/A';
$dmUserId = $_SESSION['dm_user_id'];
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

// Get Philippines time
$phTime = getPhilippinesTime();
$today = $phTime->format('Y-m-d');

// Helper to parse remarks (JSON or plain text)
function parseRemarks($remarks) {
    if (empty($remarks)) return ['1st' => '', '2nd' => ''];
    
    $decoded = json_decode($remarks, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return array_merge(['1st' => '', '2nd' => ''], $decoded);
    }
    
    // Legacy: Treat plain text as 1st shift remark
    return ['1st' => $remarks, '2nd' => ''];
}

// 1. Check if specific date requested
$targetDate = $today;
$requestedDate = $_GET['date'] ?? null;

if ($requestedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
    $targetDate = $requestedDate;
} else {
    // 2. Auto-resolve date if not requested
    // Try to find the appropriate schedule
    if ($isSuperAdmin) {
        // Super Admin: Find ANY next schedule
        $nextSched = dbQueryOne("SELECT ScheduledDate FROM DM_Schedules WHERE ScheduledDate >= ? ORDER BY ScheduledDate ASC LIMIT 1", [$today]);
        if ($nextSched) {
            $targetDate = $nextSched['ScheduledDate'];
        }
    } else {
        // Regular User: Find THEIR next schedule
        $myNextSched = dbQueryOne("SELECT ScheduledDate FROM DM_Schedules WHERE ManagerID = ? AND ScheduledDate >= ? ORDER BY ScheduledDate ASC LIMIT 1", [$dmUserId, $today]);
        if ($myNextSched) {
             $targetDate = $myNextSched['ScheduledDate'];
        } else {
            // If they have no personal schedule, check if there is a general session active today that they might need to see (unlikely but safe fallback) or just fallback to today.
             $targetDate = $today;
        }
    }
}

// Get ALL schedules for this Target Date to display in selection or find own
// Get ALL schedules for this Target Date to display in selection or find own
// Note: DM_Users does not have Department column
$scheduleQuery = "SELECT s.ID as ScheduleID, s.ScheduledDate, s.ManagerID, s.Timeline, u.Name as ManagerName
                  FROM DM_Schedules s
                  JOIN DM_Users u ON s.ManagerID = u.ID
                  WHERE s.ScheduledDate = ?";
$allDateSchedules = dbQuery($scheduleQuery, [$targetDate]);

if ($allDateSchedules === false) {
    $allDateSchedules = [];
}

// Determine which schedule to view
$selectedSchedule = null;
$requestedScheduleId = $_GET['schedule_id'] ?? null;

// 1. Try to find requested schedule in today's list
if ($requestedScheduleId) {
    foreach ($allDateSchedules as $s) {
        if ($s['ScheduleID'] == $requestedScheduleId) {
            $selectedSchedule = $s;
            break;
        }
    }
}

// 2. If no valid selection yet, check if Current User is in the list (Auto-select own)
// But only auto-select if we aren't explicitly looking at the "Selection Screen" (e.g. if the user clicked "Back")
// For now, if no ID param, we default to own. Super Admin defaults to Selection Screen (null).
if (!$selectedSchedule && !$isSuperAdmin && empty($_GET['show_selection'])) {
    foreach ($allDateSchedules as $s) {
        if ($s['ManagerID'] == $dmUserId) {
            $selectedSchedule = $s;
            break;
        }
    }
}

$showSelectionScreen = !$selectedSchedule;

// Setup Checkist Environment if a schedule is selected
$sessionId = null;
$sessionStatus = null;
$canEdit = false;
$editBlockReason = null;
$isAssignedManager = false;
$currentManagerName = '';
$currentManagerDept = '';

if ($selectedSchedule) {
    $scheduleId = $selectedSchedule['ScheduleID'];
    $currentManagerName = $selectedSchedule['ManagerName'];
    $currentManagerDept = $selectedSchedule['ManagerDept'] ?? 'Duty Manager'; // Default since col is missing
    
    // Check Permissions
    $isAssignedManager = ($selectedSchedule['ManagerID'] == $dmUserId);
    $canEdit = $isAssignedManager || $isSuperAdmin;
    
    // Check Existing Session for THIS specific schedule
    $sessionQuery = "SELECT ID, Status, SubmittedAt FROM DM_Checklist_Sessions WHERE ScheduleID = ?";
    $existingSession = dbQueryOne($sessionQuery, [$scheduleId]);
    
    if ($existingSession) {
        $sessionId = $existingSession['ID'];
        $sessionStatus = $existingSession['Status'];
        if ($sessionStatus === 'Completed') {
            $canEdit = false; // Completed sessions are locked
        }
    } elseif ($canEdit) {
        // Create new session if it allows editing (User opens their own empty checklist)
        // If Viewer/Admin opens an empty checklist of someone else, do NOT create session, just show view only (empty)
        if ($isAssignedManager || ($isSuperAdmin && $isAssignedManager)) {
             $createSession = "INSERT INTO DM_Checklist_Sessions (ScheduleID, SessionDate, Status) VALUES (?, ?, 'In Progress')";
             dbExecute($createSession, [$scheduleId, $targetDate]);
             $sessionId = dbLastInsertId();
             $sessionStatus = 'In Progress';
        }
    }
    
    // --- Validation Logic (Pending/Future) ---
    if ($canEdit && !$isSuperAdmin) {
        // 1. Check Previous Pending
        $pendingQuery = "SELECT s.ScheduledDate 
                         FROM DM_Schedules s
                         LEFT JOIN DM_Checklist_Sessions cs ON s.ID = cs.ScheduleID
                         WHERE s.ManagerID = ? 
                           AND s.ScheduledDate < ? 
                           AND (cs.Status IS NULL OR cs.Status != 'Completed')
                         ORDER BY s.ScheduledDate ASC
                         LIMIT 1";
        $pendingResult = dbQueryOne($pendingQuery, [$selectedSchedule['ManagerID'], $targetDate]);
        
        if ($pendingResult) {
            $canEdit = false;
            $editBlockReason = "pending_previous";
            $blockDetail = (new DateTime($pendingResult['ScheduledDate']))->format('M j, Y');
            $blockDetailRaw = $pendingResult['ScheduledDate'];
        }
        
        // 2. Future Date Check
        if ($canEdit) {
             $limitDate = clone $phTime;
             $limitDate->modify('+7 days');
             if ($targetDate > $limitDate->format('Y-m-d')) {
                 $canEdit = false;
                 $editBlockReason = "future_date";
             }
        }
    }
}

// Re-calculate assigned manager names for display
$assignedManagerNames = [];
if (isset($allDateSchedules)) {
    foreach ($allDateSchedules as $sched) {
        $assignedManagerNames[] = $sched['ManagerName'];
    }
}

// Get all checklist items
$itemsQuery = "SELECT \"ID\", \"Area\", \"TaskName\", \"SortOrder\", \"AC_Status\", \"RequiresTemperature\" FROM \"DM_Checklist_Items\" 
               WHERE \"IsActive\" = TRUE ORDER BY \"SortOrder\" ASC";
$allItems = dbQuery($itemsQuery, []);

// Get existing entries for this session
$entriesByItem = [];
$photosByItem = []; // [ItemID => [ {ID, Path}, ... ]]

if ($sessionId) {
    // 1. Get main entries
    $entriesQuery = "SELECT ItemID, Shift_Selection, Coordinated, Dept_In_Charge, Remarks, Temperature 
                     FROM DM_Checklist_Entries WHERE SessionID = ?";
    $existingEntries = dbQuery($entriesQuery, [$sessionId]);
    
    foreach ($existingEntries as $entry) {
        $entriesByItem[$entry['ItemID']] = $entry;
    }
    
    // 2. Get photos
    $photosQuery = "SELECT ID, ItemID, MimeType FROM DM_Checklist_Photos WHERE SessionID = ? ORDER BY UploadedAt ASC";
    $photos = dbQuery($photosQuery, [$sessionId]);
    if ($photos) {
        foreach ($photos as $photo) {
            $photosByItem[$photo['ItemID']][] = [
                'ID' => $photo['ID'],
                'Path' => 'actions/serve_image.php?photo_id=' . $photo['ID']
            ];
        }
    }
}

// Group items by area
$itemsByArea = [];
$areas = [];
foreach ($allItems as $item) {
    $area = $item['Area'];
    if (!isset($itemsByArea[$area])) {
        $itemsByArea[$area] = [];
        $areas[] = $area;
    }
    $itemsByArea[$area][] = $item;
}

// Handle form submission via AJAX
// Helper function to send clean JSON response (defined before use)
// Helper function to send clean JSON response (defined before use)
function sendJsonResponse($data) {
    // Suppress errors to prevent them from breaking JSON
    ini_set('display_errors', 0);
    
    // Clear any output buffers to prevent corrupted JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Validate sessionId exists for most actions
    if (!$sessionId && $_POST['action'] !== 'reset_session') {
        sendJsonResponse(['success' => false, 'error' => 'No active session found. SessionID is null.']);
    }
    
    if ($_POST['action'] === 'reset_session') {
        // Allow assigned managers Or super admin to undo submission
        $isManager = false;
        
        // Strictly check if the current user is the manager assigned to this specific session's schedule
        $sessionOwnerCheck = dbQueryOne("SELECT s.ManagerID 
                                         FROM DM_Checklist_Sessions cs 
                                         JOIN DM_Schedules s ON cs.ScheduleID = s.ID 
                                         WHERE cs.ID = ?", [$sessionId]);
                                         
        // If the session owner matches the current user
        if ($sessionOwnerCheck && $sessionOwnerCheck['ManagerID'] == $dmUserId) {
            $isManager = true;
        }

        $canUndo = $isManager || ($isSuperAdmin ?? false);
        
        if ($canUndo) {
            dbExecute("UPDATE DM_Checklist_Sessions SET Status = 'In Progress', SubmittedAt = NULL WHERE ID = ?",
                      [$sessionId]);
            sendJsonResponse(['success' => true]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Permission denied. Only assigned managers can undo.']);
        }
        exit();
    }

    if (!$canEdit) {
        sendJsonResponse(['success' => false, 'error' => 'You do not have permission to edit this checklist.']);
    }
    
    if ($_POST['action'] === 'save_entry') {
        $itemId = (int)$_POST['item_id'];
        $shift = $_POST['shift'] ?? null;
        $coordinated = isset($_POST['coordinated']) && $_POST['coordinated'] === '1' ? 1 : 0;
        $deptInCharge = $_POST['dept_in_charge'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        $temperature = $_POST['temperature'] ?? '';
        
        $existingEntry = dbQueryOne("SELECT ID FROM DM_Checklist_Entries WHERE SessionID = ? AND ItemID = ?", 
                                    [$sessionId, $itemId]);
        
        if ($existingEntry) {
            $updateSql = "UPDATE DM_Checklist_Entries 
                          SET Shift_Selection = ?, Coordinated = ?, Dept_In_Charge = ?, Remarks = ?, Temperature = ?, UpdatedAt = CURRENT_TIMESTAMP
                          WHERE SessionID = ? AND ItemID = ?";
            dbExecute($updateSql, [$shift, $coordinated, $deptInCharge, $remarks, $temperature, $sessionId, $itemId]);
        } else {
            $insertSql = "INSERT INTO DM_Checklist_Entries (SessionID, ItemID, Shift_Selection, Coordinated, Dept_In_Charge, Remarks, Temperature)
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
            dbExecute($insertSql, [$sessionId, $itemId, $shift, $coordinated, $deptInCharge, $remarks, $temperature]);
        }
        
        sendJsonResponse(['success' => true]);
    }
    
    // Wrap all AJAX actions in try-catch for better error handling
    try {
        if ($_POST['action'] === 'upload_image') {
            $itemId = (int)$_POST['item_id'];
            
            if (!isset($_FILES['image'])) {
                sendJsonResponse(['success' => false, 'error' => 'No file uploaded (POST data missing).']);
            }
            
            if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['image']['error'];
            $msg = 'Upload failed: ';
            switch ($err) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $msg .= 'File is too large (Server limit exceeded).';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $msg .= 'File was only partially uploaded.';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $msg .= 'No file was uploaded.';
                    break;
                default:
                    $msg .= 'Unknown error code ' . $err;
            }
            sendJsonResponse(['success' => false, 'error' => $msg]);
        }

        // Check limit (Max 5 photos per item)
        $countQuery = "SELECT COUNT(*) as cnt FROM DM_Checklist_Photos WHERE SessionID = ? AND ItemID = ?";
        $countRes = dbQueryOne($countQuery, [$sessionId, $itemId]);
        if ($countRes['cnt'] >= 5) {
            sendJsonResponse(['success' => false, 'error' => 'Maximum limit of 5 photos per item reached.']);
        }

        // Proceed with success case
        if (true) {
            $fileExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($fileExt, $allowedTypes)) {
                sendJsonResponse(['success' => false, 'error' => 'Invalid file type.']);
            }
            
            // Open stream for uploading (Memory efficient and reliable for sqlsrv)
            $fp = fopen($_FILES['image']['tmp_name'], 'rb');
            $mimeType = mime_content_type($_FILES['image']['tmp_name']) ?: 'application/octet-stream';
            
            if ($fp === false) {
                sendJsonResponse(['success' => false, 'error' => 'Failed to open image stream.']);
            }
            
            // 1. Create Directory Structure: uploads/checklist-{SessionID}/images/
            $baseUploadDir = __DIR__ . '/uploads'; // This is one level up from actions/ check logic? Wait, checklist.php is in root.
            // checklist.php is in /manager-duties/
            $baseUploadDir = __DIR__ . '/uploads'; 
            
            if (!file_exists($baseUploadDir)) {
                mkdir($baseUploadDir, 0777, true);
            }
            
            $sessionDir = $baseUploadDir . "/checklist-" . $sessionId . "/images";
            if (!file_exists($sessionDir)) {
                mkdir($sessionDir, 0777, true);
            }
            
            // 2. Insert record into DB first to get ID (needed for filename)
            // We insert with NULL FilePath first, then update it, OR generate unique name first.
            // Let's generate a temporary unique name, or just insert and update.
            // Inserting first allows us to use the ID in the filename which is cleaner.
            
            // 2. Insert record into DB first to get ID (needed for filename)
            // We use RETURNING ID for PostgreSQL (unquoted to allow case insensitivity matching if column is lowercase)
            $sql = "INSERT INTO DM_Checklist_Photos (SessionID, ItemID, MimeType) 
                    VALUES (?, ?, ?)
                    RETURNING ID";
            
            // dbQueryOne should fetch the row
            $insertedRow = dbQueryOne($sql, [$sessionId, $itemId, $mimeType]);
            
            if ($insertedRow === false) {
                 if ($fp) fclose($fp);
                 sendJsonResponse(['success' => false, 'error' => 'Database save failed']);
            }
            
            // Handle potentially different case from PDO driver
            $newPhotoId = $insertedRow['ID'] ?? $insertedRow['id'] ?? 0;
            // No statement freeing needed for PDO wrapper
            
            if ($newPhotoId > 0) {
                 // 3. Save file to disk
                 $fileName = "image_" . $newPhotoId . "." . $fileExt; // $fileExt derived earlier
                 $fullPath = $sessionDir . "/" . $fileName;
                 $relativePath = "uploads/checklist-" . $sessionId . "/images/" . $fileName;
                 
                            // Rewind stream if needed or just copy from tmp_name since we have it
                 if ($fp) fclose($fp); // Close the stream we opened earlier
                 
                 if (move_uploaded_file($_FILES['image']['tmp_name'], $fullPath)) {
                     // 4. Update DB with FilePath
                     $updateSql = "UPDATE DM_Checklist_Photos SET FilePath = ? WHERE ID = ?";
                     dbExecute($updateSql, [$relativePath, $newPhotoId]);
                 } else {
                     // Failed to move
                     dbExecute("DELETE FROM DM_Checklist_Photos WHERE ID = ?", [$newPhotoId]);
                     sendJsonResponse(['success' => false, 'error' => 'Failed to save file to server directory.']);
                 }
            } else {
                 if ($fp) fclose($fp);
                 sendJsonResponse(['success' => false, 'error' => 'Failed to create database record.']);
            }
            
            
            
            // Ensure Entry exists so checklist item is "active" even if just photo
            $existingEntry = dbQueryOne("SELECT ID FROM DM_Checklist_Entries WHERE SessionID = ? AND ItemID = ?", [$sessionId, $itemId]);
            if (!$existingEntry) {
                dbExecute("INSERT INTO DM_Checklist_Entries (SessionID, ItemID) VALUES (?, ?)", [$sessionId, $itemId]);
            }

            // Return payload suitable for new frontend list
            sendJsonResponse([
                'success' => true, 
                'photo' => [
                    'id' => $newPhotoId,
                    'path' => 'actions/serve_image.php?photo_id=' . $newPhotoId
                ]
            ]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'No file uploaded.']);
        }
        exit();
    }
    
    if ($_POST['action'] === 'remove_image') {
        // Now accepts photo_id (for new logic) or item_id (backward compatibility fallback?)
        // The new frontend will send photo_id.
        
        $photoId = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : null;
        
        if ($photoId && $photoId > 0) {
            // 1. Get file path to delete file
            $photoDef = dbQueryOne("SELECT FilePath FROM DM_Checklist_Photos WHERE ID = ? AND SessionID = ?", [$photoId, $sessionId]);
            
            if ($photoDef && !empty($photoDef['FilePath'])) {
                $filePath = __DIR__ . '/' . $photoDef['FilePath'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // 2. Delete DB Record
            $result = dbExecute("DELETE FROM DM_Checklist_Photos WHERE ID = ? AND SessionID = ?", [$photoId, $sessionId]);
             if ($result) {
                sendJsonResponse(['success' => true]);
            } else {
                
                sendJsonResponse(['success' => false, 'error' => 'Failed to remove image from database.']);
            }
        } else {
             sendJsonResponse(['success' => false, 'error' => 'Invalid photo ID. Received: ' . var_export($_POST['photo_id'] ?? 'NOT SET', true)]);
        }
    }
    
    if ($_POST['action'] === 'finalize') {
        error_log("FINALIZE: SessionID=$sessionId, canEdit=" . ($canEdit ? 'true' : 'false'));
        $result = dbExecute("UPDATE DM_Checklist_Sessions SET Status = 'Completed', SubmittedAt = CURRENT_TIMESTAMP WHERE ID = ?",
                  [$sessionId]);
        error_log("FINALIZE: Update result=" . ($result ? 'true' : 'false'));
        if ($result) {
            sendJsonResponse(['success' => true, 'sessionId' => $sessionId]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to update session status.']);
        }
    }
    
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'error' => 'An unexpected error occurred: ' . $e->getMessage()]);
    }
}

$displayDateObj = new DateTime($targetDate);
$displayDate = $displayDateObj->format('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duty Schedule • Manager</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/favicon.png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            800: '#5b21b6',
                            900: '#4c1d95',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #030014;
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(17, 25, 40, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .glass-header {
            background: rgba(3, 0, 20, 0.8);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .checklist-item.hidden-by-filter { display: none !important; }
        
        .checked-item { 
            background: rgba(16, 185, 129, 0.05);
            border-left: 3px solid #10b981; 
        }
        .unchecked-item { 
            border-left: 3px solid #334155; 
        }
        
        /* Shift Button Styles */
        .shift-btn.selected {
            background: #7c3aed;
            border-color: #7c3aed;
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }
        .shift-btn:not(.selected):not(:disabled):hover {
            background: rgba(124, 58, 237, 0.1);
            border-color: #7c3aed;
        }
        .shift-btn:disabled { opacity: 0.3; cursor: not-allowed; }

        .read-only-mode input:not(.view-only-input), 
        .read-only-mode button:not(.view-only-btn),
        .read-only-mode .image-upload { pointer-events: none; opacity: 0.5; }

        /* Modal Transitions */
        .modal-overlay {
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }
    </style>
</head>
<body class="text-slate-100 min-h-screen selection:bg-brand-500 selection:text-white <?= !$canEdit ? 'read-only-mode' : '' ?>">

    <!-- Navbar -->
    <header class="sticky top-0 z-50 glass-header">
        <div class="max-w-7xl mx-auto px-4 py-3 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="index.php" class="view-only-btn w-10 h-10 rounded-xl bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors">
                        <i class="fas fa-arrow-left text-slate-400"></i>
                    </a>
                    <div>
                        <h1 class="text-lg font-bold text-white leading-none">Duty Checklist</h1>
                        <p class="text-xs text-brand-400 font-medium"><?= $displayDate ?></p>
                    </div>
                </div>
                
                <div class="flex items-center gap-2">
                    <?php if ($canEdit): ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">
                            <i class="fas fa-edit mr-1"></i>Edit Mode
                        </span>
                    <?php elseif ($sessionStatus === 'Completed'): ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-400 border border-blue-500/20">
                            <i class="fas fa-check mr-1"></i>Submitted
                        </span>
                    <?php else: ?>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-slate-500/20 text-slate-400 border border-slate-500/20">
                            <i class="fas fa-eye mr-1"></i>View Only
                        </span>
                    <?php endif; ?>
                    
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1 rounded-full bg-slate-800/50 border border-white/5">
                        <span id="progress-count" class="text-brand-400 font-bold">0</span>
                        <span class="text-slate-500 text-xs">/ <?= count($allItems) ?> Completed</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <?php if ($showSelectionScreen): ?>
            <!-- SELECTION SCREEN -->
            <div class="max-w-2xl mx-auto mt-12 mb-12">
                <div class="glass-card rounded-2xl p-8 text-center border-t border-white/10 relative overflow-hidden shadow-2xl">
                    <div class="absolute inset-0 bg-gradient-to-b from-brand-500/5 to-transparent pointer-events-none"></div>
                    <div class="relative z-10">
                        <div class="w-20 h-20 bg-slate-900 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-xl border border-slate-700">
                            <i class="fas fa-users-cog text-3xl text-brand-400"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-white mb-2">Select Duty Manager</h2>
                        <p class="text-slate-400 mb-8 max-w-md mx-auto text-sm">Multiple managers are scheduled. Please select which checklist you would like to view or update.</p>
                        
                        <div class="grid gap-3 text-left">
                            <?php foreach ($allDateSchedules as $sched): 
                                // Check status for this schedule for a badge
                                $chkStatus = dbQueryOne("SELECT Status FROM DM_Checklist_Sessions WHERE ScheduleID = ?", [$sched['ScheduleID']]);
                                $isDone = ($chkStatus && $chkStatus['Status'] === 'Completed');
                                $isMe = ($sched['ManagerID'] == $dmUserId);
                            ?>
                                <a href="checklist.php?date=<?= $targetDate ?>&schedule_id=<?= $sched['ScheduleID'] ?>" 
                                   class="group relative flex items-center p-4 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 hover:border-brand-500/50 transition-all duration-300 shadow-md">
                                    <div class="w-12 h-12 rounded-full bg-slate-900 flex items-center justify-center text-brand-400 font-bold text-lg border border-slate-700 group-hover:border-brand-500/30 transition-colors shrink-0">
                                        <?= substr($sched['ManagerName'], 0, 1) ?>
                                    </div>
                                    <div class="ml-4 min-w-0 flex-1">
                                        <h3 class="text-white font-bold text-sm group-hover:text-brand-300 transition-colors flex items-center gap-2 truncate">
                                            <?= htmlspecialchars($sched['ManagerName']) ?>
                                            <?php if ($isMe): ?>
                                                <span class="text-[10px] bg-brand-500/20 text-brand-300 px-1.5 py-0.5 rounded border border-brand-500/20">ME</span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($sched['ManagerDept'] ?? 'Duty Manager') ?></p>
                                    </div>
                                    <div class="ml-auto flex items-center gap-3 shrink-0">
                                        <?php if ($isDone): ?>
                                            <span class="text-xs text-emerald-400 font-medium bg-emerald-500/10 px-2 py-1 rounded border border-emerald-500/20"><i class="fas fa-check mr-1"></i>Done</span>
                                        <?php else: ?>
                                            <span class="w-8 h-8 rounded-full bg-slate-700/50 flex items-center justify-center text-slate-500 group-hover:text-white group-hover:bg-brand-600 transition-all"><i class="fas fa-chevron-right text-xs"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            
                            <?php if (count($allDateSchedules) === 0): ?>
                                <div class="text-center p-6 text-slate-500 italic">No schedules found for this date.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- CHECKLIST VIEW -->
            
            <!-- Info Banner -->
            <div class="glass-card rounded-2xl p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-start gap-4 flex-1">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-700 flex items-center justify-center shadow-lg shadow-brand-500/20 shrink-0">
                        <i class="fas fa-user-tie text-white text-xl"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-slate-400 uppercase tracking-wider font-semibold mb-1">Assigned Manager</p>
                        <h2 class="text-lg font-bold text-white leading-tight mb-1"><?= htmlspecialchars($currentManagerName) ?></h2>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-brand-300 bg-brand-500/10 px-2 py-0.5 rounded border border-brand-500/10">
                                <?= htmlspecialchars($currentManagerDept ?? 'Duty Manager') ?>
                            </span>
                             <?php if (count($allDateSchedules) > 1): ?>
                                <a href="checklist.php?date=<?= $targetDate ?>&show_selection=1" class="text-xs text-slate-400 hover:text-white underline ml-2 decoration-slate-600 underline-offset-2 transition-colors">Switch Manager</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!$canEdit && !$isAssignedManager): ?>
                    <div class="px-4 py-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-sm flex items-center gap-2">
                        <i class="fas fa-lock"></i>
                        <span>Read Only Mode</span>
                    </div>
                <?php elseif ($sessionStatus === 'Completed'): ?>
                    <div class="flex items-center gap-3">
                        <span class="text-emerald-400 text-sm font-medium"><i class="fas fa-check-circle mr-1"></i>All done!</span>
                        <?php if ($isAssignedManager || $isSuperAdmin): ?>
                            <button id="undo-btn" onclick="resetSession()" class="view-only-btn px-4 py-2 hover:bg-rose-500/20 border border-rose-500/30 rounded-lg text-sm text-rose-400 transition-colors">
                                <i class="fas fa-undo mr-1"></i>Undo
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        <!-- Filter Bar -->
        <div class="sticky top-20 z-40 glass-card rounded-xl p-2 mb-6">
            <div class="flex flex-wrap items-center gap-2">
                
                <!-- Area Select -->
                <div class="relative">
                    <select id="filter-area" class="view-only-input appearance-none bg-slate-800 border border-slate-700 hover:border-brand-500 rounded-lg pl-3 pr-8 py-2 text-sm text-white focus:outline-none focus:ring-2 focus:ring-brand-500 transition-all cursor-pointer">
                        <option value="all">All Areas</option>
                        <?php foreach ($areas as $area): ?>
                            <option value="<?= htmlspecialchars($area) ?>"><?= htmlspecialchars($area) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-400">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                </div>
                
                <!-- Status Toggles -->
                <div class="flex bg-slate-800 rounded-lg p-1 border border-slate-700">
                    <button class="view-only-btn filter-btn active px-3 py-1.5 rounded-md text-xs font-medium text-slate-400 hover:text-white [&.active]:bg-brand-600 [&.active]:text-white transition-all" data-filter="all">All</button>
                    <button class="view-only-btn filter-btn px-3 py-1.5 rounded-md text-xs font-medium text-slate-400 hover:text-white [&.active]:bg-brand-600 [&.active]:text-white transition-all" data-filter="checked">Done</button>
                    <button class="view-only-btn filter-btn px-3 py-1.5 rounded-md text-xs font-medium text-slate-400 hover:text-white [&.active]:bg-brand-600 [&.active]:text-white transition-all" data-filter="unchecked">Pending</button>
                </div>
                
                <!-- Search -->
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"></i>
                        <input type="text" id="filter-search" placeholder="Search tasks..." 
                               class="view-only-input w-full bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-lg pl-9 pr-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-all">
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile & Tablet Portrait Layout (Cards) -->
        <div class="mobile-cards space-y-6 lg:hidden">
            <?php foreach ($itemsByArea as $area => $items): ?>
                <div class="area-section" data-area="<?= htmlspecialchars($area) ?>">
                    <h3 class="text-brand-400 text-sm font-bold uppercase tracking-wider mb-3 pl-1 border-l-4 border-brand-500 ml-1">
                        <?= htmlspecialchars($area) ?>
                    </h3>
                    
                    <div class="space-y-3">
                        <?php 
                        // Initialize counter if not set
                        if (!isset($globalItemIndex)) $globalItemIndex = 1;
                        
                        foreach ($items as $item): 
                            $entry = $entriesByItem[$item['ID']] ?? null;
                            $hasShift = $entry && !empty($entry['Shift_Selection']);
                            $isCoordinated = ($entry['Coordinated'] ?? 0) == 1;
                            $itemClass = $hasShift ? 'checked-item' : 'unchecked-item';
                            $currentNumber = $globalItemIndex++;
                        ?>
                            <div class="checklist-item glass-card rounded-xl p-4 <?= $itemClass ?>" 
                                 data-item-id="<?= $item['ID'] ?>" 
                                 data-area="<?= htmlspecialchars($area) ?>"
                                 data-task="<?= htmlspecialchars(strtolower($item['TaskName'])) ?>"
                                 data-checked="<?= $hasShift ? '1' : '0' ?>">
                                
                                <div class="flex items-start justify-between gap-2 mb-4">
                                    <p class="text-white font-medium"><span class="text-brand-400 font-bold mr-1"><?= $currentNumber ?>.</span><?= htmlspecialchars($item['TaskName']) ?></p>
                                    <?php if (!empty($item['AC_Status'])): 
                                        $acStatus = $item['AC_Status'];
                                        $displayAcStatus = str_ireplace(['Yes', 'No'], ['On', 'Off'], $acStatus);
                                        $acClass = 'bg-slate-700/50 text-slate-400 border border-slate-600/50'; // Default
                                        
                                        if (stripos($acStatus, 'Yes') !== false) {
                                            $acClass = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                                        } elseif (stripos($acStatus, 'No') !== false) {
                                            $acClass = 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
                                        }
                                    ?>
                                        <span class="shrink-0 inline-flex items-center justify-center px-2 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider <?= $acClass ?>">
                                            <?= htmlspecialchars($displayAcStatus) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php 
                                $showTemp = ($item['RequiresTemperature'] ?? 0) == 1 || 
                                            stripos($item['TaskName'], 'Chiller') !== false || 
                                            stripos($item['TaskName'], 'Freezer') !== false;
                                
                                if ($showTemp): ?>
                                    <div class="mb-4">
                                        <label class="block text-xs text-slate-400 mb-1.5 font-medium">Temperature</label>
                                        <input type="text" 
                                               class="temp-input w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none transition-all"
                                               data-item="<?= $item['ID'] ?>"
                                               value="<?= htmlspecialchars($entry['Temperature'] ?? '') ?>"
                                               placeholder="Enter Temperature..."
                                               <?= !$canEdit ? 'readonly' : '' ?>>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Shift Buttons -->
                                <div class="grid grid-cols-3 gap-2 mb-4">
                                    <?php 
                                    $selectedShift = $entry['Shift_Selection'] ?? null;
                                    foreach (['1st', '2nd'] as $shift): 
                                        $isSelected = $selectedShift === $shift;
                                        $isDisabled = !$canEdit;
                                    ?>
                                        <button type="button" 
                                                class="shift-btn py-2 rounded-lg border border-slate-600 text-slate-400 text-xs font-bold uppercase tracking-wide transition-all <?= $isSelected ? 'selected' : '' ?>" 
                                                data-item="<?= $item['ID'] ?>"
                                                data-shift="<?= $shift ?>"
                                                <?= $isDisabled ? 'disabled' : '' ?>>
                                            <?= $shift ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Inputs -->
                                <div class="space-y-3">
                                    <label class="group flex items-center gap-3 p-3 rounded-xl bg-slate-800/40 border border-white/5 hover:bg-slate-800 duration-200 cursor-pointer transition-all">
                                        <div class="relative flex items-center">
                                            <input type="checkbox" 
                                                   class="peer appearance-none w-5 h-5 rounded-md border-2 border-slate-600 bg-slate-700 checked:bg-brand-500 checked:border-brand-500 transition-all cursor-pointer coordinated-check"
                                                   data-item="<?= $item['ID'] ?>"
                                                   <?= $isCoordinated ? 'checked' : '' ?>
                                                   <?= !$canEdit ? 'disabled' : '' ?>>
                                            <i class="fas fa-check absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-white text-[10px] opacity-0 peer-checked:opacity-100 transition-opacity pointer-events-none"></i>
                                        </div>
                                        <span class="text-sm font-medium text-slate-400 group-hover:text-slate-200 transition-colors select-none">Coordinated with Dept</span>
                                    </label>
                                    
                                    <div class="grid grid-cols-1 gap-3">
                                        <!-- Department Input (Full Width on Mobile/Tablet) -->
                                        <div class="w-full">
                                            <label class="block text-xs text-slate-400 mb-1.5 font-medium">Department</label>
                                            <input type="text" 
                                                   class="dept-input w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white placeholder-slate-600 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none transition-all"
                                                   data-item="<?= $item['ID'] ?>"
                                                   value="<?= htmlspecialchars($entry['Dept_In_Charge'] ?? '') ?>"
                                                   placeholder="Department In Charge"
                                                   <?= !$canEdit ? 'readonly' : '' ?>>
                                        </div>
                                               
                                            <!-- Remarks Input (Full Width on Mobile/Tablet) -->
                                        <div class="w-full">
                                            <label class="block text-xs text-slate-400 mb-1.5 font-medium">Remarks</label>
                                            <div class="space-y-2">
                                                <?php 
                                                $parsedRemarks = parseRemarks($entry['Remarks'] ?? '');
                                                foreach (['1st', '2nd'] as $shift): 
                                                ?>
                                                    <input type="text" 
                                                           class="remarks-input w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-3 text-xs text-white placeholder-slate-600 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none transition-all"
                                                           data-item="<?= $item['ID'] ?>"
                                                           data-shift-remark="<?= $shift ?>"
                                                           value="<?= htmlspecialchars($parsedRemarks[$shift]) ?>"
                                                           placeholder="<?= $shift ?> Shift Remark..."
                                                           <?= !$canEdit ? 'readonly' : '' ?>>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Photo Upload Section (Mobile/Tablet Cards) -->
                                    <?php 
                                    $itemPhotos = $photosByItem[$item['ID']] ?? [];
                                    $photoCount = count($itemPhotos);
                                    ?>
                                    <div class="flex flex-col gap-2 pt-3 mt-3 border-t border-slate-700/50">
                                        <div class="flex items-center justify-between">
                                            <label class="text-xs text-slate-400 font-medium">Photos (<?= $photoCount ?>/5)</label>
                                            
                                            <?php if ($canEdit && $photoCount < 5): ?>
                                                <div class="flex items-center gap-2">
                                                    <button type="button" onclick="triggerGallery(<?= $item['ID'] ?>)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600 flex items-center gap-2 transition-colors border border-slate-700 text-slate-300 hover:text-white" title="Attach Photo">
                                                        <i class="fas fa-paperclip text-xs"></i>
                                                        <span class="text-xs font-medium">Attach</span>
                                                    </button>
                                                    <button type="button" onclick="triggerCamera(<?= $item['ID'] ?>)" class="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-500 flex items-center gap-2 transition-colors text-white shadow-lg shadow-brand-600/20" title="Take Photo">
                                                        <i class="fas fa-camera text-xs"></i>
                                                        <span class="text-xs font-medium">Camera</span>
                                                    </button>
                                                    
                                                    <!-- Hidden Inputs - Keep persistent per item -->
                                                    <input type="file" class="hidden image-input-camera" data-item="<?= $item['ID'] ?>" accept="image/*" capture="environment">
                                                    <input type="file" class="hidden image-input-gallery" data-item="<?= $item['ID'] ?>" accept="image/*">
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Photo Grid -->
                                        <div id="preview-<?= $item['ID'] ?>-mobile" class="flex flex-wrap gap-2 mt-1">
                                            <?php 
                                            $itemPhotos = $photosByItem[$item['ID']] ?? [];
                                            if (!empty($itemPhotos)): 
                                            ?>
                                                <?php foreach ($itemPhotos as $photo): 
                                                     $imgSrc = 'actions/serve_image.php?photo_id=' . $photo['ID'];
                                                     // Debug: Ensure ID exists
                                                     if (!isset($photo['ID'])) {
                                                        
                                                     }
                                                ?>
                                                    <!-- Photo ID: <?= $photo['ID'] ?? 'UNKNOWN' ?> -->
                                                    <div class="relative group w-16 h-16 rounded-lg overflow-hidden border border-slate-700 photo-wrapper" data-photo-id="<?= $photo['ID'] ?>">
                                                        <img src="<?= $imgSrc ?>" class="w-full h-full object-cover" onclick="openImageModal('<?= $imgSrc ?>')">
                                                        <?php if ($canEdit): ?>
                                                            <button type="button" onclick="removeImage('<?= $item['ID'] ?>', '<?= $photo['ID'] ?>')" class="absolute top-0 right-0 p-1 bg-black/50 hover:bg-rose-500 text-white transition-colors rounded-bl-lg backdrop-blur-sm">
                                                                <i class="fas fa-times text-[10px]"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php elseif (!$canEdit): ?>
                                                 <span class="text-slate-600 text-xs italic">No photos attached</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Tablet Landscape & Desktop Layout (Table) -->
        <div class="desktop-table hidden lg:block">
            <div class="glass-card rounded-2xl overflow-hidden shadow-2xl">
                <!-- Horizontal scroll wrapper for tablet -->
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-900/80 border-b border-white/5 text-xs uppercase tracking-wider text-slate-400 font-semibold sticky top-0 z-10 glass-header">
                            <tr>
                                <th class="text-center py-4 px-2 w-[40px]">#</th>
                                <th class="text-left py-4 px-4 pl-2">Task Description</th>
                                <th class="text-center py-4 px-2 w-[100px]">AC Config</th>
                                <th class="text-center py-4 px-2 w-[140px]">Shift</th>
                                <th class="text-center py-4 px-2 w-[70px]">Coord</th>
                                <th class="text-left py-4 px-2 w-[180px]">Dept</th>
                                <th class="text-left py-4 px-2 w-[220px]">Remarks</th>
                                <th class="text-center py-4 px-2 w-[70px]">Photo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php 
                        if (!isset($desktopItemIndex)) $desktopItemIndex = 1;
                        foreach ($itemsByArea as $area => $items): 
                        ?>
                            <!-- Area Header Row -->
                            <tr class="bg-slate-800/50 border-b border-white/5">
                                <td colspan="8" class="py-3 px-6 text-sm font-bold text-brand-400 uppercase tracking-wider">
                                    <?= htmlspecialchars($area) ?>
                                </td>
                            </tr>
                            
                            <?php foreach ($items as $item): 
                                $entry = $entriesByItem[$item['ID']] ?? null;
                                $hasShift = $entry && !empty($entry['Shift_Selection']);
                                $selectedShift = $entry['Shift_Selection'] ?? null;
                                $isCoordinated = ($entry['Coordinated'] ?? 0) == 1;
                                
                                $hasImage = !empty($entry['ImageData']) || !empty($entry['ImagePath']);
                                $imgSrc = '';
                                if ($hasImage) {
                                    $imgSrc = !empty($entry['ImageData']) 
                                        ? 'actions/serve_image.php?session=' . $sessionId . '&item=' . $item['ID']
                                        : htmlspecialchars($entry['ImagePath']);
                                }
                            ?>
                                <tr class="checklist-item hover:bg-slate-800/30 transition-colors <?= $hasShift ? 'bg-emerald-500/5' : '' ?>"
                                    data-item-id="<?= $item['ID'] ?>"
                                    data-area="<?= htmlspecialchars($area) ?>"
                                    data-task="<?= htmlspecialchars(strtolower($item['TaskName'])) ?>"
                                    data-checked="<?= $hasShift ? '1' : '0' ?>">
                                    
                                    <td class="py-4 px-2 align-middle text-center text-slate-400 font-bold text-xs">
                                        <?= $desktopItemIndex++ ?>
                                    </td>
                                    
                                    <td class="py-4 px-4 pl-2 align-middle">
                                        <div class="flex flex-col gap-2">
                                            <p class="text-sm text-slate-200 leading-relaxed font-medium" title="<?= htmlspecialchars($item['TaskName']) ?>"><?= htmlspecialchars($item['TaskName']) ?></p>
                                            <?php 
                                            $showTemp = ($item['RequiresTemperature'] ?? 0) == 1 || 
                                                        stripos($item['TaskName'], 'Chiller') !== false || 
                                                        stripos($item['TaskName'], 'Freezer') !== false;
                                            
                                            if ($showTemp): ?>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs text-cyan-400 font-medium">Temp:</span>
                                                    <input type="text" 
                                                           class="w-20 h-7 bg-slate-900/50 border border-slate-700 rounded px-2 text-xs text-center text-white placeholder-slate-600 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none transition-all temp-input"
                                                           data-item="<?= $item['ID'] ?>"
                                                           value="<?= htmlspecialchars($entry['Temperature'] ?? '') ?>"
                                                           placeholder="°C"
                                                           <?= !$canEdit ? 'readonly' : '' ?>>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="py-4 px-2 text-center align-middle">
                                        <?php if (!empty($item['AC_Status'])): 
                                            $acStatus = $item['AC_Status'];
                                            $displayAcStatus = str_ireplace(['Yes', 'No'], ['On', 'Off'], $acStatus);
                                            $acClass = 'bg-slate-700/50 text-slate-400 border border-slate-600/50'; // Default
                                            
                                            if (stripos($acStatus, 'Yes') !== false) {
                                                $acClass = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
                                            } elseif (stripos($acStatus, 'No') !== false) {
                                                $acClass = 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
                                            }
                                        ?>
                                            <span class="inline-flex items-center justify-center px-2 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider <?= $acClass ?>">
                                                <?= htmlspecialchars($displayAcStatus) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="py-4 px-2 text-center align-middle">
                                        <div class="flex gap-1 justify-center items-center h-full">
                                            <?php foreach (['1st', '2nd'] as $shift): 
                                                $isSelected = $selectedShift === $shift;
                                                $isDisabled = !$canEdit;
                                            ?>
                                                <button type="button" 
                                                        class="shift-btn w-9 h-7 rounded-md border border-slate-600 text-[10px] font-bold transition-all flex items-center justify-center <?= $isSelected ? 'selected' : 'text-slate-400 hover:text-white' ?>" 
                                                        data-item="<?= $item['ID'] ?>"
                                                        data-shift="<?= $shift ?>"
                                                        <?= $isDisabled ? 'disabled' : '' ?>>
                                                    <?= $shift ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="py-4 px-2 text-center align-middle">
                                        <div class="flex justify-center items-center h-full">
                                            <div class="relative flex items-center justify-center">
                                                <input type="checkbox" 
                                                       class="peer appearance-none w-5 h-5 rounded-md border-2 border-slate-600 bg-slate-800/50 checked:bg-brand-500 checked:border-brand-500 hover:border-brand-400 transition-all cursor-pointer coordinated-check"
                                                       data-item="<?= $item['ID'] ?>"
                                                       <?= $isCoordinated ? 'checked' : '' ?>
                                                       <?= !$canEdit ? 'disabled' : '' ?>>
                                                <i class="fas fa-check absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-white text-[10px] opacity-0 peer-checked:opacity-100 transition-opacity pointer-events-none"></i>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td class="py-4 px-2 align-middle">
                                        <input type="text" 
                                               class="dept-input w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white placeholder-slate-600 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none transition-all"
                                               data-item="<?= $item['ID'] ?>"
                                               value="<?= htmlspecialchars($entry['Dept_In_Charge'] ?? '') ?>"
                                               placeholder="Dept..."
                                               <?= !$canEdit ? 'readonly' : '' ?>>
                                    </td>
                                    
                                    <td class="py-4 px-2 align-middle">
                                        <div class="space-y-1">
                                            <?php 
                                            $parsedRemarks = parseRemarks($entry['Remarks'] ?? '');
                                            foreach (['1st', '2nd'] as $shift): 
                                            ?>
                                                <input type="text" 
                                                       class="remarks-input w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white placeholder-slate-600 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none transition-all"
                                                       data-item="<?= $item['ID'] ?>"
                                                       data-shift-remark="<?= $shift ?>"
                                                       value="<?= htmlspecialchars($parsedRemarks[$shift]) ?>"
                                                       placeholder="<?= $shift ?>..."
                                                       title="<?= $shift ?> Shift Remark"
                                                       <?= !$canEdit ? 'readonly' : '' ?>>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="py-4 px-2 text-center align-middle">
                                        <?php 
                                        $itemPhotos = $photosByItem[$item['ID']] ?? [];
                                        $photoCount = count($itemPhotos);
                                        ?>
                                        <div class="flex flex-col items-center gap-2">
                                            <?php if ($canEdit && $photoCount < 5): ?>
                                                <div class="flex items-center justify-center gap-1">
                                                    <button type="button" onclick="triggerGallery(<?= $item['ID'] ?>)" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-indigo-600 flex items-center justify-center transition-colors border border-slate-700 hover:border-indigo-500 text-slate-400 hover:text-white" title="Attach Photo">
                                                        <i class="fas fa-paperclip text-xs"></i>
                                                    </button>
                                                    <button type="button" onclick="triggerCamera(<?= $item['ID'] ?>)" class="w-8 h-8 rounded-lg bg-brand-600 hover:bg-brand-500 flex items-center justify-center transition-colors text-white shadow-lg shadow-brand-600/20" title="Take Photo">
                                                        <i class="fas fa-camera text-xs"></i>
                                                    </button>
                                                </div>
                                                <!-- Desktop Inputs (Mirrors Mobile) -->
                                                <input type="file" class="hidden image-input-camera" data-item="<?= $item['ID'] ?>" accept="image/*" capture="environment">
                                                <input type="file" class="hidden image-input-gallery" data-item="<?= $item['ID'] ?>" accept="image/*">
                                            <?php endif; ?>
                                            
                                            
                                            <div id="preview-<?= $item['ID'] ?>" class="flex flex-wrap justify-center gap-1 max-w-[80px]">
                                                <?php 
                                                $itemPhotos = $photosByItem[$item['ID']] ?? [];
                                                foreach ($itemPhotos as $photo): 
                                                     $imgSrc = 'actions/serve_image.php?photo_id=' . $photo['ID'];
                                                ?>
                                                    <div class="relative group w-8 h-8 rounded overflow-hidden border border-slate-700/50 photo-wrapper" data-photo-id="<?= $photo['ID'] ?>">
                                                        <img src="<?= $imgSrc ?>" class="w-full h-full object-cover cursor-pointer" onclick="openImageModal('<?= $imgSrc ?>')">
                                                        <?php if ($canEdit): ?>
                                                            <button type="button" onclick="removeImage('<?= $item['ID'] ?>', '<?= $photo['ID'] ?>')" class="absolute top-0 right-0 w-3 h-3 bg-black/50 hover:bg-rose-500 text-white flex items-center justify-center rounded-bl backdrop-blur-sm" title="Remove">
                                                                <i class="fas fa-times text-[8px]"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (empty($itemPhotos) && !$canEdit): ?>
                                                    <span class="text-slate-700 text-xs">-</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- end overflow-x-auto -->
            </div>
        </div>

        <?php if ($canEdit): ?>
            <div class="fixed bottom-0 left-0 right-0 p-6 bg-slate-950/90 backdrop-blur border-t border-white/10 z-50">
                <div class="max-w-7xl mx-auto flex items-center justify-center gap-4">
                    <a href="history.php?export=1&session_id=<?= $sessionId ?>" target="_blank" class="w-full sm:w-auto px-6 py-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-medium shadow-lg shadow-slate-900/20 flex items-center justify-center gap-3 transition-colors border border-white/10">
                        <i class="fas fa-file-pdf text-rose-400"></i>
                        <span>Export PDF</span>
                    </a>
                    
                    <button id="submit-btn" class="w-full sm:w-auto sm:min-w-[300px] btn-primary py-4 rounded-xl bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-bold shadow-xl shadow-brand-600/20 flex items-center justify-center gap-3 transform hover:-translate-y-1 transition-all">
                        <i class="fas fa-check-circle text-lg"></i>
                        <span>Submit Checklist</span>
                    </button>
                </div>
            </div>
            <div class="h-24"></div>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="max-w-7xl mx-auto px-6 py-16 border-t border-white/5">
        <div class="flex flex-col items-center gap-8 opacity-80 hover:opacity-100 transition-opacity">
            <img src="assets/img/footer.png" alt="La Rose Noire" class="h-24 grayscale invert opacity-80">
            <p class="text-slate-500 text-lg text-center">
                © <?= date('Y') ?> La Rose Noire Philippines • Facilities Management Department
            </p>
        </div>
    </footer>

    <!-- Modals -->
    <div id="image-modal" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/95 backdrop-blur-sm" onclick="closeImageModal()">
        <div class="relative max-w-5xl w-full p-4 flex flex-col items-center">
            <button onclick="closeImageModal()" class="absolute top-4 right-4 w-10 h-10 bg-white/10 hover:bg-white/20 rounded-full flex items-center justify-center text-white transition-all">
                <i class="fas fa-times"></i>
            </button>
            <img id="modal-image" src="" class="max-w-full max-h-[85vh] rounded-xl shadow-2xl border border-white/10" onclick="event.stopPropagation()">
        </div>
    </div>

    <div id="confirm-modal" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-card rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-white/10 scale-100">
            <div class="text-center">
                <div class="w-16 h-16 bg-brand-600/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-clipboard-check text-3xl text-brand-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Submit Checklist?</h3>
                <p class="text-slate-400 text-sm mb-8">You can't edit this after submission unless an admin reverts it.</p>
                <div class="flex gap-3">
                    <button onclick="closeConfirmModal()" class="flex-1 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">Cancel</button>
                    <button onclick="finalizeChecklist()" class="flex-1 py-3 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-sm font-bold shadow-lg shadow-brand-600/25 transition-all">Submit</button>
                </div>
            </div>
        </div>
    </div>

    <div id="success-modal" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-card rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-white/10">
            <div class="text-center">
                <div class="w-16 h-16 bg-emerald-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-check text-3xl text-emerald-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Submitted!</h3>
                <p class="text-slate-400 text-sm mb-8">Your checklist has been saved successfully.</p>
                <a href="index.php" class="block w-full py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/25 transition-all text-center">Return to Dashboard</a>
            </div>
        </div>
    </div>

    <div id="error-modal" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-card rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-rose-500/20">
            <div class="text-center">
                <div class="w-16 h-16 bg-rose-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-3xl text-rose-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Error</h3>
                <p class="text-slate-400 text-sm mb-8" id="error-message">Something went wrong.</p>
                <button onclick="closeErrorModal()" class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">Okay</button>
            </div>
        </div>
    </div>

    <!-- Block Reason Modal -->
    <div id="block-reason-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="glass-card rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-amber-500/30 transform scale-95 transition-transform duration-300">
            <div class="text-center">
                <div class="w-16 h-16 bg-amber-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-lock text-3xl text-amber-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Edit Locked</h3>
                <p class="text-slate-400 text-sm mb-6" id="block-reason-text">
                    You cannot edit this checklist yet.
                </p>
                <div class="flex gap-3" id="block-modal-actions">
                    <!-- Dynamic Buttons -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Check for block reason on load
        <?php if ($editBlockReason): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const reason = "<?= $editBlockReason ?>";
                const dateRaw = "<?= $blockDetailRaw ?? '' ?>";
                
                let message = "You cannot edit this checklist.";
                // Single action: Return to Dashboard
                let buttons = `
                    <a href="index.php" class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-home"></i> Return to Dashboard
                    </a>
                `;
                
                if (reason === 'pending_previous') {
                    message = "Please complete and submit your previous checklist (<?= $blockDetail ?? 'Previous' ?>) before starting this one.";
                } else if (reason === 'future_date') {
                    message = "This schedule is more than 7 days in the future. You cannot start it yet.";
                }
                
                document.getElementById('block-reason-text').textContent = message;
                document.getElementById('block-modal-actions').innerHTML = buttons;
                showBlockModal();
            });
        <?php endif; ?>

        function showBlockModal() {
            const modal = document.getElementById('block-reason-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        function closeBlockModal() {
            const modal = document.getElementById('block-reason-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 200);
        }
    </script>

    <script>const canEdit = <?= $canEdit ? 'true' : 'false' ?>;</script>
    <script src="assets/js/script.js"></script>
    <script>
        function resetSession() {
            if (!confirm('Are you sure you want to UNDO the submission? This will allow editing again.')) return;
            
            const formData = new FormData();
            formData.append('action', 'reset_session');
            
            fetch('checklist.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred.');
            });
        }
    </script>
</body>
</html>

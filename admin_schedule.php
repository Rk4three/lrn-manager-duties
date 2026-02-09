<?php
// admin_schedule.php - Super Admin Schedule Management
session_start();
require_once 'config/db.php';

// Auto-submit expired checklists (past deadline)
require_once 'actions/auto_submit.php';
autoSubmitExpiredChecklists();

// Check if user is logged in and is Super Admin
if (!isset($_SESSION['dm_user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['is_super_admin']) || !$_SESSION['is_super_admin']) {
    header("Location: index.php");
    exit();
}

$userName = $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'User';
$phTime = getPhilippinesTime();

// 1. Get all managers from local DM_Users (User: rkudo)
$managersQuery = "SELECT ID, Name FROM DM_Users WHERE IsActive = 1 ORDER BY Name ASC";
$managers = dbQuery($managersQuery, []);

if ($managers === false) {
    die("Error fetching managers: " . print_r(sqlsrv_errors(), true));
}

// 2. Fetch Department info from LRNPH_E.dbo.lrn_master_list (User: sa)
// Optimization: Fetch all needed departments in one go or per-user?
// Given list is small (managers), per-user or bulk fetch is fine. Let's do bulk fetch of all master list since we can't JOIN easily on Name.
// Or better: Collect names, query master list for those names.
$managerNames = array_map(function ($m) {
    return $m['Name'];
}, $managers);

// Clean names for SQL IN clause (handle quotes)
$cleanNames = array_map(function ($n) {
    return str_replace("'", "''", $n);
}, $managerNames);
$nameListStr = "'" . implode("', '", $cleanNames) . "'";

$departments = [];
if (!empty($cleanNames)) {
    $deptQuery = "SELECT FirstName + ' ' + LastName as FullName, Department 
                  FROM lrn_master_list 
                  WHERE (FirstName + ' ' + LastName) IN ($nameListStr)";

    $stmtDept = sqlsrv_query($connData, $deptQuery);
    if ($stmtDept) {
        while ($row = sqlsrv_fetch_array($stmtDept, SQLSRV_FETCH_ASSOC)) {
            // Normalize name key
            $departments[$row['FullName']] = $row['Department'];
        }
    }
}

// 3. Merge Departments into Managers array
foreach ($managers as &$mgr) {
    if (isset($departments[$mgr['Name']])) {
        $mgr['Department'] = $departments[$mgr['Name']];
    } else {
        // Fallback: Try case-insensitive matching if direct match fails
        $found = false;
        foreach ($departments as $dName => $dept) {
            if (strcasecmp($dName, $mgr['Name']) === 0) {
                $mgr['Department'] = $dept;
                $found = true;
                break;
            }
        }
        $mgr['Department'] = $found ? $mgr['Department'] : '-';
    }
}
unset($mgr); // Break reference

// Handle session messages
$message = $_SESSION['message'] ?? null;
$messageType = $_SESSION['messageType'] ?? null;
unset($_SESSION['message'], $_SESSION['messageType']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        /* Helper for redirect with query string preservation */
        $redirect = function () {
            $url = $_SERVER['PHP_SELF'];
            if (!empty($_SERVER['QUERY_STRING'])) {
                $url .= '?' . $_SERVER['QUERY_STRING'];
            }
            header("Location: $url");
            exit();
        };

        if ($_POST['action'] === 'add_schedule') {
            $managerId = (int) $_POST['manager_id'];
            $scheduleDate = $_POST['schedule_date'];

            $timeline = $_POST['timeline'] ?? '';
            $validTimelines = ['8:00AM - 5:00PM', '8:00PM - 5:00AM'];

            if (!in_array($timeline, $validTimelines)) {
                $_SESSION['message'] = "Invalid timeline selected.";
                $_SESSION['messageType'] = "error";
            } else {
                // Check if this specific manager is already assigned to this date (No duplicates per day)
                $existingCheck = dbQueryOne("SELECT ID FROM DM_Schedules WHERE ScheduledDate = ? AND ManagerID = ?", [$scheduleDate, $managerId]);

                if ($existingCheck) {
                    $_SESSION['message'] = "This manager is already assigned to this date.";
                    $_SESSION['messageType'] = "error";
                } else {
                    // Check how many managers are already assigned to this TIMELINE on this date
                    $managerCountCheck = dbQueryOne("SELECT COUNT(*) as count FROM DM_Schedules WHERE ScheduledDate = ? AND Timeline = ?", [$scheduleDate, $timeline]);

                    if ($managerCountCheck && $managerCountCheck['count'] >= 3) {
                        $_SESSION['message'] = "Maximum of 3 managers per timeline ($timeline).";
                        $_SESSION['messageType'] = "error";
                    } else {
                        // INSERT the schedule
                        dbExecute(
                            "INSERT INTO DM_Schedules (ManagerID, ScheduledDate, Timeline, CreatedBy) VALUES (?, ?, ?, ?)",
                            [$managerId, $scheduleDate, $timeline, $userName]
                        );
                        $_SESSION['message'] = "Schedule added.";
                        $_SESSION['messageType'] = "success";
                    }
                }
            }
            $redirect();
        }

        if ($_POST['action'] === 'delete_schedule') {
            $scheduleId = (int) $_POST['schedule_id'];

            // Get the scheduled date for this schedule to confirm existence
            $checkQuery = dbQueryOne("SELECT ID FROM DM_Schedules WHERE ID = ?", [$scheduleId]);

            if ($checkQuery) {
                // Delete dependent records first (simulate cascade delete)
                $sessions = dbQuery("SELECT ID FROM DM_Checklist_Sessions WHERE ScheduleID = ?", [$scheduleId]);

                if ($sessions !== false) {
                    foreach ($sessions as $session) {
                        $sessionId = $session['ID'];
                        // Delete entries for this session
                        dbExecute("DELETE FROM DM_Checklist_Entries WHERE SessionID = ?", [$sessionId]);
                    }
                    // Delete sessions
                    dbExecute("DELETE FROM DM_Checklist_Sessions WHERE ScheduleID = ?", [$sessionId]);
                }

                // Delete the schedule
                dbExecute("DELETE FROM DM_Schedules WHERE ID = ?", [$scheduleId]);

                $_SESSION['message'] = "Schedule deleted.";
                $_SESSION['messageType'] = "success";
            } else {
                $_SESSION['message'] = "Schedule not found.";
                $_SESSION['messageType'] = "error";
            }

            $redirect();
        }

        if ($_POST['action'] === 'delete_day_schedule') {
            $scheduleDate = $_POST['schedule_date'];

            // Get all schedules for this date
            $daySchedules = dbQuery("SELECT ID FROM DM_Schedules WHERE ScheduledDate = ?", [$scheduleDate]);

            if ($daySchedules) {
                foreach ($daySchedules as $sched) {
                    $sId = $sched['ID'];

                    // Delete dependent records first
                    $sessions = dbQuery("SELECT ID FROM DM_Checklist_Sessions WHERE ScheduleID = ?", [$sId]);

                    if ($sessions) {
                        foreach ($sessions as $session) {
                            $sessionId = $session['ID'];
                            // Delete entries
                            dbExecute("DELETE FROM DM_Checklist_Entries WHERE SessionID = ?", [$sessionId]);
                        }
                        // Delete session
                        dbExecute("DELETE FROM DM_Checklist_Sessions WHERE ScheduleID = ?", [$sId]);
                    }

                    // Delete schedule
                    dbExecute("DELETE FROM DM_Schedules WHERE ID = ?", [$sId]);
                }

                $_SESSION['message'] = "All schedules for " . (new DateTime($scheduleDate))->format('M j') . " deleted.";
                $_SESSION['messageType'] = "success";
            } else {
                $_SESSION['message'] = "No schedules found for this date.";
                $_SESSION['messageType'] = "error";
            }

            $redirect();
        }
    }
}

// --- Dual Pagination Setup ---

// 1. All Schedules (Bottom Table)
$allPage = isset($_GET['apage']) ? max(1, (int) $_GET['apage']) : 1;
$allPerPage = 10;
$allOffset = ($allPage - 1) * $allPerPage;

// 2. Quick Assignment (Top Cards)
$quickPage = isset($_GET['qpage']) ? max(1, (int) $_GET['qpage']) : 1;
$quickPerPage = 6;
$quickOffset = ($quickPage - 1) * $quickPerPage;


// Calculate default filter date (1 month ago)
$today = $phTime->format('Y-m-d');
$filterDateObj = clone $phTime;
$filterDateObj->modify('-1 month');
$filterDate = $filterDateObj->format('Y-m-d');

// Filter parameters
$filterMonth = $_GET['filter_month'] ?? ''; // Format: MM (01-12)
$searchName = $_GET['search_name'] ?? '';
$filterStatus = $_GET['filter_status'] ?? ''; // 'submitted', 'upcoming', or ''

// Build Base WHERE clauses (common filters)
$baseWhere = [];
$baseParams = [];

// Get current year for month filtering
$currentYear = (int) $phTime->format('Y');

// Month Filter
if ($filterMonth && preg_match('/^(0[1-9]|1[0-2])$/', $filterMonth)) {
    $monthStart = sprintf('%04d-%02d-01', $currentYear, (int) $filterMonth);
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $baseWhere[] = "s.ScheduledDate >= ?";
    $baseWhere[] = "s.ScheduledDate <= ?";
    $baseParams[] = $monthStart;
    $baseParams[] = $monthEnd;
} else {
    // Default: show from 1 month ago onwards
    $baseWhere[] = "s.ScheduledDate >= ?";
    $baseParams[] = $filterDate;
}

// Name Filter (Subquery to keep groups intact)
if ($searchName) {
    $baseWhere[] = "EXISTS (
        SELECT 1 
        FROM DM_Schedules s2 
        JOIN DM_Users u2 ON s2.ManagerID = u2.ID 
        WHERE s2.ScheduledDate = s.ScheduledDate 
        AND u2.Name LIKE ?
    )";
    $baseParams[] = '%' . $searchName . '%';
}

// --- QUERY 1: ALL SCHEDULES (Table) ---
$allWhere = $baseWhere;
$allParams = $baseParams;

// Status Filter (Only applies to All Schedules view)
if ($filterStatus === 'submitted') {
    $allWhere[] = "cs.Status = 'Completed'";
} elseif ($filterStatus === 'upcoming') {
    $allWhere[] = "(cs.Status IS NULL OR cs.Status != 'Completed')";
}

$allWhereClause = implode(' AND ', $allWhere);

// Count Total (All Schedules)

$allCountQuery = "SELECT COUNT(s.ID)
               FROM DM_Schedules s
               JOIN DM_Users u ON s.ManagerID = u.ID
               LEFT JOIN DM_Checklist_Sessions cs ON s.ID = cs.ScheduleID
               WHERE $allWhereClause";
$allCountResult = dbQueryOne($allCountQuery, $allParams);
$totalAllCount = $allCountResult ? (int) array_values($allCountResult)[0] : 0;
$totalAllPages = ceil($totalAllCount / $allPerPage);

// Fetch Data (All Schedules)

// Fetch Data (All Schedules)
$schedulesQuery = "SELECT 
                      s.ID,
                      s.ScheduledDate,
                      s.CreatedAt,
                      s.CreatedBy,
                      u.Name as ManagerName,
                      cs.Status as ChecklistStatus
                   FROM DM_Schedules s
                   JOIN DM_Users u ON s.ManagerID = u.ID
                   LEFT JOIN DM_Checklist_Sessions cs ON s.ID = cs.ScheduleID
                   WHERE $allWhereClause
                   ORDER BY s.ScheduledDate ASC
                   OFFSET ? ROWS
                   FETCH NEXT ? ROWS ONLY";

$allQueryParams = $allParams;
$allQueryParams[] = $allOffset;
$allQueryParams[] = $allPerPage;

$schedules = dbQuery($schedulesQuery, $allQueryParams); // Result for Bottom Table

// Merge Departments for Schedules
if ($schedules) {
    // We already have $departments map populated from the top "managers" query?
    // Not necessarily, the paginated schedules might include managers not in the active "managers" filter list (though unlikely if all active).
    // Safer to fetch fresh for this batch.

    $schedManagerNames = array_map(function ($s) {
        return $s['ManagerName'];
    }, $schedules);
    $schedCleanNames = array_map(function ($n) {
        return str_replace("'", "''", $n);
    }, $schedManagerNames);

    // Filter out names we might already have from step 1 to save DB calls?
    // Given simplicity, just re-fetch or use cache array.

    if (!empty($schedCleanNames)) {
        $nameListStrS = "'" . implode("', '", $schedCleanNames) . "'";
        $deptQueryS = "SELECT FirstName + ' ' + LastName as FullName, Department 
                       FROM lrn_master_list 
                       WHERE (FirstName + ' ' + LastName) IN ($nameListStrS)";

        $stmtDeptS = sqlsrv_query($connData, $deptQueryS);
        $schedDepts = [];
        if ($stmtDeptS) {
            while ($row = sqlsrv_fetch_array($stmtDeptS, SQLSRV_FETCH_ASSOC)) {
                $schedDepts[$row['FullName']] = $row['Department'];
            }
        }

        // Attach
        foreach ($schedules as &$sched) {
            $name = $sched['ManagerName'];
            $sched['Department'] = $schedDepts[$name] ?? '-';

            // Try fuzzy if not exact
            if ($sched['Department'] === '-') {
                foreach ($schedDepts as $dName => $dept) {
                    if (strcasecmp($dName, $name) === 0) {
                        $sched['Department'] = $dept;
                        break;
                    }
                }
            }
        }
        unset($sched);
    }
} else {
    $schedules = [];
}
// Remove old fetch logic block completely 
// (The previous replace block target will cover the old logic)


// --- QUERY 2: QUICK ASSIGNMENT (Cards) ---
// Rule: Hide submitted checklists
$quickWhere = $baseWhere;
$quickParams = $baseParams;

// Force hide completed, regardless of filter (Subquery check for SessionDate status to be safer for shared sessions)
// We only want dates where the session is NOT completed.
// Since sessions are shared per date, if one is completed, the date is completed.
$quickWhere[] = "NOT EXISTS (
    SELECT 1 FROM DM_Checklist_Sessions cs_check 
    WHERE cs_check.SessionDate = s.ScheduledDate 
    AND cs_check.Status = 'Completed'
)";

$quickWhereClause = implode(' AND ', $quickWhere);

// Count Total (Quick Assignment)
$quickCountQuery = "SELECT COUNT(DISTINCT s.ScheduledDate)
                    FROM DM_Schedules s
                    JOIN DM_Users u ON s.ManagerID = u.ID
                    WHERE $quickWhereClause";
$quickCountResult = dbQueryOne($quickCountQuery, $quickParams);
$totalQuickCount = $quickCountResult ? (int) array_values($quickCountResult)[0] : 0;
$totalQuickPages = ceil($totalQuickCount / $quickPerPage);

// Fetch Data (Quick Assignment) - Get DISTINCT DATES first for pagination
// We paginate by DATE, then fetch details for those dates
$quickDatesQuery = "SELECT DISTINCT s.ScheduledDate
                    FROM DM_Schedules s
                    JOIN DM_Users u ON s.ManagerID = u.ID
                    WHERE $quickWhereClause
                    ORDER BY s.ScheduledDate ASC
                    OFFSET ? ROWS
                    FETCH NEXT ? ROWS ONLY";
$quickQueryParams = $quickParams;
$quickQueryParams[] = $quickOffset;
$quickQueryParams[] = $quickPerPage;

$quickDatesResult = dbQuery($quickDatesQuery, $quickQueryParams);

// Now fetch the actual schedule rows for these dates
$quickAssignmentSchedules = [];
if ($quickDatesResult) {
    $targetDates = [];
    foreach ($quickDatesResult as $r) {
        $targetDates[] = $r['ScheduledDate']->format('Y-m-d');
    }

    if (!empty($targetDates)) {
        $placeholders = implode(',', array_fill(0, count($targetDates), '?'));
        // Re-use logic to get details
        $detailsQuery = "SELECT s.ID, s.ScheduledDate, s.ManagerID, s.Timeline,
                                 u.Name as ManagerName,
                                 cs.Status as ChecklistStatus
                          FROM DM_Schedules s
                          JOIN DM_Users u ON s.ManagerID = u.ID
                          LEFT JOIN DM_Checklist_Sessions cs ON s.ID = cs.ScheduleID
                          WHERE s.ScheduledDate IN ($placeholders)
                          ORDER BY s.ScheduledDate ASC, u.Name ASC";
        $quickAssignmentSchedules = dbQuery($detailsQuery, $targetDates);
    }
}

// Group quick assignment schedules by date for display
$quickAssignmentSundays = [];
if ($quickAssignmentSchedules !== false) {
    foreach ($quickAssignmentSchedules as $schedule) {
        $dateStr = $schedule['ScheduledDate']->format('Y-m-d');
        if (!isset($quickAssignmentSundays[$dateStr])) {
            $quickAssignmentSundays[$dateStr] = [
                'assignments' => [],
                'date_obj' => $schedule['ScheduledDate'],
                'is_submitted' => $schedule['ChecklistStatus'] === 'Completed'
            ];
        }
        $quickAssignmentSundays[$dateStr]['assignments'][] = $schedule;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel • Duty Manager</title>
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
                radial-gradient(at 0% 0%, hsla(253, 16%, 7%, 1) 0, transparent 50%),
                radial-gradient(at 50% 0%, hsla(225, 39%, 30%, 1) 0, transparent 50%),
                radial-gradient(at 100% 0%, hsla(339, 49%, 30%, 1) 0, transparent 50%);
            background-repeat: no-repeat;
            background-attachment: fixed;
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
    </style>
</head>

<body class="text-slate-100 min-h-screen selection:bg-brand-500 selection:text-white">

    <!-- Navbar -->
    <header class="sticky top-0 z-50 glass-header">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="index.php"
                        class="w-10 h-10 rounded-xl bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors group">
                        <i class="fas fa-arrow-left text-slate-400 group-hover:text-white transition-colors"></i>
                    </a>
                    <div>
                        <h1 class="text-lg font-bold text-white leading-none">Admin Panel</h1>
                        <p class="text-xs text-slate-400 mt-1">Schedule Management</p>
                    </div>
                </div>
                <div
                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-500 text-xs font-semibold uppercase tracking-wider">
                    <i class="fas fa-shield-alt"></i>
                    <span>Super Admin</span>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <?php if ($message): ?>
            <div id="flash-message"
                class="mb-6 p-4 rounded-xl flex items-center gap-3 animate-fade-in-down
                <?= $messageType === 'success' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> text-xl"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($message) ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Add Schedule Form -->
            <div class="lg:col-span-1 glass-card rounded-2xl p-6 h-fit">
                <h2 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-brand-500/20 flex items-center justify-center text-brand-400">
                        <i class="fas fa-plus"></i>
                    </div>
                    Add Assignment
                </h2>

                <form method="POST" class="space-y-4" id="add-schedule-form">
                    <input type="hidden" name="action" value="add_schedule">

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-slate-400 ml-1 uppercase">Manager</label>
                        <input type="hidden" name="manager_id" id="manager_id" required>

                        <div class="relative group">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                <i class="fas fa-search text-xs"></i>
                            </div>
                            <input type="text" id="manager_search_input"
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors"
                                placeholder="Type to search manager..." autocomplete="off">

                            <!-- Dropdown List -->
                            <div id="manager_dropdown_list"
                                class="absolute z-20 top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-700 rounded-xl shadow-xl max-h-60 overflow-y-auto hidden">
                                <?php foreach ($managers as $manager): ?>
                                    <div class="manager-option px-4 py-3 hover:bg-slate-700 cursor-pointer transition-colors border-b border-slate-700/50 last:border-0 flex flex-col"
                                        data-id="<?= $manager['ID'] ?>"
                                        data-name="<?= htmlspecialchars($manager['Name']) ?>"
                                        data-dept="<?= htmlspecialchars($manager['Department'] ?? '') ?>">
                                        <span
                                            class="text-sm text-white font-medium"><?= htmlspecialchars($manager['Name']) ?></span>
                                        <span
                                            class="text-xs text-slate-500"><?= htmlspecialchars($manager['Department'] ?? '') ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div id="no_manager_found"
                                    class="px-4 py-3 text-sm text-slate-500 hidden text-center italic">
                                    No manager found
                                </div>
                            </div>
                        </div>

                        <script>
                                (function () {
                                    const input = document.getElementById('manager_search_input');
                                    const hiddenInput = document.getElementById('manager_id');
                                    const dropdown = document.getElementById('manager_dropdown_list');
                                    const options = document.querySelectorAll('.manager-option');
                                    const noResult = document.getElementById('no_manager_found');
                                    let isOptionSelected = false;

                                    // Show dropdown on focus
                                    input.addEventListener('focus', () => {
                                        dropdown.classList.remove('hidden');
                                        filterManagers();
                                    });

                                    // Filter logic
                                    function filterManagers() {
                                        const term = input.value.toLowerCase();
                                        let hasVisible = false;

                                        options.forEach(opt => {
                                            const name = opt.getAttribute('data-name').toLowerCase();
                                            const dept = opt.getAttribute('data-dept').toLowerCase();

                                            if (name.includes(term) || dept.includes(term)) {
                                                opt.classList.remove('hidden');
                                                hasVisible = true;
                                            } else {
                                                opt.classList.add('hidden');
                                            }
                                        });

                                        if (!hasVisible) {
                                            noResult.classList.remove('hidden');
                                        } else {
                                            noResult.classList.add('hidden');
                                        }
                                    }

                                    input.addEventListener('input', () => {
                                        isOptionSelected = false; // Reset selection flag on type
                                        hiddenInput.value = ''; // Clear ID on type
                                        dropdown.classList.remove('hidden');
                                        filterManagers();
                                    });

                                    // Select option
                                    options.forEach(opt => {
                                        opt.addEventListener('click', () => {
                                            const name = opt.getAttribute('data-name');
                                            const id = opt.getAttribute('data-id');

                                            input.value = name;
                                            hiddenInput.value = id;
                                            isOptionSelected = true;

                                            dropdown.classList.add('hidden');
                                        });
                                    });

                                    // Close when clicking outside
                                    document.addEventListener('click', (e) => {
                                        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                                            dropdown.classList.add('hidden');

                                            // Validate: If no valid option selected, clear input
                                            if (!isOptionSelected && hiddenInput.value === '') {
                                                input.value = '';
                                            } else if (hiddenInput.value !== '') {
                                                // Restore name if ID is set but user typed something else then clicked away (optional, simplified here)
                                                // For now, if they typed garbage but had a valid ID, we might want to clear or reset.
                                                // Better UX: require exact match or click. We enforce click.
                                                if (!isOptionSelected) {
                                                    // This case: they selected someone, then clicked input, typed garbage, clicked away.
                                                    // Or typed garbage from start.
                                                    // If value doesn't match a name, clear it.
                                                    // Simplest: Just clear if !isOptionSelected
                                                    // But we set isOptionSelected=false on input.
                                                    // So if they strictly typed without clicking, it's invalid.
                                                    if (!hiddenInput.value) input.value = '';
                                                }
                                            }
                                        }
                                    });
                                })();
                        </script>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-slate-400 ml-1 uppercase">Date</label>
                        <input type="date" name="schedule_date" id="schedule_date" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 appearance-none cursor-pointer hover:bg-slate-800 transition-colors"
                            min="<?= $today ?>">
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-slate-400 ml-1 uppercase">Timeline</label>
                        <div class="relative">
                            <select name="timeline" id="timeline_select" required
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 appearance-none cursor-pointer hover:bg-slate-800 transition-colors">
                                <option value="8:00AM - 5:00PM">8:00AM - 5:00PM</option>
                                <option value="8:00PM - 5:00AM">8:00PM - 5:00AM</option>
                            </select>
                            <i
                                class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                        </div>
                    </div>

                    <!-- Info Box about Time Slots -->
                    <div class="p-3 rounded-xl bg-cyan-500/5 border border-cyan-500/20 text-cyan-400 text-xs">
                        <div class="flex items-start gap-2">
                            <i class="fas fa-info-circle mt-0.5 shrink-0"></i>
                            <div>
                                <p class="font-semibold mb-1">Schedule Limits:</p>
                                <p class="text-cyan-300/80">• Max 3 managers per timeline.</p>
                                <p class="text-cyan-300/80">• No duplicate managers per day.</p>
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="showAddConfirmModal()"
                        class="w-full bg-brand-600 hover:bg-brand-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-brand-600/20 hover:shadow-brand-600/40 transform hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-calendar-plus"></i>
                        <span>Assign Schedule</span>
                    </button>
                </form>
            </div>

            <!-- Quick Assignment (Current Page Schedules) -->
            <div class="lg:col-span-2 glass-card rounded-2xl p-6">
                <h2 class="text-lg font-bold text-white mb-6 flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-brand-500/20 flex items-center justify-center text-brand-400">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    Quick Assignment (Current Page)
                </h2>

                <?php if (count($quickAssignmentSundays) > 0): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                        <?php foreach ($quickAssignmentSundays as $sunday => $sundayData):
                            $sundayDate = $sundayData['date_obj'];
                            $assignments = $sundayData['assignments'];
                            $managerCount = count($assignments);
                            $isSubmitted = $sundayData['is_submitted'];
                            ?>
                            <div class="group relative p-4 rounded-xl border transition-all duration-300
                                <?= $isSubmitted
                                    ? 'bg-blue-500/5 border-blue-500/20'
                                    : 'bg-emerald-500/5 border-emerald-500/20 hover:bg-emerald-500/10' ?>">

                                <div class="flex justify-between items-start mb-3">
                                    <span class="text-sm font-bold text-slate-200"><?= $sundayDate->format('M j') ?></span>
                                    <div class="flex items-center gap-1.5">
                                        <?php if ($isSubmitted): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/20 text-blue-400 border border-blue-500/30">
                                                <i class="fas fa-check-circle"></i>
                                                Submitted
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                                <i class="fas fa-users"></i>
                                                <?= $managerCount ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Group by Timeline -->
                                <?php
                                $timelines = ['8:00AM - 5:00PM', '8:00PM - 5:00AM'];
                                foreach ($timelines as $timeline):
                                    $timelineAssignments = array_filter($assignments, function ($a) use ($timeline) {
                                        return ($a['Timeline'] ?? '8:00AM - 5:00PM') === $timeline; // Default to first if null
                                    });
                                    $count = count($timelineAssignments);
                                    ?>
                                    <div class="mb-3 last:mb-0">
                                        <h4 class="text-[10px] uppercase font-bold text-slate-500 mb-1.5 flex items-center gap-1.5">
                                            <i class="far fa-clock"></i> <?= $timeline ?>
                                        </h4>

                                        <div class="space-y-2 mb-2">
                                            <?php if ($count === 0): ?>
                                                <p class="text-[10px] text-slate-600 italic pl-2">No managers assigned</p>
                                            <?php else: ?>
                                                <?php foreach ($timelineAssignments as $assignment): ?>
                                                    <div
                                                        class="flex items-center justify-between gap-2 p-2 rounded-lg bg-slate-900/50 border border-slate-700/50 <?= $isSubmitted ? 'opacity-60' : '' ?>">
                                                        <div class="flex items-center gap-2 min-w-0 flex-1">
                                                            <div
                                                                class="w-6 h-6 rounded-full <?= $isSubmitted ? 'bg-blue-500/20' : 'bg-emerald-500/20' ?> flex items-center justify-center text-[10px] <?= $isSubmitted ? 'text-blue-400' : 'text-emerald-400' ?> font-bold shrink-0">
                                                                <?= substr($assignment['ManagerName'], 0, 1) ?>
                                                            </div>
                                                            <p
                                                                class="text-xs <?= $isSubmitted ? 'text-blue-300' : 'text-emerald-300' ?> font-medium truncate">
                                                                <?= htmlspecialchars($assignment['ManagerName']) ?>
                                                            </p>
                                                        </div>
                                                        <?php if (!$isSubmitted): ?>
                                                            <form method="POST" class="shrink-0" id="delete-form-<?= $assignment['ID'] ?>">
                                                                <input type="hidden" name="action" value="delete_schedule">
                                                                <input type="hidden" name="schedule_id" value="<?= $assignment['ID'] ?>">
                                                                <button type="button" data-form-id="delete-form-<?= $assignment['ID'] ?>"
                                                                    data-manager="<?= htmlspecialchars($assignment['ManagerName']) ?>"
                                                                    data-date="<?= $sundayDate->format('M j') ?>"
                                                                    onclick="showDeleteModal(this)"
                                                                    class="w-5 h-5 rounded bg-rose-500/10 text-rose-400 hover:bg-rose-500 hover:text-white transition-all flex items-center justify-center text-[10px]">
                                                                    <i class="fas fa-times"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!$isSubmitted && $count < 3): ?>
                                            <!-- Add Manager Button for this timeline -->
                                            <button onclick="toggleAddManager('add-<?= $sunday ?>-<?= md5($timeline) ?>')" type="button"
                                                class="w-full py-1.5 rounded-lg border border-dashed border-slate-600 hover:border-brand-500 text-slate-400 hover:text-brand-400 text-xs font-medium transition-all flex items-center justify-center gap-1.5 mb-1">
                                                <i class="fas fa-plus"></i>
                                                Add
                                            </button>

                                            <!-- Add form -->
                                            <form method="POST" id="add-<?= $sunday ?>-<?= md5($timeline) ?>" class="mt-1 hidden">
                                                <input type="hidden" name="action" value="add_schedule">
                                                <input type="hidden" name="schedule_date" value="<?= $sunday ?>">
                                                <input type="hidden" name="timeline" value="<?= $timeline ?>">
                                                <select name="manager_id" required
                                                    onchange="showQuickAssignModal(this, '<?= $sundayDate->format('M j') ?>')"
                                                    class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2 py-1.5 text-xs text-slate-300 hover:border-brand-500 focus:border-brand-500 focus:outline-none transition-colors cursor-pointer">
                                                    <option value="">Select manager...</option>
                                                    <?php foreach ($managers as $manager): ?>
                                                        <option value="<?= $manager['ID'] ?>">
                                                            <?= htmlspecialchars($manager['Name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        <?php elseif (!$isSubmitted && $count >= 3): ?>
                                            <div
                                                class="text-[10px] text-amber-500/80 text-center bg-amber-500/5 border border-amber-500/10 rounded py-1">
                                                Max Reached
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($isSubmitted): ?>
                                    <!-- Submitted - Read Only -->
                                    <div
                                        class="w-full py-2 rounded-lg border border-blue-500/20 bg-blue-500/5 text-blue-400 text-xs font-medium text-center mt-2">
                                        <i class="fas fa-lock mr-1"></i>
                                        Checklist Submitted
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Quick Assignment Pagination -->
                    <?php if ($totalQuickPages > 1): ?>
                        <div class="flex justify-end gap-2">
                            <?php
                            $qBaseParams = [];
                            if ($filterMonth)
                                $qBaseParams[] = 'filter_month=' . urlencode($filterMonth);
                            if ($searchName)
                                $qBaseParams[] = 'search_name=' . urlencode($searchName);
                            if ($filterStatus)
                                $qBaseParams[] = 'filter_status=' . urlencode($filterStatus);
                            // Maintain 'apage' if present
                            if (isset($_GET['apage']))
                                $qBaseParams[] = 'apage=' . urlencode($_GET['apage']);

                            $qBaseUrl = 'admin_schedule.php?' . implode('&', $qBaseParams);
                            if (!empty($qBaseParams))
                                $qBaseUrl .= '&';
                            ?>
                            <?php if ($quickPage > 1): ?>
                                <a href="<?= $qBaseUrl ?>qpage=<?= $quickPage - 1 ?>"
                                    class="px-3 py-1 text-xs rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors">
                                    <i class="fas fa-chevron-left mr-1"></i> Prev
                                </a>
                            <?php endif; ?>

                            <span class="px-2 py-1 text-xs text-slate-500 font-medium">
                                Page <?= $quickPage ?> of <?= $totalQuickPages ?>
                            </span>

                            <?php if ($quickPage < $totalQuickPages): ?>
                                <a href="<?= $qBaseUrl ?>qpage=<?= $quickPage + 1 ?>"
                                    class="px-3 py-1 text-xs rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors">
                                    Next <i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="text-center py-12 text-slate-500">
                        <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                        <p class="text-sm">No pending assignments found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </div>

        <!-- All Schedules -->
        <div class="glass-card rounded-2xl p-6">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-brand-500/20 flex items-center justify-center text-brand-400">
                        <i class="fas fa-list"></i>
                    </div>
                    All Schedules
                </h2>

                <div class="text-sm text-slate-400">
                    <?php if ($totalAllCount > 0): ?>
                        Showing
                        <?= min($allOffset + 1, $totalAllCount) ?>-<?= min($allOffset + $allPerPage, $totalAllCount) ?> of
                        <?= $totalAllCount ?> schedules
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-slate-400 ml-1 uppercase">Month</label>
                    <select name="filter_month"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors cursor-pointer">
                        <option value="">All Months</option>
                        <?php
                        $months = [
                            '01' => 'January',
                            '02' => 'February',
                            '03' => 'March',
                            '04' => 'April',
                            '05' => 'May',
                            '06' => 'June',
                            '07' => 'July',
                            '08' => 'August',
                            '09' => 'September',
                            '10' => 'October',
                            '11' => 'November',
                            '12' => 'December'
                        ];
                        foreach ($months as $monthNum => $monthName) {
                            $selected = ($filterMonth === $monthNum) ? 'selected' : '';
                            echo "<option value='$monthNum' $selected>$monthName</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-slate-400 ml-1 uppercase">Status</label>
                    <select name="filter_status"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors cursor-pointer">
                        <option value="">All Status</option>
                        <option value="submitted" <?= $filterStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                        <option value="upcoming" <?= $filterStatus === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-slate-400 ml-1 uppercase">Search Manager</label>
                    <input type="text" name="search_name" value="<?= htmlspecialchars($searchName) ?>"
                        placeholder="Manager name..."
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors">
                </div>

                <div class="flex items-end gap-2 lg:col-span-4">
                    <button type="submit"
                        class="flex-1 bg-brand-600 hover:bg-brand-500 text-white font-bold py-2 px-4 rounded-xl transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-filter text-sm"></i>
                        <span>Filter</span>
                    </button>
                    <a href="admin_schedule.php"
                        class="bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium py-2 px-4 rounded-xl transition-colors flex items-center justify-center">
                        <i class="fas fa-redo text-sm"></i>
                    </a>
                </div>
            </form>

            <?php if (count($schedules) > 0): ?>
                <div class="overflow-x-auto rounded-xl border border-white/5">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-900/50 text-xs uppercase tracking-wider text-slate-400 font-semibold">
                            <tr>
                                <th class="text-left py-4 px-6">Date</th>
                                <th class="text-left py-4 px-6">Manager</th>
                                <th class="text-left py-4 px-6">Department</th>
                                <th class="text-left py-4 px-6">Status</th>
                                <th class="text-center py-4 px-6">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($schedules as $schedule):
                                $schedDate = $schedule['ScheduledDate']->format('Y-m-d');
                                $isPast = $schedDate < $today;
                                ?>
                                <tr
                                    class="hover:bg-slate-800/30 transition-colors <?= $isPast ? 'opacity-50 grayscale' : '' ?>">
                                    <td class="py-4 px-6 text-slate-200 font-medium">
                                        <?= (new DateTime($schedDate))->format('M j, Y') ?>
                                    </td>
                                    <td class="py-4 px-6">
                                        <span
                                            class="text-brand-300 font-medium"><?= htmlspecialchars($schedule['ManagerName']) ?></span>
                                    </td>
                                    <td class="py-4 px-6 text-slate-400 text-xs">
                                        <?= htmlspecialchars($schedule['Department'] ?? '-') ?>
                                    </td>
                                    <td class="py-4 px-6">
                                        <?php
                                        $checklistStatus = $schedule['ChecklistStatus'] ?? null;
                                        $isSubmitted = $checklistStatus === 'Completed';

                                        if ($isSubmitted): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-500/10 text-blue-400 border border-blue-500/20">
                                                <i class="fas fa-check-circle"></i> Submitted
                                            </span>
                                        <?php elseif ($isPast): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-800 text-slate-400 border border-slate-700">
                                                <i class="fas fa-check"></i> Past
                                            </span>
                                        <?php elseif ($schedDate === $today): ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 animate-pulse">
                                                <i class="fas fa-clock"></i> Today
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                <i class="fas fa-hourglass-start"></i> Upcoming
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6 text-center">
                                        <?php
                                        $checklistStatus = $schedule['ChecklistStatus'] ?? null;
                                        $isSubmitted = $checklistStatus === 'Completed';

                                        // Only allow deleting if not submitted
                                        if (!$isSubmitted): ?>
                                            <form method="POST" class="inline" id="delete-table-form-<?= $schedule['ID'] ?>">
                                                <input type="hidden" name="action" value="delete_schedule">
                                                <input type="hidden" name="schedule_id" value="<?= $schedule['ID'] ?>">
                                                <button type="button" data-form-id="delete-table-form-<?= $schedule['ID'] ?>"
                                                    data-manager="<?= htmlspecialchars($schedule['ManagerName']) ?>"
                                                    data-date="<?= (new DateTime($schedDate))->format('M j') ?>"
                                                    onclick="showDeleteModal(this)"
                                                    class="w-8 h-8 rounded-lg hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition-colors flex items-center justify-center"
                                                    title="Delete Schedule">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-slate-700">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="glass-card rounded-2xl p-12 text-center">
                    <div class="w-20 h-20 bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="far fa-calendar text-4xl text-slate-600"></i>
                    </div>
                    <h3 class="text-xl font-medium text-white mb-2">No schedules found</h3>
                    <p class="text-slate-400">Get started by assigning managers to upcoming Sundays.</p>
                </div>
            <?php endif; ?>

            <!-- Pagination (All Schedules) -->
            <?php if ($totalAllPages > 1): ?>
                <div class="mt-6 flex items-center justify-center gap-2">
                    <?php
                    $aBaseParams = [];
                    if ($filterMonth)
                        $aBaseParams[] = 'filter_month=' . urlencode($filterMonth);
                    if ($searchName)
                        $aBaseParams[] = 'search_name=' . urlencode($searchName);
                    if ($filterStatus)
                        $aBaseParams[] = 'filter_status=' . urlencode($filterStatus);
                    // Maintain 'qpage' if present
                    if (isset($_GET['qpage']))
                        $aBaseParams[] = 'qpage=' . urlencode($_GET['qpage']);

                    $aBaseUrl = 'admin_schedule.php?' . implode('&', $aBaseParams);
                    if (!empty($aBaseParams))
                        $aBaseUrl .= '&';
                    ?>

                    <!-- Previous Button -->
                    <?php if ($allPage > 1): ?>
                        <a href="<?= $aBaseUrl ?>apage=<?= $allPage - 1 ?>"
                            class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors">
                            <i class="fas fa-chevron-left text-sm"></i>
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-2 rounded-lg bg-slate-900 text-slate-600 cursor-not-allowed">
                            <i class="fas fa-chevron-left text-sm"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php
                    $startPage = max(1, $allPage - 2);
                    $endPage = min($totalAllPages, $allPage + 2);

                    if ($startPage > 1): ?>
                        <a href="<?= $aBaseUrl ?>apage=1"
                            class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm transition-colors">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="text-slate-600">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i == $allPage): ?>
                            <span class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-bold"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= $aBaseUrl ?>apage=<?= $i ?>"
                                class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm transition-colors"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalAllPages): ?>
                        <?php if ($endPage < $totalAllPages - 1): ?>
                            <span class="text-slate-600">...</span>
                        <?php endif; ?>
                        <a href="<?= $aBaseUrl ?>apage=<?= $totalAllPages ?>"
                            class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm transition-colors"><?= $totalAllPages ?></a>
                    <?php endif; ?>

                    <!-- Next Button -->
                    <?php if ($allPage < $totalAllPages): ?>
                        <a href="<?= $aBaseUrl ?>apage=<?= $allPage + 1 ?>"
                            class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors">
                            <i class="fas fa-chevron-right text-sm"></i>
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-2 rounded-lg bg-slate-900 text-slate-600 cursor-not-allowed">
                            <i class="fas fa-chevron-right text-sm"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Confirmation Modal -->
    <div id="confirm-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="glass-card rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl border border-rose-500/30 transform scale-95 transition-transform duration-300">
            <div class="text-center">
                <div class="w-16 h-16 bg-rose-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-3xl text-rose-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2" id="confirm-title">Confirm Action</h3>
                <p class="text-slate-400 text-sm mb-6" id="confirm-message">Are you sure you want to proceed?</p>
                <div class="flex gap-3">
                    <button onclick="closeConfirmModal()"
                        class="flex-1 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button onclick="confirmAction()"
                        class="flex-1 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-sm font-bold transition-colors">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Assignment Confirmation Modal -->
    <div id="add-confirm-modal"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="glass-card rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl border border-brand-500/30 transform scale-95 transition-transform duration-300">
            <div class="text-center">
                <div class="w-16 h-16 bg-brand-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-calendar-plus text-3xl text-brand-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2" id="add-confirm-title">Confirm Assignment</h3>
                <div class="text-left space-y-3 mb-6 p-4 rounded-xl bg-slate-900/50 border border-slate-700">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-user text-brand-400"></i>
                        <div class="flex-1">
                            <p class="text-xs text-slate-500">Manager</p>
                            <p class="text-sm text-white font-medium" id="add-manager-name">-</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="fas fa-calendar text-brand-400"></i>
                        <div class="flex-1">
                            <p class="text-xs text-slate-500">Date</p>
                            <p class="text-sm text-white font-medium" id="add-schedule-date">-</p>
                        </div>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button onclick="closeAddConfirmModal()"
                        class="flex-1 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button onclick="confirmAddAction()"
                        class="flex-1 py-3 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-sm font-bold transition-colors">
                        Assign
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Validation Modal -->
    <div id="validation-modal"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="glass-card rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl border border-amber-500/30 transform scale-95 transition-transform duration-300">
            <div class="text-center">
                <div class="w-16 h-16 bg-amber-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-circle text-3xl text-amber-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Validation Error</h3>
                <p class="text-slate-400 text-sm mb-6" id="validation-message">Please check your input.</p>
                <button onclick="closeValidationModal()"
                    class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                    Okay
                </button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="max-w-7xl mx-auto px-6 py-16 border-t border-white/5">
        <div class="flex flex-col items-center gap-8 opacity-80 hover:opacity-100 transition-opacity">
            <img src="assets/img/footer.png" alt="La Rose Noire" class="h-24 grayscale invert opacity-80">
            <p class="text-slate-500 text-lg text-center">
                © <?= date('Y') ?> La Rose Noire Philippines • Facilities Management Department
            </p>
        </div>
    </footer>

    <script>
        // Modal state
        let currentFormId = null;

        // Show validation modal
        function showValidationModal(message) {
            document.getElementById('validation-message').textContent = message;

            const modal = document.getElementById('validation-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        // Close validation modal
        function closeValidationModal() {
            const modal = document.getElementById('validation-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 200);
        }

        // Show delete confirmation modal (simplified using data attributes)
        function showDeleteModal(button) {
            const formId = button.getAttribute('data-form-id');
            const manager = button.getAttribute('data-manager');
            const date = button.getAttribute('data-date');

            currentFormId = formId;
            document.getElementById('confirm-title').textContent = `Remove ${manager} from ${date}?`;
            document.getElementById('confirm-message').textContent = 'This manager will be removed from this Sunday\'s schedule.';

            const modal = document.getElementById('confirm-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        // Show confirmation modal for delete actions
        function showConfirmModal(formId, title, message) {
            currentFormId = formId;
            document.getElementById('confirm-title').textContent = title;
            document.getElementById('confirm-message').textContent = message;

            const modal = document.getElementById('confirm-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            // Trigger animation
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        // Close confirmation modal
        function closeConfirmModal() {
            const modal = document.getElementById('confirm-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
                currentFormId = null;
            }, 200);
        }

        // Confirm delete action
        function confirmAction() {
            if (currentFormId) {
                document.getElementById(currentFormId).submit();
            }
        }

        // Show add assignment confirmation modal
        function showAddConfirmModal() {
            const managerSelect = document.getElementById('manager_select');
            const dateInput = document.getElementById('schedule_date');

            // Validate inputs
            if (!managerSelect.value) {
                showValidationModal('Please select a manager');
                return;
            }
            if (!dateInput.value) {
                showValidationModal('Please select a date');
                return;
            }

            // Get selected manager name
            const managerName = managerSelect.options[managerSelect.selectedIndex].text;
            const dateObj = new Date(dateInput.value + 'T00:00:00');
            const formattedDate = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            // Update modal content
            document.getElementById('add-confirm-title').textContent = 'Confirm Assignment';
            document.getElementById('add-manager-name').textContent = managerName;
            document.getElementById('add-schedule-date').textContent = formattedDate;

            // Show modal
            const modal = document.getElementById('add-confirm-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            // Trigger animation
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        // Close add confirmation modal
        function closeAddConfirmModal() {
            const modal = document.getElementById('add-confirm-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');

            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');

                // Reset quick assign dropdown if it was used
                if (currentFormId && (currentFormId.startsWith('quick-') || currentFormId.startsWith('add-'))) {
                    const form = document.getElementById(currentFormId);
                    if (form) {
                        const select = form.querySelector('select');
                        if (select) select.value = '';
                    }
                }
            }, 200);
        }

        // Confirm add action
        function confirmAddAction() {
            // If currentFormId is set, it's a quick assign (from dropdown)
            if (currentFormId && currentFormId.startsWith('quick-') || currentFormId && currentFormId.startsWith('add-')) {
                document.getElementById(currentFormId).submit();
            } else {
                // Otherwise, it's the main add schedule form
                document.getElementById('add-schedule-form').submit();
            }
        }

        // Show quick assign confirmation modal
        function showQuickAssignModal(selectElement, dateText) {
            if (!selectElement.value) return; // Don't show if "Select manager..." or "+ Assign" is selected

            const managerName = selectElement.options[selectElement.selectedIndex].text;
            const form = selectElement.closest('form');

            // Store form reference for later submission
            currentFormId = form.id;

            // Update modal content
            document.getElementById('add-confirm-title').textContent = `Assign ${managerName}?`;
            document.getElementById('add-manager-name').textContent = managerName;
            document.getElementById('add-schedule-date').textContent = dateText;

            // Show modal
            const modal = document.getElementById('add-confirm-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        // Toggle Add Manager dropdown
        function toggleAddManager(formId) {
            const form = document.getElementById(formId);
            if (form) {
                form.classList.toggle('hidden');
            }
        }

        // Close modals on overlay click
        document.getElementById('confirm-modal')?.addEventListener('click', function (e) {
            if (e.target === this) {
                closeConfirmModal();
            }
        });

        document.getElementById('add-confirm-modal')?.addEventListener('click', function (e) {
            if (e.target === this) {
                closeAddConfirmModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeConfirmModal();
                closeAddConfirmModal();
                closeValidationModal();
            }
        });

        // Close validation modal on overlay click
        document.getElementById('validation-modal')?.addEventListener('click', function (e) {
            if (e.target === this) {
                closeValidationModal();
            }
        });

        // Auto-hide flash message after 5 seconds
        const flashMessage = document.getElementById('flash-message');
        if (flashMessage) {
            setTimeout(() => {
                flashMessage.style.transition = 'opacity 0.5s ease-out';
                flashMessage.style.opacity = '0';
                setTimeout(() => {
                    flashMessage.remove();
                }, 500);
            }, 5000);
        }
    </script>
</body>

</html>
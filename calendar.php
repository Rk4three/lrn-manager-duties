<?php
// calendar.php - Manager Schedule Calendar
session_start();
require_once 'config/db.php';

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    header("Location: login.php");
    exit();
}

$userName = $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'User';
$userDept = $_SESSION['dm_department'] ?? $_SESSION['dept'] ?? 'N/A';
$dmUserId = $_SESSION['dm_user_id'];
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

// If Super Admin, fetch all managers for the dropdown
$managers = [];
if ($isSuperAdmin) {
    $mgrQuery = "SELECT ID, Name FROM DM_Users WHERE IsActive = 1 ORDER BY Name ASC";
    $managers = dbQuery($mgrQuery, []);
    if ($managers === false) {
        $managers = [];
    } else {
        // Fetch Departments from Master List
        $managerNames = array_map(function ($m) {
            return $m['Name'];
        }, $managers);
        if (!empty($managerNames)) {
            $cleanNames = array_map(function ($n) {
                return str_replace("'", "''", $n);
            }, $managerNames);
            $nameListStr = "'" . implode("', '", $cleanNames) . "'";

            $departments = [];
            $deptQuery = "SELECT FirstName + ' ' + LastName as FullName, Department 
                          FROM LRNPH_E.dbo.lrn_master_list 
                          WHERE (FirstName + ' ' + LastName) IN ($nameListStr)";

            // Use connData for cross-db access
            if (isset($connData) && $connData) {
                $stmtDept = sqlsrv_query($connData, $deptQuery);
                if ($stmtDept) {
                    while ($row = sqlsrv_fetch_array($stmtDept, SQLSRV_FETCH_ASSOC)) {
                        $departments[$row['FullName']] = $row['Department'];
                    }
                }
            }

            // Merge
            foreach ($managers as &$mgr) {
                $mgr['Department'] = $departments[$mgr['Name']] ?? 'N/A';
            }
            unset($mgr);
        }
    }
}

// Get month and year from query params or use current
$phTime = getPhilippinesTime();
$currentYear = (int) ($_GET['year'] ?? $phTime->format('Y'));
$currentMonth = (int) ($_GET['month'] ?? $phTime->format('m'));

// Validate month and year
if ($currentMonth < 1 || $currentMonth > 12) {
    $currentMonth = (int) $phTime->format('m');
}
if ($currentYear < 2020 || $currentYear > 2050) {
    $currentYear = (int) $phTime->format('Y');
}

// Create DateTime for the first day of the month
$firstDay = new DateTime(sprintf('%04d-%02d-01', $currentYear, $currentMonth));
$monthName = $firstDay->format('F');
$daysInMonth = (int) $firstDay->format('t');
$firstDayOfWeek = (int) $firstDay->format('w'); // 0 = Sunday

// Calculate previous and next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Fetch calendar entries for this month
$firstDayStr = $firstDay->format('Y-m-d');
$lastDayStr = $firstDay->format('Y-m-t');

$entries = dbQuery(
    "SELECT ID, EntryDate, EntryType, StartTime, EndTime, LeaveNote
     FROM Manager_Calendar
     WHERE ManagerID = ? AND EntryDate >= ? AND EntryDate <= ?
     ORDER BY EntryDate ASC",
    [$dmUserId, $firstDayStr, $lastDayStr]
);

// Index entries by date for easy lookup
$entriesByDate = [];
if ($entries) {
    foreach ($entries as $entry) {
        $date = $entry['EntryDate']->format('Y-m-d');
        $entriesByDate[$date] = $entry;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Calendar • Duty Manager</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Icons & Tailwind -->
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
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.125);
        }

        .glass-header {
            background: rgba(3, 0, 20, 0.7);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .calendar-day {
            min-height: 100px;
            transition: all 0.2s;
        }

        .calendar-day:hover {
            background: rgba(139, 92, 246, 0.05);
            border-color: rgba(139, 92, 246, 0.3);
        }

        .entry-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            display: inline-block;
            margin-top: 0.25rem;
        }

        .entry-work {
            background: rgba(34, 197, 94, 0.15);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .entry-leave {
            background: rgba(251, 146, 60, 0.15);
            color: #fdba74;
            border: 1px solid rgba(251, 146, 60, 0.3);
        }

        .view-toggle {
            color: #94a3b8;
            background: transparent;
        }

        .view-toggle.active {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .count-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            display: inline-block;
            margin-top: 0.25rem;
            font-weight: 600;
        }

        .badge-work {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .badge-leave {
            background: rgba(251, 146, 60, 0.15);
            color: #fdba74;
            border: 1px solid rgba(251, 146, 60, 0.3);
        }

        .manager-photo {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
        }

        #day-detail-modal table tbody tr:hover {
            background: rgba(139, 92, 246, 0.05);
        }

        /* Batch Mode Styles */
        .batch-mode-active .calendar-day {
            cursor: pointer;
        }

        .batch-mode-active .calendar-day:hover {
            border-color: #8b5cf6;
            background: rgba(139, 92, 246, 0.1);
        }

        .calendar-day.selected {
            border-color: #8b5cf6 !important;
            background: rgba(139, 92, 246, 0.2) !important;
            box-shadow: 0 0 15px rgba(139, 92, 246, 0.3) !important;
            transform: scale(0.98);
        }

        .batch-mode-btn.active {
            background-color: #7c3aed;
            /* brand-600 */
            color: white;
            border-color: #8b5cf6;
            box-shadow: 0 0 15px rgba(139, 92, 246, 0.4);
        }

        /* Floating Toolbar */
        #batch-toolbar {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #batch-toolbar.translate-y-full {
            transform: translateY(150%);
        }

        #batch-toolbar.translate-y-0 {
            transform: translateY(0);
        }
    </style>
</head>

<body data-manager-id="<?= $dmUserId ?>" data-is-super-admin="<?= $isSuperAdmin ? '1' : '0' ?>">

    <!-- Navbar -->
    <header class="sticky top-0 z-50 glass-header transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Logo / Title -->
                <div class="flex items-center gap-4">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-brand-500/20">
                        <i class="fas fa-calendar-alt text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold tracking-tight text-white leading-tight">Schedule Calendar</h1>
                        <p class="text-xs text-slate-400 font-medium">Manage Your Work Schedule</p>
                    </div>
                </div>

                <!-- User Profile with Photo -->
                <div class="flex items-center gap-4">
                    <img id="user-photo" src="assets/img/mystery-man.png" alt="Profile"
                        class="w-12 h-12 rounded-full border-2 border-white/10 shadow-lg"
                        onerror="this.src='assets/img/mystery-man.png'">

                    <div class="hidden md:block">
                        <p class="text-sm font-semibold text-white">
                            <?= htmlspecialchars($userName) ?>
                        </p>
                        <p class="text-xs text-slate-400" id="user-dept">
                            <?= htmlspecialchars($userDept) ?>
                        </p>
                    </div>

                    <a href="index.php"
                        class="group relative w-10 h-10 rounded-xl bg-slate-800/50 hover:bg-brand-500/20 border border-white/5 hover:border-brand-500/30 flex items-center justify-center transition-all duration-300"
                        title="Back to Dashboard">
                        <i class="fas fa-arrow-left text-slate-400 group-hover:text-brand-400 transition-colors"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

        <!-- Month Navigation -->
        <div class="glass-card rounded-3xl p-6 mb-8">
            <div class="flex items-center justify-between">
                <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?><?= isset($_GET['view']) ? '&view=' . htmlspecialchars($_GET['view']) : '' ?>"
                    class="p-3 rounded-xl bg-slate-800/50 hover:bg-slate-800 border border-white/5 hover:border-brand-500/30 transition-all">
                    <i class="fas fa-chevron-left text-slate-400"></i>
                </a>

                <div class="text-center">
                    <h2 class="text-3xl font-bold text-white mb-3">
                        <?= $monthName ?> <span class="text-2xl text-slate-500"><?= $currentYear ?></span>
                    </h2>

                    <!-- View Toggle Buttons -->
                    <div class="inline-flex gap-2 p-1 bg-slate-800/50 rounded-xl border border-white/5 mb-3">
                        <button id="my-schedule-btn" onclick="switchView('my')"
                            class="view-toggle px-6 py-2 rounded-lg font-medium transition-all active">
                            <i class="fas fa-user text-sm mr-2"></i>
                            My Schedule
                        </button>
                        <button id="all-managers-btn" onclick="switchView('all')"
                            class="view-toggle px-6 py-2 rounded-lg font-medium transition-all">
                            <i class="fas fa-users text-sm mr-2"></i>
                            All Managers
                        </button>
                    </div>

                    <!-- Batch Mode Button -->
                    <div class="mt-2">
                        <button id="batch-mode-btn" onclick="toggleBatchMode()"
                            class="batch-mode-btn px-4 py-2 rounded-xl bg-slate-800/50 border border-white/5 text-slate-400 text-sm font-medium hover:text-white transition-all">
                            <i class="fas fa-layer-group mr-2"></i> Batch Mode
                        </button>
                    </div>

                    <p class="text-sm text-slate-400">
                        <i class="far fa-calendar text-brand-500"></i>
                        <span id="hint-text">Click on any day to add or edit your schedule</span>
                    </p>
                </div>

                <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?><?= isset($_GET['view']) ? '&view=' . htmlspecialchars($_GET['view']) : '' ?>"
                    class="p-3 rounded-xl bg-slate-800/50 hover:bg-slate-800 border border-white/5 hover:border-brand-500/30 transition-all">
                    <i class="fas fa-chevron-right text-slate-400"></i>
                </a>
            </div>
        </div>

        <!-- Calendar Grid -->
        <div class="glass-card rounded-3xl p-8">
            <!-- Day Headers -->
            <div class="grid grid-cols-7 gap-2 mb-4">
                <?php
                $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                foreach ($dayNames as $day):
                    ?>
                    <div class="text-center text-sm font-semibold text-slate-400 uppercase tracking-wide py-2">
                        <?= $day ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Calendar Days -->
            <div class="grid grid-cols-7 gap-2">
                <?php
                // Fill in empty cells for days before the first day of month
                for ($i = 0; $i < $firstDayOfWeek; $i++) {
                    echo '<div class="calendar-day border border-slate-800/50 rounded-xl p-2 bg-slate-900/20"></div>';
                }

                // Generate calendar days
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $dateStr = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                    $dateObj = new DateTime($dateStr);
                    $isToday = ($dateStr === $phTime->format('Y-m-d'));
                    $entry = $entriesByDate[$dateStr] ?? null;

                    $dayClasses = 'calendar-day border rounded-xl p-3 cursor-pointer relative ';
                    if ($isToday) {
                        $dayClasses .= 'border-brand-500 bg-brand-500/10';
                    } else {
                        $dayClasses .= 'border-slate-800 bg-slate-900/40';
                    }
                    ?>
                    <div class="<?= $dayClasses ?>" data-date="<?= $dateStr ?>"
                        onclick="handleDayClick(this, '<?= $dateStr ?>', <?= $entry ? $entry['ID'] : 'null' ?>)">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-lg font-bold <?= $isToday ? 'text-brand-300' : 'text-white' ?>">
                                <?= $day ?>
                            </span>
                            <?php if ($entry): ?>
                                <i
                                    class="fas fa-circle text-xs <?= $entry['EntryType'] === 'WORK' ? 'text-green-500' : 'text-orange-500' ?>"></i>
                            <?php endif; ?>
                        </div>

                        <div class="day-content">
                            <?php if ($entry): ?>
                                <div class="entry-badge <?= $entry['EntryType'] === 'WORK' ? 'entry-work' : 'entry-leave' ?>">
                                    <?php if ($entry['EntryType'] === 'WORK'): ?>
                                        <i class="fas fa-briefcase text-xs"></i>
                                        <?= htmlspecialchars($entry['StartTime']) ?> - <?= htmlspecialchars($entry['EndTime']) ?>
                                    <?php else: ?>
                                        <i class="fas fa-umbrella-beach text-xs"></i>
                                        <?= htmlspecialchars(substr($entry['LeaveNote'], 0, 20)) . (strlen($entry['LeaveNote']) > 20 ? '...' : '') ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($isToday): ?>
                            <div
                                class="absolute top-2 right-2 w-2 h-2 rounded-full bg-brand-500 shadow-[0_0_10px_rgba(139,92,246,0.5)]">
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php
                }

                // Fill remaining cells
                $totalCells = $firstDayOfWeek + $daysInMonth;
                $remainingCells = (7 - ($totalCells % 7)) % 7;
                for ($i = 0; $i < $remainingCells; $i++) {
                    echo '<div class="calendar-day border border-slate-800/50 rounded-xl p-2 bg-slate-900/20"></div>';
                }
                ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="glass-card rounded-2xl p-6 mt-6">
            <div class="flex flex-wrap items-center gap-6">
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-green-500"></div>
                    <span class="text-sm text-slate-300">Work Schedule</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-orange-500"></div>
                    <span class="text-sm text-slate-300">Leave</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full bg-brand-500"></div>
                    <span class="text-sm text-slate-300">Today</span>
                </div>
            </div>
        </div>

    </main>

    <!-- Batch Toolbar -->
    <div id="batch-toolbar"
        class="fixed bottom-8 left-1/2 -translate-x-1/2 translate-y-full z-40 bg-slate-900/90 backdrop-blur-xl border border-white/10 rounded-2xl p-2 shadow-2xl flex items-center gap-3 transition-transform duration-300">
        <div class="px-4 py-2 border-r border-white/10">
            <span class="block text-xs text-slate-400 uppercase tracking-wider font-bold">Selected</span>
            <span class="text-white font-bold text-lg" id="selected-count">0</span>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="openBatchEntryModal()"
                class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-medium transition-colors">
                <i class="fas fa-pen mr-2"></i> Set Schedule
            </button>
            <button onclick="confirmBatchDelete()"
                class="px-4 py-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 font-medium transition-colors">
                <i class="fas fa-trash mr-2"></i> Clear
            </button>
            <button onclick="toggleBatchMode()"
                class="px-3 py-2 rounded-xl hover:bg-white/5 text-slate-400 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Batch Confirmation Modal -->
    <div id="batch-confirm-modal"
        class="fixed inset-0 z-[60] hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="glass-card rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-slate-700 transform scale-95 transition-transform duration-300">
            <div class="text-center mb-6">
                <div class="w-16 h-16 rounded-full bg-slate-800 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-question text-3xl text-brand-500" id="confirm-icon"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2" id="confirm-title">Are you sure?</h3>
                <p class="text-slate-400 text-sm" id="confirm-message">This action will affect 5 days.</p>
            </div>
            <div class="flex gap-3">
                <button onclick="closeBatchConfirm()"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-medium transition-all">
                    Cancel
                </button>
                <button id="confirm-action-btn"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-bold shadow-lg shadow-brand-600/25 transition-all">
                    Confirm
                </button>
            </div>

            <!-- Manager Selection for Delete (Hidden by default) -->
            <div id="delete-manager-select-container" class="hidden mt-4 border-t border-slate-700 pt-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs text-slate-400" id="delete-selected-count">0 selected</span>
                    <button type="button" onclick="selectAllDeleteManagers()"
                        class="text-xs text-brand-400 hover:text-brand-300">Select All</button>
                </div>

                <div id="delete-tags-container" class="flex flex-wrap gap-2 mb-3 max-h-24 overflow-y-auto"></div>

                <div class="relative group">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                        <i class="fas fa-search text-xs"></i>
                    </div>
                    <input type="text" id="delete_manager_search"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors"
                        placeholder="Search managers to delete..." autocomplete="off">

                    <div id="delete_manager_dropdown"
                        class="absolute z-[70] top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-700 rounded-xl shadow-xl max-h-48 overflow-y-auto hidden">
                        <!-- Options loaded dynamically via JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Entry Modal -->
    <div id="entry-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="glass-card rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl border border-slate-700 transform scale-95 transition-transform duration-300">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-bold text-white" id="modal-title">Add Schedule</h3>
                <button onclick="closeEntryModal()" class="text-slate-400 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="entry-form" onsubmit="saveEntry(event)">
                <input type="hidden" id="entry-id" name="entry_id">
                <input type="hidden" id="entry-date" name="entry_date">

                <!-- Super Admin: Manager Selection (Hidden by default) -->
                <?php if ($isSuperAdmin): ?>
                    <div id="manager-select-container" class="mb-6 hidden">
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Assign To</label>

                        <!-- Single Select (Default/Legacy) -->
                        <div id="single-manager-select" class="relative group">
                            <input type="hidden" name="manager_id" id="manager_id_input">
                            <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                <i class="fas fa-search text-xs"></i>
                            </div>
                            <input type="text" id="manager_search_calendar"
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors"
                                placeholder="Type to search manager..." autocomplete="off">

                            <!-- Dropdown List (Single) -->
                            <div id="manager_dropdown_calendar"
                                class="absolute z-[60] top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-700 rounded-xl shadow-xl max-h-48 overflow-y-auto hidden">
                                <?php foreach ($managers as $manager): ?>
                                    <div class="calendar-mgr-option px-4 py-3 hover:bg-slate-700 cursor-pointer transition-colors border-b border-slate-700/50 last:border-0 flex flex-col"
                                        data-id="<?= $manager['ID'] ?>" data-name="<?= htmlspecialchars($manager['Name']) ?>"
                                        data-dept="<?= htmlspecialchars($manager['Department'] ?? '') ?>">
                                        <span
                                            class="text-sm text-white font-medium"><?= htmlspecialchars($manager['Name']) ?></span>
                                        <span
                                            class="text-xs text-slate-500"><?= htmlspecialchars($manager['Department'] ?? '') ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Multi Select (Batch Mode) -->
                        <div id="multi-manager-select" class="hidden">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-slate-400" id="multi-selected-count">0 selected</span>
                                <button type="button" onclick="selectAllManagers()"
                                    class="text-xs text-brand-400 hover:text-brand-300">Select All</button>
                            </div>

                            <!-- Selected Tags Container -->
                            <div id="multi-tags-container" class="flex flex-wrap gap-2 mb-3 max-h-24 overflow-y-auto"></div>

                            <!-- Search & Dropdown -->
                            <div class="relative group">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                    <i class="fas fa-users text-xs"></i>
                                </div>
                                <input type="text" id="multi_manager_search"
                                    class="w-full bg-slate-900 border border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-colors"
                                    placeholder="Search managers to add..." autocomplete="off">

                                <div id="multi_manager_dropdown"
                                    class="absolute z-[60] top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-700 rounded-xl shadow-xl max-h-48 overflow-y-auto hidden">
                                    <?php foreach ($managers as $manager): ?>
                                        <div class="multi-mgr-option px-4 py-3 hover:bg-slate-700 cursor-pointer transition-colors border-b border-slate-700/50 last:border-0 flex items-center justify-between"
                                            data-id="<?= $manager['ID'] ?>"
                                            data-name="<?= htmlspecialchars($manager['Name']) ?>">
                                            <div class="flex flex-col">
                                                <span
                                                    class="text-sm text-white font-medium"><?= htmlspecialchars($manager['Name']) ?></span>
                                                <span
                                                    class="text-xs text-slate-500"><?= htmlspecialchars($manager['Department'] ?? '') ?></span>
                                            </div>
                                            <div
                                                class="w-4 h-4 rounded border border-slate-500 flex items-center justify-center option-checkbox">
                                                <i
                                                    class="fas fa-check text-xs text-brand-500 opacity-0 transform scale-0 transition-all"></i>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Entry Type -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-300 mb-3">Entry Type</label>
                    <div class="flex gap-3">
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="entry_type" value="WORK" class="peer sr-only" checked
                                onchange="toggleEntryType()">
                            <div
                                class="p-4 rounded-xl border-2 border-slate-700 peer-checked:border-green-500 peer-checked:bg-green-500/10 transition-all text-center">
                                <i class="fas fa-briefcase text-2xl text-green-500 mb-2"></i>
                                <div class="text-sm font-medium text-slate-300">Work Schedule</div>
                            </div>
                        </label>
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="entry_type" value="LEAVE" class="peer sr-only"
                                onchange="toggleEntryType()">
                            <div
                                class="p-4 rounded-xl border-2 border-slate-700 peer-checked:border-orange-500 peer-checked:bg-orange-500/10 transition-all text-center">
                                <i class="fas fa-umbrella-beach text-2xl text-orange-500 mb-2"></i>
                                <div class="text-sm font-medium text-slate-300">Leave</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Work Schedule Fields -->
                <div id="work-fields" class="space-y-4">
                    <!-- Start Time -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">Start Time</label>
                        <div class="grid grid-cols-3 gap-2">
                            <select name="start_hour"
                                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:border-brand-500 focus:outline-none">
                                <?php for ($h = 1; $h <= 12; $h++): ?>
                                    <option value="<?= $h ?>" <?= $h === 8 ? 'selected' : '' ?>>
                                        <?= $h ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select name="start_minute"
                                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:border-brand-500 focus:outline-none">
                                <option value="00">00</option>
                                <option value="30">30</option>
                            </select>
                            <select name="start_ampm"
                                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:border-brand-500 focus:outline-none">
                                <option value="AM" selected>AM</option>
                                <option value="PM">PM</option>
                            </select>
                        </div>
                    </div>

                    <!-- End Time -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-2">End Time</label>
                        <div class="grid grid-cols-3 gap-2">
                            <select name="end_hour"
                                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:border-brand-500 focus:outline-none">
                                <?php for ($h = 1; $h <= 12; $h++): ?>
                                    <option value="<?= $h ?>" <?= $h === 5 ? 'selected' : '' ?>>
                                        <?= $h ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select name="end_minute"
                                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:border-brand-500 focus:outline-none">
                                <option value="00">00</option>
                                <option value="30">30</option>
                            </select>
                            <select name="end_ampm"
                                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:border-brand-500 focus:outline-none">
                                <option value="AM">AM</option>
                                <option value="PM" selected>PM</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Leave Fields -->
                <div id="leave-fields" class="hidden">
                    <label class="block text-sm font-semibold text-slate-300 mb-2">Leave Note</label>
                    <input type="text" name="leave_note" placeholder="e.g., Vacation Leave, Sick Leave..."
                        class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white placeholder-slate-500 focus:border-brand-500 focus:outline-none">
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="deleteEntry()" id="delete-btn"
                        class="px-4 py-3 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 font-medium transition-all hidden">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <button type="button" onclick="closeEntryModal()"
                        class="flex-1 px-6 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-medium transition-all">
                        Cancel
                    </button>
                    <button type="submit"
                        class="flex-1 px-6 py-3 rounded-xl bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-bold shadow-lg shadow-brand-600/25 transition-all">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentEntryId = null;
        let currentEntryDate = null;

        // Manager Dropdown Logic (Super Admin)
        <?php if ($isSuperAdmin): ?>
                (function () {
                    const input = document.getElementById('manager_search_calendar');
                    const hiddenInput = document.getElementById('manager_id_input');
                    const dropdown = document.getElementById('manager_dropdown_calendar');
                    const options = document.querySelectorAll('.calendar-mgr-option');
                    let isOptionSelected = false;

                    if (!input) return; // Dropdown not present

                    input.addEventListener('focus', () => {
                        dropdown.classList.remove('hidden');
                        filterManagers();
                    });

                    function filterManagers() {
                        const term = input.value.toLowerCase();
                        options.forEach(opt => {
                            const name = opt.getAttribute('data-name').toLowerCase();
                            const dept = opt.getAttribute('data-dept').toLowerCase();
                            if (name.includes(term) || dept.includes(term)) {
                                opt.classList.remove('hidden');
                            } else {
                                opt.classList.add('hidden');
                            }
                        });
                    }

                    input.addEventListener('input', () => {
                        isOptionSelected = false;
                        hiddenInput.value = '';
                        dropdown.classList.remove('hidden');
                        filterManagers();
                    });

                    options.forEach(opt => {
                        opt.addEventListener('click', () => {
                            input.value = opt.getAttribute('data-name');
                            hiddenInput.value = opt.getAttribute('data-id');
                            isOptionSelected = true;
                            dropdown.classList.add('hidden');
                        });
                    });

                    document.addEventListener('click', (e) => {
                        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                            dropdown.classList.add('hidden');
                            if (!isOptionSelected) {
                                // Optional: enforced selection logic
                                if (!hiddenInput.value) input.value = '';
                            }
                        }
                    });
                })();
        <?php endif; ?>

        // Load user photo
        fetch('actions/get_employee_info.php?manager_id=<?= $dmUserId ?>')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.photo_url) {
                    const img = document.getElementById('user-photo');
                    img.src = data.photo_url;

                    // Try alternative formats if first fails
                    img.onerror = function () {
                        if (data.employee_id) {
                            const formats = ['png', 'jpeg'];
                            let tried = 0;
                            const tryNext = () => {
                                if (tried < formats.length) {
                                    img.src = `http://10.2.0.8/lrnph/emp_photos/${data.employee_id}.${formats[tried]}`;
                                    tried++;
                                } else {
                                    img.src = 'assets/img/mystery-man.png';
                                }
                            };
                            img.onerror = tryNext;
                            tryNext();
                        } else {
                            img.src = 'assets/img/mystery-man.png';
                        }
                    };
                }

                if (data.success && data.department) {
                    document.getElementById('user-dept').textContent = data.department;
                }
            })
            .catch(err => console.error('Error loading employee info:', err));

        function openEntryModal(date, entryId = null, assignMode = false) {
            currentEntryDate = date;
            currentEntryId = entryId;

            // Debug
            console.log('openEntryModal called', { date, entryId, assignMode });

            document.getElementById('entry-date').value = date;
            document.getElementById('entry-id').value = entryId || '';

            const modal = document.getElementById('entry-modal');
            const modalTitle = document.getElementById('modal-title');
            const deleteBtn = document.getElementById('delete-btn');
            const mgrContainer = document.getElementById('manager-select-container');

            // Handle Assign Mode (Super Admin)
            if (mgrContainer) {
                if (assignMode) {
                    mgrContainer.classList.remove('hidden');
                    // Reset manager selection if adding new
                    if (!entryId) {
                        document.getElementById('manager_search_calendar').value = '';
                        document.getElementById('manager_id_input').value = '';
                    }
                } else {
                    mgrContainer.classList.add('hidden');
                    // Ensure we clear any set manager ID so it defaults to session user in backend if not strict
                    document.getElementById('manager_id_input').value = '';
                }
            }

            if (entryId) {
                modalTitle.textContent = 'Edit Schedule';
                deleteBtn.classList.remove('hidden');

                // Load entry data
                loadEntryData(entryId);
            } else {
                modalTitle.textContent = 'Add Schedule for ' + new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                deleteBtn.classList.add('hidden');
                document.getElementById('entry-form').reset();
                toggleEntryType();

                // If assign mode, re-show (reset might hide or clear it)
                if (assignMode && mgrContainer) {
                    // Reset cleared it, so we need to ensure hidden input is empty
                    document.getElementById('manager_id_input').value = '';
                    setTimeout(() => {
                        const input = document.getElementById('manager_search_calendar');
                        if (input) input.focus();
                    }, 50);
                }
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        function closeEntryModal() {
            const modal = document.getElementById('entry-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 200);
        }

        function toggleEntryType() {
            const entryType = document.querySelector('input[name="entry_type"]:checked').value;
            const workFields = document.getElementById('work-fields');
            const leaveFields = document.getElementById('leave-fields');

            if (entryType === 'WORK') {
                workFields.classList.remove('hidden');
                leaveFields.classList.add('hidden');
            } else {
                workFields.classList.add('hidden');
                leaveFields.classList.remove('hidden');
            }
        }

        function loadEntryData(entryId) {
            // Find entry data from the existing entries
            const dateStr = currentEntryDate;
            <?php foreach ($entriesByDate as $date => $entry): ?>
                if ('<?= $date ?>' === dateStr && <?= $entry['ID'] ?> == entryId) {
                    const entryType = '<?= $entry['EntryType'] ?>';
                    document.querySelector(`input[name="entry_type"][value="${entryType}"]`).checked = true;
                    toggleEntryType();

                    if (entryType === 'WORK') {
                        const startTime = '<?= $entry['StartTime'] ?>'.split(/[:\s]+/);
                        const endTime = '<?= $entry['EndTime'] ?>'.split(/[:\s]+/);

                        document.querySelector('select[name="start_hour"]').value = parseInt(startTime[0]);
                        document.querySelector('select[name="start_minute"]').value = startTime[1];
                        document.querySelector('select[name="start_ampm"]').value = startTime[2];

                        document.querySelector('select[name="end_hour"]').value = parseInt(endTime[0]);
                        document.querySelector('select[name="end_minute"]').value = endTime[1];
                        document.querySelector('select[name="end_ampm"]').value = endTime[2];
                    } else {
                        document.querySelector('input[name="leave_note"]').value = '<?= addslashes($entry['LeaveNote']) ?>';
                    }
                }
            <?php endforeach; ?>
        }

        function saveEntry(event) {
            event.preventDefault();

            const formData = new FormData(event.target);

            fetch('actions/save_calendar.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Refresh team view if in All Managers mode, otherwise reload
                        if (typeof currentView !== 'undefined' && currentView === 'all') {
                            closeEntryModal();
                            loadAllSchedules();
                        } else {
                            location.reload();
                        }
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error('Error saving entry:', err);
                    alert('Failed to save entry. Please try again.');
                });
        }

        function deleteEntry() {
            if (!currentEntryId) return;

            if (!confirm('Are you sure you want to delete this entry?')) return;

            const formData = new FormData();
            formData.append('entry_id', currentEntryId);

            fetch('actions/delete_calendar_entry.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error('Error deleting entry:', err);
                    alert('Failed to delete entry. Please try again.');
                });
        }

        /* Batch Mode Logic */
        let isBatchMode = false;
        let selectedDates = new Set();
        let selectedManagerIds = new Set(); // For Batch Multi-Select

        function toggleBatchMode() {
            isBatchMode = !isBatchMode;
            const btn = document.getElementById('batch-mode-btn');

            if (isBatchMode) {
                document.body.classList.add('batch-mode-active');
                if (btn) btn.classList.add('active');
                // Hint
                const hint = document.getElementById('hint-text');
                if (hint) hint.textContent = "Select days to edit in bulk";
            } else {
                document.body.classList.remove('batch-mode-active');
                if (btn) btn.classList.remove('active');
                // Reset Selection
                selectedDates.clear();
                document.querySelectorAll('.calendar-day.selected').forEach(el => el.classList.remove('selected'));
                updateBatchToolbar();
                // Reset Hint
                const hint = document.getElementById('hint-text');
                if (hint) hint.textContent = "Click on any day to add or edit your schedule";
            }
        }

        function handleDayClick(el, date, id) {
            // If we are in batch mode
            if (isBatchMode) {
                if (selectedDates.has(date)) {
                    selectedDates.delete(date);
                    el.classList.remove('selected');
                } else {
                    selectedDates.add(date);
                    el.classList.add('selected');
                }
                updateBatchToolbar();
            } else {
                // Normal mode
                if (typeof currentView !== 'undefined' && currentView === 'all') {
                    openDayDetail(date);
                } else {
                    openEntryModal(date, id);
                }
            }
        }

        function updateBatchToolbar() {
            const toolbar = document.getElementById('batch-toolbar');
            const countEl = document.getElementById('selected-count');

            countEl.textContent = selectedDates.size;

            if (selectedDates.size > 0) {
                toolbar.classList.remove('translate-y-full');
                toolbar.classList.add('translate-y-0');
            } else {
                toolbar.classList.add('translate-y-full');
                toolbar.classList.remove('translate-y-0');
            }
        }

        function openBatchEntryModal() {
            if (selectedDates.size === 0) return;

            // Use the existing modal but modify it for batch
            const modal = document.getElementById('entry-modal');
            const modalTitle = document.getElementById('modal-title');
            const deleteBtn = document.getElementById('delete-btn');
            const form = document.getElementById('entry-form');

            // Toggle Manager Select UI for Super Admin
            const mgrContainer = document.getElementById('manager-select-container');
            const singleSelect = document.getElementById('single-manager-select');
            const multiSelect = document.getElementById('multi-manager-select');

            if (mgrContainer) {
                mgrContainer.classList.remove('hidden');
                if (singleSelect) singleSelect.classList.add('hidden');
                if (multiSelect) {
                    multiSelect.classList.remove('hidden');
                    // Reset Multi Select
                    selectedManagerIds.clear();
                    updateMultiSelectUI();
                }
            }

            modalTitle.textContent = `Batch Edit (${selectedDates.size} days)`;
            deleteBtn.classList.add('hidden');

            // Explicitly set onsubmit to batch handler
            form.onsubmit = function (e) {
                saveBatchEntry(e);
            };

            // Don't pre-fill data, let user set new values
            form.reset();
            // Default to WORK type
            const workRadio = document.querySelector('input[name="entry_type"][value="WORK"]');
            if (workRadio) {
                workRadio.checked = true;
                toggleEntryType();
            }

            // Show modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        // Override existing close to reset form handler
        const originalOpenEntryModal = openEntryModal;
        openEntryModal = function (date, entryId = null, assignMode = false) {
            // Reset onsubmit to default
            const form = document.getElementById('entry-form');
            if (form) form.onsubmit = saveEntry;

            // Reset Manager Select UI
            const mgrContainer = document.getElementById('manager-select-container');
            const singleSelect = document.getElementById('single-manager-select');
            const multiSelect = document.getElementById('multi-manager-select');

            if (mgrContainer) {
                if (singleSelect) singleSelect.classList.remove('hidden');
                if (multiSelect) multiSelect.classList.add('hidden');
            }

            if (originalOpenEntryModal) originalOpenEntryModal(date, entryId, assignMode);
        };

        function confirmBatchAction(title, message, iconClass, actionCallback, showScopeOptions = false) {
            const modal = document.getElementById('batch-confirm-modal');
            document.getElementById('confirm-title').textContent = title;
            document.getElementById('confirm-message').textContent = message;

            const icon = document.getElementById('confirm-icon');
            icon.className = iconClass;

            const btn = document.getElementById('confirm-action-btn');
            // Clone button to remove old listeners
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);

            // Additional Buttons container for Scope (if Super Admin delete)
            let scopeContainer = document.getElementById('scope-options-container');
            if (!scopeContainer) {
                scopeContainer = document.createElement('div');
                scopeContainer.id = 'scope-options-container';
                scopeContainer.className = 'hidden flex-col gap-2 mb-4 w-full';
                // Insert before buttons
                modal.querySelector('.flex.gap-3').before(scopeContainer);
            }
            scopeContainer.innerHTML = ''; // Clear previous

            if (showScopeOptions && document.body.dataset.isSuperAdmin === '1') {
                scopeContainer.classList.remove('hidden');
                document.getElementById('confirm-message').classList.add('mb-4'); // Add spacing

                // Create Scope Buttons
                ['Selected Managers Only', 'Everyone on These Days'].forEach((text, idx) => {
                    const scopeBtn = document.createElement('button');
                    scopeBtn.type = 'button';
                    scopeBtn.className = `w-full text-left px-4 py-3 rounded-xl border border-slate-700 bg-slate-800/50 hover:bg-slate-700 transition-colors flex items-center justify-between group ${idx === 0 ? 'border-brand-500 bg-brand-500/10' : ''}`;
                    scopeBtn.dataset.scope = idx === 0 ? 'specific' : 'all';
                    scopeBtn.innerHTML = `
                        <span class="text-sm font-medium text-white">${text}</span>
                        <div class="w-4 h-4 rounded-full border border-slate-500 flex items-center justify-center group-hover:border-white">
                            <div class="w-2 h-2 rounded-full bg-brand-500 ${idx === 0 ? '' : 'hidden'}"></div>
                        </div>
                     `;

                    scopeBtn.onclick = function () {
                        // Toggle active state
                        scopeContainer.querySelectorAll('button').forEach(b => {
                            b.classList.remove('border-brand-500', 'bg-brand-500/10');
                            b.querySelector('.w-2.h-2').classList.add('hidden');
                        });
                        this.classList.add('border-brand-500', 'bg-brand-500/10');
                        this.querySelector('.w-2.h-2').classList.remove('hidden');

                        // Store selection
                        scopeContainer.dataset.selectedScope = this.dataset.scope;

                        // Toggle Manager Selector Visibility
                        const mgrSelect = document.getElementById('delete-manager-select-container');
                        if (mgrSelect) {
                            if (this.dataset.scope === 'specific') {
                                mgrSelect.classList.remove('hidden');
                                // Reset selection if needed, or keep it? Let's keep it.
                            } else {
                                mgrSelect.classList.add('hidden');
                            }
                        }
                    };

                    scopeContainer.appendChild(scopeBtn);
                });
                // Default
                scopeContainer.dataset.selectedScope = 'specific';

                // Show manager search by default since specific is selected
                const mgrSelect = document.getElementById('delete-manager-select-container');
                if (mgrSelect) mgrSelect.classList.remove('hidden');

                newBtn.onclick = function () {
                    const scope = scopeContainer.dataset.selectedScope;
                    actionCallback(scope);
                    closeBatchConfirm();
                };
            } else {
                scopeContainer.classList.add('hidden');
                newBtn.onclick = function () {
                    actionCallback();
                    closeBatchConfirm();
                };
            }

            if (title.toLowerCase().includes('delete') || title.toLowerCase().includes('clear')) {
                newBtn.className = "flex-1 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold shadow-lg shadow-rose-600/25 transition-all";
            } else {
                newBtn.className = "flex-1 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white font-bold shadow-lg shadow-brand-600/25 transition-all";
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        function closeBatchConfirm() {
            const modal = document.getElementById('batch-confirm-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 200);
        }

        function saveBatchEntry(event) {
            event.preventDefault();

            const formData = new FormData(event.target);
            // Append selected dates
            Array.from(selectedDates).forEach(date => {
                formData.append('dates[]', date);
            });

            // Append Selected Managers (if any)
            if (selectedManagerIds.size > 0) {
                Array.from(selectedManagerIds).forEach(id => {
                    formData.append('manager_ids[]', id);
                });
            }

            confirmBatchAction(
                'Update Schedule?',
                `This will overwrite schedules for ${selectedDates.size} selected days${selectedManagerIds.size > 0 ? ' for ' + selectedManagerIds.size + ' managers' : ''}.`,
                'fas fa-exclamation-triangle text-3xl text-brand-500',
                function () {
                    performBatchSave(formData);
                }
            );
        }

        function performBatchSave(formData) {
            fetch('actions/batch_save_calendar.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Batch save failed');
                });
        }

        function confirmBatchDelete() {
            if (selectedDates.size === 0) return;

            // If Super Admin, show scope options
            const isSuper = document.body.dataset.isSuperAdmin === '1';
            
            if (isSuper) {
                loadManagersForDelete(Array.from(selectedDates));
            }

            confirmBatchAction(
                'Clear Schedules?',
                isSuper ? 'Who do you want to remove schedules for?' : `Are you sure you want to clear schedules for ${selectedDates.size} days?`,
                'fas fa-trash text-3xl text-rose-500',
                function (scope) {
                    const formData = new FormData();
                    Array.from(selectedDates).forEach(date => {
                        formData.append('dates[]', date);
                    });

                    if (isSuper) {
                        formData.append('delete_scope', scope || 'self');

                        // Append selected manager IDs if specific scope
                        if (scope === 'specific' && typeof deleteSelectedManagerIds !== 'undefined') {
                            if (deleteSelectedManagerIds.size === 0) {
                                alert("Please select at least one manager to delete.");
                                return;
                            }
                            Array.from(deleteSelectedManagerIds).forEach(id => {
                                formData.append('manager_ids[]', id);
                            });
                        }

                        // If specific managers selected but not in the modal (using some global selection state?), 
                        // Actually, for delete, we haven't selected managers yet if we just clicked "Clear" from toolbar.
                        // Ideally we should prompt for manager selection OR just use "All".
                        // BUT, if we have a robust "Delete All", maybe that's enough?
                        // User request: "Batch Delete all person assigned". 
                        // So "All" is the primary need.

                        // Refinement: If they choose "Selected Managers Only", we should probably use the ones 
                        // they *might* have selected if we had a selection UI. 
                        // CURRENTLY: We don't have a manager selection UI open when clicking "Clear".
                        // So "Selected Managers" is ambiguous unless we open the selection modal first.

                        // Logic Change: If "Specific" is chosen, show an error or (better) open the manager selector.
                        // OR: Just interpret "Specific" as "Currently Logged In User" for now, or don't offer it if no selection made.
                        // BETTER: If "Specific" is passed, we check if we have IDs. If not, maybe we just default to ALL for now based on request?
                        // "Batch Delete all person assigned on the days they chose". -> This implies ALL.

                        // Let's keep it simple: Just Delete All or Delete My Own?
                        // Actually, let's pass an empty manager_ids array if 'specific' is chosen without IDs, 
                        // which the backend should handle (or we can prompt).

                        // For this iteration, let's assume 'specific' means "Select Managers" which requires a UI we haven't built for *Deletion* specifically.
                        // So I will piggyback on the Entry Modal logic: clicking "Clear" in the Entry Modal sends selected IDs.
                        // But here we are clicking "Clear" from the floating toolbar.

                        // FIX: If "Specific" is chosen in the confirm callback, we'll ask them to select managers.
                        // But that's complicated.
                        // Alternative: "Delete" button inside the Batch Modal (where manager selection exists).
                        // Let's modify `openBatchEntryModal` to have a Delete button!
                        // And remove the "Clear" button from the floating toolbar? Or make it "Delete All".

                        // Let's stick to the floating toolbar "Clear" being "Delete All" or "Delete Me".
                        // If they want to delete specific others, they can use the Batch Edit modal -> "Clear" button (which I'll add).
                    }

                    fetch('actions/batch_delete_calendar.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            alert('Batch delete failed');
                        });
                },
                isSuper // Valid to show scope options
            );
        }

        // Multi-Select JS Logic
        (function () {
            const multiSearch = document.getElementById('multi_manager_search');
            const multiDropdown = document.getElementById('multi_manager_dropdown');
            const options = document.querySelectorAll('.multi-mgr-option');

            if (!multiSearch) return;

            multiSearch.addEventListener('focus', () => multiDropdown.classList.remove('hidden'));

            multiSearch.addEventListener('input', () => {
                const term = multiSearch.value.toLowerCase();
                multiDropdown.classList.remove('hidden');
                options.forEach(opt => {
                    const name = opt.getAttribute('data-name').toLowerCase();
                    if (name.includes(term)) {
                        opt.classList.remove('hidden');
                        opt.classList.add('flex');
                    } else {
                        opt.classList.add('hidden');
                        opt.classList.remove('flex');
                    }
                });
            });

            // Toggle Logic
            options.forEach(opt => {
                opt.addEventListener('click', () => {
                    const id = opt.getAttribute('data-id');
                    const name = opt.getAttribute('data-name');

                    toggleManagerSelection(id, name);
                });
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!multiSearch.contains(e.target) && !multiDropdown.contains(e.target)) {
                    multiDropdown.classList.add('hidden');
                }
            });
        })();

        function toggleManagerSelection(id, name) {
            if (selectedManagerIds.has(id)) {
                selectedManagerIds.delete(id);
            } else {
                selectedManagerIds.add(id);
            }
            updateMultiSelectUI();
        }

        function selectAllManagers() {
            const options = document.querySelectorAll('.multi-mgr-option');
            options.forEach(opt => {
                const id = opt.getAttribute('data-id');
                selectedManagerIds.add(id);
            });
            updateMultiSelectUI();
        }

        function updateMultiSelectUI() {
            // Update Selected Count
            const countEl = document.getElementById('multi-selected-count');
            if (countEl) countEl.textContent = `${selectedManagerIds.size} selected`;

            // Update Checkboxes in Dropdown
            document.querySelectorAll('.multi-mgr-option').forEach(opt => {
                const id = opt.getAttribute('data-id');
                const check = opt.querySelector('.option-checkbox i');
                const box = opt.querySelector('.option-checkbox');

                if (selectedManagerIds.has(id)) {
                    check.classList.remove('opacity-0', 'scale-0');
                    box.classList.add('border-brand-500', 'bg-brand-500/10');
                    box.classList.remove('border-slate-500');
                } else {
                    check.classList.add('opacity-0', 'scale-0');
                    box.classList.remove('border-brand-500', 'bg-brand-500/10');
                    box.classList.add('border-slate-500');
                }
            });

            // Render Tags
            const container = document.getElementById('multi-tags-container');
            if (container) {
                container.innerHTML = '';
                selectedManagerIds.forEach(id => {
                    // Find name
                    const opt = document.querySelector(`.multi-mgr-option[data-id="${id}"]`);
                    const name = opt ? opt.getAttribute('data-name') : 'Unknown';

                    const tag = document.createElement('div');
                    tag.className = 'px-2 py-1 bg-brand-500/20 border border-brand-500/30 rounded text-xs text-brand-300 flex items-center gap-2';
                    tag.innerHTML = `
                        <span>${name}</span>
                        <button type="button" onclick="toggleManagerSelection('${id}')" class="hover:text-white"><i class="fas fa-times"></i></button>
                    `;
                    container.appendChild(tag);
                });
            }
        }

        // ============================================
        // ============================================
        // DELETE MANAGER SELECTION LOGIC (DYNAMIC)
        // ============================================
        let deleteSelectedManagerIds = new Set();
        let currentLoadRequest = null;

        function loadManagersForDelete(dates) {
            const dropdown = document.getElementById('delete_manager_dropdown');
            dropdown.innerHTML = '<div class="px-4 py-3 text-slate-400 text-sm italic">Loading managers...</div>';

            // Clear previous selection reset? Or keep? 
            // If we change dates, we probably should reset selection to avoid deleting for a manager not in the new list.
            // But if we just want to refine, maybe keep. 
            // Safer to clear for now to avoid confusion.
            deleteSelectedManagerIds.clear();
            updateDeleteMultiSelectUI();

            const formData = new FormData();
            dates.forEach(d => formData.append('dates[]', d));

            fetch('actions/get_assigned_managers.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderDeleteManagerOptions(data.managers);
                    } else {
                        dropdown.innerHTML = `<div class="px-4 py-3 text-rose-400 text-sm">Error: ${data.message}</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    dropdown.innerHTML = '<div class="px-4 py-3 text-rose-400 text-sm">Failed to load managers</div>';
                });
        }

        function renderDeleteManagerOptions(managers) {
            const dropdown = document.getElementById('delete_manager_dropdown');
            if (managers.length === 0) {
                dropdown.innerHTML = '<div class="px-4 py-3 text-slate-500 text-sm italic">No managers assigned on these dates.</div>';
                return;
            }

            let html = '';
            managers.forEach(mgr => {
                html += `
                    <div class="delete-mgr-option px-4 py-3 hover:bg-slate-700 cursor-pointer transition-colors border-b border-slate-700/50 last:border-0 flex items-center justify-between"
                        data-id="${mgr.ID}" data-name="${mgr.Name}">
                        <div class="flex flex-col">
                            <span class="text-sm text-white font-medium">${mgr.Name}</span>
                            <span class="text-xs text-slate-500">${mgr.Department || ''}</span>
                        </div>
                        <div class="w-4 h-4 rounded border border-slate-500 flex items-center justify-center option-checkbox">
                            <i class="fas fa-check text-xs text-rose-500 opacity-0 transform scale-0 transition-all"></i>
                        </div>
                    </div>
                `;
            });
            dropdown.innerHTML = html;
        }

        // Init Search & Delegation
        (function () {
            const search = document.getElementById('delete_manager_search');
            const dropdown = document.getElementById('delete_manager_dropdown');

            if (!search) return;

            search.addEventListener('focus', () => dropdown.classList.remove('hidden'));

            search.addEventListener('input', () => {
                const term = search.value.toLowerCase();
                dropdown.classList.remove('hidden');

                // Filter currently rendered options
                const options = dropdown.querySelectorAll('.delete-mgr-option');
                options.forEach(opt => {
                    const name = opt.getAttribute('data-name').toLowerCase();
                    if (name.includes(term)) {
                        opt.classList.remove('hidden');
                        opt.classList.add('flex');
                    } else {
                        opt.classList.add('hidden');
                        opt.classList.remove('flex');
                    }
                });
            });

            // Use Event Delegation for Click on Options
            dropdown.addEventListener('click', (e) => {
                const opt = e.target.closest('.delete-mgr-option');
                if (opt) {
                    const id = opt.getAttribute('data-id');
                    toggleDeleteManagerSelection(id);
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!search.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        })();

        function toggleDeleteManagerSelection(id) {
            if (deleteSelectedManagerIds.has(id)) {
                deleteSelectedManagerIds.delete(id);
            } else {
                deleteSelectedManagerIds.add(id);
            }
            updateDeleteMultiSelectUI();
        }

        function selectAllDeleteManagers() {
            const options = document.querySelectorAll('.delete-mgr-option'); // live query
            options.forEach(opt => {
                // Only select visible ones (if searching)? 
                // Usually "Select All" means all available in list.
                // Let's select all currently rendered.
                if (!opt.classList.contains('hidden')) {
                    const id = opt.getAttribute('data-id');
                    deleteSelectedManagerIds.add(id);
                }
            });
            updateDeleteMultiSelectUI();
        }

        function updateDeleteMultiSelectUI() {
            // Update Selected Count
            const countEl = document.getElementById('delete-selected-count');
            if (countEl) countEl.textContent = `${deleteSelectedManagerIds.size} selected`;

            // Update Checkboxes in Dropdown
            document.querySelectorAll('.delete-mgr-option').forEach(opt => {
                const id = opt.getAttribute('data-id');
                const check = opt.querySelector('.option-checkbox i');
                const box = opt.querySelector('.option-checkbox');

                if (deleteSelectedManagerIds.has(id)) {
                    check.classList.remove('opacity-0', 'scale-0');
                    box.classList.add('border-rose-500', 'bg-rose-500/10');
                    box.classList.remove('border-slate-500');
                } else {
                    check.classList.add('opacity-0', 'scale-0');
                    box.classList.remove('border-rose-500', 'bg-rose-500/10');
                    box.classList.add('border-slate-500');
                }
            });

            // Render Tags
            const container = document.getElementById('delete-tags-container');
            if (container) {
                container.innerHTML = '';
                deleteSelectedManagerIds.forEach(id => {
                    // Find name - might not be in DOM if filtered or not loaded, 
                    // but we can try to find it. 
                    const opt = document.querySelector(`.delete-mgr-option[data-id="${id}"]`);
                    // If not found (e.g. search filtered out), ideally we still show the tag.
                    // But we don't store names in the Set. 
                    // For now, if opt is missing, use "ID:..." or try to keep a Map of names.
                    // Simple fix: Assuming user just selected it, it's likely in the list.

                    const name = opt ? opt.getAttribute('data-name') : 'Manager';

                    const tag = document.createElement('div');
                    tag.className = 'px-2 py-1 bg-rose-500/20 border border-rose-500/30 rounded text-xs text-rose-300 flex items-center gap-2';
                    tag.innerHTML = `
                        <span>${name}</span>
                        <button type="button" onclick="toggleDeleteManagerSelection('${id}')" class="hover:text-white"><i class="fas fa-times"></i></button>
                    `;
                    container.appendChild(tag);
                });
            }
        }
    </script>


    <!-- Day Detail Modal -->
    <?php include 'includes/day-detail-modal.html'; ?>

    <!-- Team View JavaScript -->
    <script src="assets/js/calendar-team-view.js?v=<?= time() ?>"></script>

</body>

</html>
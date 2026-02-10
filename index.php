<?php
// index.php - Dashboard for Duty Manager Checklist
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'config/db.php';

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    header("Location: login.php");
    exit();
}

// Auto-submit expired checklists (Run only for logged-in users to avoid overhead)
// And wrap in try-catch to prevent page crash
try {
    require_once 'actions/auto_submit.php';
    autoSubmitExpiredChecklists();
} catch (Throwable $e) {
    error_log("Auto-submit error: " . $e->getMessage());
}

$userName = $_SESSION['dm_name'] ?? $_SESSION['full_name'] ?? 'User';
$userDept = $_SESSION['dm_department'] ?? $_SESSION['dept'] ?? 'N/A';
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
$dmUserId = $_SESSION['dm_user_id'];

// Get Philippines time
$phTime = getPhilippinesTime();
$today = $phTime->format('Y-m-d');
$dayName = $phTime->format('l');
$isSundayToday = isSunday();

// Calculate "Target Date" - The nearest schedule >= Today
// If found, that is our target. If not, maybe just default to today?
$targetDateQuery = "SELECT ScheduledDate FROM DM_Schedules WHERE ScheduledDate >= ? ORDER BY ScheduledDate ASC LIMIT 1";
$targetDateResult = dbQueryOne($targetDateQuery, [$today]);

$targetDate = $targetDateResult ? $targetDateResult['ScheduledDate'] : $today; // Default to today if no future schedule

// Check all managers assigned for this Target Date
$currentSchedules = dbQuery("SELECT s.ID, u.Name as ManagerName, s.Timeline 
                              FROM DM_Schedules s 
                              JOIN DM_Users u ON s.ManagerID = u.ID 
                              WHERE s.ScheduledDate = ?", [$targetDate]);

$dutyManagers = [];
if ($currentSchedules) {
    foreach ($currentSchedules as $sched) {
        $isMe = ($sched['ManagerName'] === $userName);
        $dutyManagers[] = [
            'name' => $isMe ? 'You' : $sched['ManagerName'],
            'fullName' => $sched['ManagerName'],
            'timeline' => $sched['Timeline'] ?? '',
            'isMe' => $isMe
        ];
    }
}

// Sort so "You" appears first
usort($dutyManagers, function ($a, $b) {
    return $b['isMe'] <=> $a['isMe'];
});

$myScheduleThisWeek = dbQueryOne(
    "SELECT ID FROM DM_Schedules WHERE ManagerID = ? AND ScheduledDate = ?",
    [$dmUserId, $targetDate]
);
$isAssignedThisWeek = (bool) $myScheduleThisWeek;

// Get upcoming schedules for this user
$upcomingQuery = "SELECT s.ID, s.ScheduledDate
                  FROM DM_Schedules s
                  WHERE s.ManagerID = ? AND s.ScheduledDate >= ?
                  ORDER BY s.ScheduledDate ASC
                  LIMIT 5";
$upcomingSchedules = dbQuery($upcomingQuery, [$dmUserId, $today]);

// Get next 8 Distinct Scheduled Dates for calendar view
// Instead of just 8 Sundays, show the next 8 days that actually HAVE schedules
$calendarDates = [];

// Query for next 8 UNIQUE dates with schedules
$datesQuery = "SELECT DISTINCT ScheduledDate
               FROM DM_Schedules
               WHERE ScheduledDate >= ?
               ORDER BY ScheduledDate ASC
               LIMIT 8";
$rangeSchedulesDates = dbQuery($datesQuery, [$today]);

if ($rangeSchedulesDates) {
    // Collect dates to query details
    $datesList = [];
    foreach ($rangeSchedulesDates as $d) {
        $datesList[] = $d['ScheduledDate'];
    }

    // Fetch managers for these dates
    if (!empty($datesList)) {
        // Create placeholders
        $placeholders = implode(',', array_fill(0, count($datesList), '?'));

        $detailsQuery = "SELECT s.ScheduledDate, u.Name as ManagerName
                         FROM DM_Schedules s 
                         JOIN DM_Users u ON s.ManagerID = u.ID
                         WHERE s.ScheduledDate IN ($placeholders)"; // Note: IN clause with dates is fine if format matches

        $rangeSchedules = dbQuery($detailsQuery, $datesList);

        // Group by Date
        $schedulesByDate = [];
        if ($rangeSchedules) {
            foreach ($rangeSchedules as $rs) {
                $d = (new DateTime($rs['ScheduledDate']))->format('Y-m-d');
                if (!isset($schedulesByDate[$d])) {
                    $schedulesByDate[$d] = [];
                }
                $schedulesByDate[$d][] = $rs['ManagerName'];
            }
        }

        foreach ($rangeSchedulesDates as $dateRecord) {
            $dateStr = $dateRecord['ScheduledDate'];
            $calendarDates[] = [
                'date' => $dateStr,
                'dateObj' => new DateTime($dateRecord['ScheduledDate']),
                'managers' => $schedulesByDate[$dateStr] ?? [],
                'isTarget' => ($dateStr === $targetDate),
                'isToday' => ($dateStr === $today)
            ];
        }
    }
} else {
    // Fallback: If no future schedules, practically empty
    $calendarDates = [];
}


// Check for active session this week
$activeSession = null;
if ($targetDate) {
    $sessionQuery = "SELECT ID, Status FROM DM_Checklist_Sessions 
                     WHERE SessionDate = ?";
    $activeSession = dbQueryOne($sessionQuery, [$targetDate]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard • Duty Manager Checklist</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/favicon.png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Ionicons & Tailwind -->
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
    </style>
</head>

<body class="text-slate-100 min-h-screen selection:bg-brand-500 selection:text-white">

    <!-- Navbar -->
    <header class="sticky top-0 z-50 glass-header transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Logo / Title -->
                <div class="flex items-center gap-4">
                    <div
                        class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-600 flex items-center justify-center shadow-lg shadow-brand-500/20">
                        <i class="fas fa-clipboard-check text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold tracking-tight text-white leading-tight">Duty Manager Checklist
                        </h1>
                        <p class="text-xs text-slate-400 font-medium">La Rose Noire Philippines</p>
                    </div>
                </div>

                <!-- User Profile -->
                <div class="flex items-center gap-4">
                    <img id="user-photo" src="assets/img/mystery-man.png" alt="Profile"
                        class="w-12 h-12 rounded-full border-2 border-white/10 shadow-lg"
                        onerror="this.src='assets/img/mystery-man.png'">

                    <div class="hidden md:block">
                        <p class="text-sm font-semibold text-white"><?= htmlspecialchars($userName) ?></p>
                        <p class="text-xs text-slate-400"><?= htmlspecialchars($userDept) ?></p>
                    </div>

                    <a href="actions/logout.php"
                        class="group relative w-10 h-10 rounded-xl bg-slate-800/50 hover:bg-rose-500/20 border border-white/5 hover:border-rose-500/30 flex items-center justify-center transition-all duration-300"
                        title="Logout">
                        <i class="fas fa-sign-out-alt text-slate-400 group-hover:text-rose-400 transition-colors"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Layout -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">

        <!-- Welcome Section -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <h2 class="text-3xl font-bold text-white mb-2">
                    Welcome back, <span
                        class="bg-gradient-to-r from-brand-300 to-indigo-300 bg-clip-text text-transparent"><?= htmlspecialchars(explode(' ', $userName)[0]) ?></span>
                </h2>
                <p class="text-slate-400 flex items-center gap-2 text-sm">
                    <i class="fas fa-calendar-alt text-brand-500"></i>
                    <?= $phTime->format('l, F j, Y') ?>
                    <span class="w-1 h-1 rounded-full bg-slate-600"></span>
                    <i class="far fa-clock text-brand-500"></i>
                    <?= $phTime->format('g:i A') ?> PHT
                </p>
            </div>

            <?php if ($isSuperAdmin): ?>
                <a href="admin_schedule.php"
                    class="px-5 py-2.5 rounded-xl bg-slate-800/50 hover:bg-slate-800 text-slate-300 hover:text-white border border-white/5 text-sm font-medium transition-all flex items-center gap-2">
                    <i class="fas fa-user-shield text-amber-500"></i>
                    Manage Schedules
                </a>
            <?php endif; ?>
        </div>

        <!-- Hero Card: This Week's Status -->
        <div class="glass-card rounded-3xl p-8 relative overflow-hidden group">
            <!-- Decorative Glow -->
            <div
                class="absolute top-0 right-0 w-64 h-64 bg-brand-500/10 blur-[80px] rounded-full pointer-events-none group-hover:bg-brand-500/20 transition-all duration-700">
            </div>

            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-8">
                <div class="flex-1 space-y-4">
                    <div
                        class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-500/10 border border-brand-500/20 text-brand-300 text-xs font-semibold uppercase tracking-wider">
                        <span class="relative flex h-2 w-2">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-brand-500"></span>
                        </span>
                        <?= ($targetDate === $today) ? "Today's Duty" : "Next Upcoming Duty" ?>
                    </div>

                    <div>
                        <h3 class="text-4xl font-bold text-white mb-1">
                            <?php $tDateObj = new DateTime($targetDate); ?>
                            <?= $tDateObj->format('F j') ?>
                            <span class="text-2xl text-slate-500 font-normal"><?= $tDateObj->format(', Y') ?></span>
                        </h3>
                        Duty Managers:
                        </p>
                        <div class="flex flex-wrap gap-3 mt-2">
                            <?php if (count($dutyManagers) > 0): ?>
                                <?php foreach ($dutyManagers as $dm): ?>
                                    <div class="inline-flex items-center gap-3 px-4 py-2 rounded-xl border transition-all hover:bg-opacity-80
                                        <?= $dm['isMe']
                                            ? 'bg-brand-500/20 border-brand-500/30 text-brand-100 shadow-[0_0_15px_rgba(139,92,246,0.15)]'
                                            : 'bg-slate-800/60 border-white/10 text-slate-300' ?>">

                                        <div
                                            class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold shadow-inner
                                            <?= $dm['isMe'] ? 'bg-brand-500 text-white' : 'bg-slate-700 text-slate-400' ?>">
                                            <?= substr($dm['fullName'], 0, 1) ?>
                                        </div>

                                        <div class="flex flex-col leading-none">
                                            <span
                                                class="text-sm font-semibold tracking-wide"><?= htmlspecialchars($dm['name']) ?></span>
                                            <?php if ($dm['timeline']): ?>
                                                <span
                                                    class="text-[10px] opacity-60 mt-0.5 font-medium"><?= htmlspecialchars($dm['timeline']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-slate-500 italic">Not Assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="calendar.php"
                        class="px-6 py-4 rounded-2xl bg-slate-800/50 hover:bg-slate-800 border border-white/5 hover:border-white/10 text-white font-medium transition-all text-center flex items-center justify-center gap-2 min-w-[140px]">
                        <i class="fas fa-calendar-alt text-emerald-400"></i>
                        <span>Calendar</span>
                    </a>

                    <a href="history.php"
                        class="px-6 py-4 rounded-2xl bg-slate-800/50 hover:bg-slate-800 border border-white/5 hover:border-white/10 text-white font-medium transition-all text-center flex items-center justify-center gap-2 min-w-[140px]">
                        <i class="fas fa-history text-indigo-400"></i>
                        <span>History</span>
                    </a>

                    <?php
                    // Determine where the main button goes
                    $heroBtnLink = "javascript:void(0)";
                    $heroBtnOnClick = "";
                    $heroBtnText = "View Checklist";
                    $heroBtnIcon = "fa-eye";

                    if ($isAssignedThisWeek) {
                        $heroBtnLink = "checklist.php?date=" . $targetDate;
                        $heroBtnText = "Start Checklist";
                        $heroBtnIcon = "fa-pen-to-square";
                    } elseif ($targetDate) {
                        // Even if not assigned, they can view the calculated target date
                        $heroBtnLink = "checklist.php?date=" . $targetDate;
                        $heroBtnText = "View Checklist";
                    } else {
                        // No target date found at all
                        $heroBtnOnClick = "showNoAssignmentModal()";
                    }
                    ?>

                    <a href="<?= $heroBtnLink ?>" <?= $heroBtnOnClick ? 'onclick="' . $heroBtnOnClick . '"' : '' ?>
                        class="px-8 py-4 rounded-2xl bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-bold shadow-lg shadow-brand-600/25 transform hover:-translate-y-1 transition-all text-center flex items-center justify-center gap-3 min-w-[200px]">
                        <i class="fas <?= $heroBtnIcon ?>"></i>
                        <span><?= $heroBtnText ?></span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Schedule Grid -->
        <div class="grid lg:grid-cols-3 gap-8">

            <!-- Calendar View (Span 2) -->
            <div class="lg:col-span-2 glass-card rounded-3xl p-8">
                <div class="flex items-center gap-3 mb-6">
                    <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-400">
                        <i class="fas fa-calendar-week text-xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Upcoming Schedules</h3>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <?php if (empty($calendarDates)): ?>
                        <div class="col-span-4 text-center py-6 text-slate-500 italic">No upcoming schedules found.</div>
                    <?php else: ?>
                        <?php foreach ($calendarDates as $cDate): ?>
                            <div
                                class="group relative p-4 rounded-2xl border transition-all duration-300
                            <?= $cDate['isTarget']
                                ? 'bg-brand-500/10 border-brand-500/30'
                                : ($cDate['isToday'] ? 'bg-emerald-500/10 border-emerald-500/30' : 'bg-slate-800/40 border-white/5 hover:border-brand-500/30 hover:bg-slate-800/60') ?>">

                                <a href="checklist.php?date=<?= $cDate['date'] ?>" class="block h-full">
                                    <!-- Date Header -->
                                    <div class="space-y-1 mb-3">
                                        <p
                                            class="text-xs uppercase tracking-wider font-semibold <?= $cDate['isTarget'] ? 'text-brand-300' : 'text-slate-500' ?>">
                                            <?= $cDate['dateObj']->format('M') ?>
                                        </p>
                                        <p class="text-2xl font-bold text-white">
                                            <?= $cDate['dateObj']->format('j') ?>
                                        </p>
                                        <!-- Day Name -->
                                        <p class="text-[10px] text-slate-400 uppercase">
                                            <?= $cDate['dateObj']->format('D') ?>
                                        </p>
                                    </div>

                                    <!-- Managers Avatar Group -->
                                    <?php if (count($cDate['managers']) > 0): ?>
                                        <div class="flex -space-x-2 overflow-hidden mb-1 pl-1">
                                            <?php foreach (array_slice($cDate['managers'], 0, 3) as $mgr): ?>
                                                <div class="inline-block h-8 w-8 rounded-full ring-2 ring-[#13151b] bg-indigo-900 flex items-center justify-center text-xs font-bold text-indigo-200"
                                                    title="<?= htmlspecialchars($mgr) ?>">
                                                    <?= strtoupper(substr($mgr, 0, 1)) ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($cDate['managers']) > 3): ?>
                                                <div
                                                    class="inline-block h-8 w-8 rounded-full ring-2 ring-[#13151b] bg-slate-800 flex items-center justify-center text-[10px] text-slate-400">
                                                    +<?= count($cDate['managers']) - 3 ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-slate-400 truncate mt-2">
                                            <?php
                                            $firstNames = array_map(function ($m) {
                                                return explode(' ', $m)[0];
                                            }, $cDate['managers']);
                                            echo htmlspecialchars(implode(', ', $firstNames));
                                            ?>
                                        </p>
                                    <?php else: ?>
                                        <div class="h-8 flex items-center">
                                            <span class="text-xs text-slate-600 italic">Unassigned</span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($cDate['isTarget']): ?>
                                        <div
                                            class="absolute top-3 right-3 w-2 h-2 rounded-full bg-brand-500 shadow-[0_0_10px_rgba(139,92,246,0.5)]">
                                        </div>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Upcoming Shifts (Span 1) -->
            <div class="glass-card rounded-3xl p-8">
                <div class="flex items-center gap-3 mb-6">
                    <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-400">
                        <i class="fas fa-calendar-check text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-white">Your Schedule</h3>
                    </div>
                </div>

                <?php if ($upcomingSchedules && count($upcomingSchedules) > 0): ?>
                    <div class="space-y-3">
                        <?php foreach ($upcomingSchedules as $schedule):
                            $schedDate = new DateTime($schedule['ScheduledDate']);
                            $isThisWeek = $schedDate->format('Y-m-d') === $targetDate;
                            ?>
                            <a href="checklist.php?date=<?= $schedDate->format('Y-m-d') ?>" class="flex items-center gap-4 p-4 rounded-xl border transition-all hover:bg-slate-800/50
                                <?= $isThisWeek
                                    ? 'bg-gradient-to-r from-emerald-500/10 to-transparent border-emerald-500/20'
                                    : 'bg-slate-800/30 border-white/5' ?>">

                                <div class="flex flex-col items-center justify-center w-12 h-12 rounded-lg 
                                    <?= $isThisWeek ? 'bg-emerald-500 text-white' : 'bg-slate-700 text-slate-300' ?>">
                                    <span class="text-xs uppercase font-bold"><?= $schedDate->format('M') ?></span>
                                    <span class="text-lg font-bold leading-none"><?= $schedDate->format('j') ?></span>
                                </div>

                                <div>
                                    <p class="text-white font-medium"><?= $schedDate->format('l') ?></p>
                                    <?php if ($isThisWeek): ?>
                                        <p class="text-xs text-emerald-400 font-medium">Coming up this week!</p>
                                    <?php else: ?>
                                        <p class="text-xs text-slate-500">
                                            <?= $today > $schedDate->format('Y-m-d') ? 'Completed' : 'Upcoming' ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <div class="w-16 h-16 rounded-full bg-slate-800 flex items-center justify-center mx-auto mb-3">
                            <i class="far fa-calendar-times text-slate-500 text-2xl"></i>
                        </div>
                        <p class="text-slate-400">No upcoming schedules found.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>

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

    <!-- No Assignment Modal -->
    <div id="no-assign-modal"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="glass-card rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-slate-700 transform scale-95 transition-transform duration-300">
            <div class="text-center">
                <div class="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-calendar-times text-3xl text-slate-500"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">No Upcoming Duties</h3>
                <p class="text-slate-400 text-sm mb-6">You don't have any upcoming duty schedules assigned to you at the
                    moment.</p>
                <div class="flex gap-3">
                    <button onclick="closeNoAssignmentModal()"
                        class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                        Close
                    </button>
                    <!-- Optional: Link to upcoming schedules if they want to browse -->
                </div>
            </div>
        </div>
    </div>

    <script>
        function showNoAssignmentModal() {
            const modal = document.getElementById('no-assign-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('.glass-card').classList.remove('scale-95');
                modal.querySelector('.glass-card').classList.add('scale-100');
            }, 10);
        }

        function closeNoAssignmentModal() {
            const modal = document.getElementById('no-assign-modal');
            modal.querySelector('.glass-card').classList.remove('scale-100');
            modal.querySelector('.glass-card').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 200);
        }

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
            })
            .catch(err => console.error('Error loading employee info:', err));
    </script>

</html>
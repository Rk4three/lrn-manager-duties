<?php
// history.php - View past checklists with PDF export
session_start();
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

$phTime = getPhilippinesTime();
$today = $phTime->format('Y-m-d');

// Get filter parameters
$filterManager = $_GET['manager'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$filterYear = $_GET['year'] ?? date('Y');

// Build query for sessions
$sessionsQuery = "SELECT cs.ID as SessionID, cs.SessionDate, cs.Status, cs.SubmittedAt,
                         s.ID as ScheduleID, u.ID as ManagerID, u.Name as ManagerName,
                         (
                             SELECT STRING_AGG(u2.Name, ' & ' ORDER BY u2.Name)
                             FROM DM_Schedules s2
                             JOIN DM_Users u2 ON s2.ManagerID = u2.ID
                             WHERE s2.ScheduledDate = s.ScheduledDate
                         ) as AllManagers
                  FROM DM_Checklist_Sessions cs
                  JOIN DM_Schedules s ON cs.ScheduleID = s.ID
                  JOIN DM_Users u ON s.ManagerID = u.ID
                  WHERE cs.Status = 'Completed'";

$params = [];



if ($filterManager) {
    // Check if the selected manager has a schedule on the session date
    // This ensures we find the session even if it's linked to the partner's ScheduleID
    $sessionsQuery .= " AND EXISTS (
        SELECT 1 
        FROM DM_Schedules s_check 
        WHERE s_check.ScheduledDate = cs.SessionDate 
        AND s_check.ManagerID = ?
    )";
    $params[] = (int) $filterManager;
}

if ($filterMonth) {
    $sessionsQuery .= " AND EXTRACT(MONTH FROM cs.SessionDate) = ?";
    $params[] = (int) $filterMonth;
}

if ($filterYear) {
    $sessionsQuery .= " AND EXTRACT(YEAR FROM cs.SessionDate) = ?";
    $params[] = (int) $filterYear;
}

$sessionsQuery .= " ORDER BY cs.SessionDate DESC";
$sessions = dbQuery($sessionsQuery, $params);

// Get managers for filter (super admin only)
$managers = [];
$managers = dbQuery("SELECT u.\"ID\", u.\"Name\" 
                     FROM \"DM_Users\" u
                     WHERE u.\"IsActive\" = TRUE 
                     ORDER BY u.\"Name\"", []);

// Handle PDF export (Existing logic preserved with minor cleanup)
if (isset($_GET['export']) && isset($_GET['session_id'])) {
    $sessionId = (int) $_GET['session_id'];

    $sessionData = dbQueryOne("SELECT cs.ID, cs.SessionDate, cs.SubmittedAt, u.Name as SubmittedBy,
                                      (
                                          SELECT STRING_AGG(u2.Name, ' & ' ORDER BY u2.Name)
                                          FROM DM_Schedules s2
                                          JOIN DM_Users u2 ON s2.ManagerID = u2.ID
                                          WHERE s2.ScheduledDate = s.ScheduledDate
                                      ) as AllManagers
                               FROM DM_Checklist_Sessions cs
                               JOIN DM_Schedules s ON cs.ScheduleID = s.ID
                               JOIN DM_Users u ON s.ManagerID = u.ID
                               WHERE cs.ID = ?", [$sessionId]);

    $entriesData = dbQuery("SELECT e.*, i.Area, i.TaskName
                            FROM DM_Checklist_Entries e
                            JOIN DM_Checklist_Items i ON e.ItemID = i.ID
                            WHERE e.SessionID = ?
                            ORDER BY i.SortOrder", [$sessionId]);

    // Fetch photos for PDF (new logic)
    $sessionPhotos = dbQuery("SELECT * FROM DM_Checklist_Photos WHERE SessionID = ?", [$sessionId]);
    $photosByItem = [];
    foreach ($sessionPhotos as $p) {
        $photosByItem[$p['ItemID']][] = $p;
    }

    if ($sessionData) {
        $sessionDate = (new DateTime($sessionData['SessionDate']))->format('F j, Y');
        $submittedAt = $sessionData['SubmittedAt'] ? (new DateTime($sessionData['SubmittedAt']))->format('F j, Y g:i A') : 'N/A';
        $submittedBy = $sessionData['SubmittedBy'];
        $assignedManagers = $sessionData['AllManagers'];

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="UTF-8">
            <title>Checklist Report - <?= $sessionDate ?></title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    font-size: 11px;
                    padding: 20px;
                    color: #1a1a1a;
                    background: #fff;
                }

                .header {
                    text-align: center;
                    margin-bottom: 25px;
                    padding-bottom: 15px;
                    border-bottom: 3px solid #7c3aed;
                }

                .header h1 {
                    font-size: 22px;
                    color: #4c1d95;
                    margin: 0 0 5px 0;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }

                .header p {
                    margin: 0;
                    font-size: 13px;
                    color: #666;
                }

                .meta-grid {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 15px;
                    margin-bottom: 25px;
                    background: #f8fafc;
                    padding: 15px;
                    border-radius: 8px;
                    border: 1px solid #e2e8f0;
                }

                .meta-item {
                    display: flex;
                    flex-direction: column;
                }

                .meta-label {
                    font-size: 10px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: #64748b;
                    margin-bottom: 2px;
                    font-weight: 600;
                }

                .meta-value {
                    font-size: 13px;
                    font-weight: 600;
                    color: #0f172a;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px;
                }

                th,
                td {
                    border: 1px solid #e2e8f0;
                    padding: 10px 8px;
                    text-align: left;
                    vertical-align: middle;
                }

                th {
                    background: #7c3aed !important;
                    color: white !important;
                    font-weight: 600;
                    text-transform: uppercase;
                    font-size: 10px;
                    letter-spacing: 0.5px;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }

                tr:nth-child(even) {
                    background: #f8fafc;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }

                .badge {
                    padding: 3px 8px;
                    border-radius: 4px;
                    font-size: 10px;
                    font-weight: bold;
                    display: inline-block;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                    text-transform: uppercase;
                    border: 1px solid rgba(0, 0, 0, 0.05);
                }

                .badge-1st {
                    background: #dcfce7;
                    color: #14532d;
                }

                .badge-2nd {
                    background: #dbeafe;
                    color: #1e3a8a;
                }

                .badge-3rd {
                    background: #fef3c7;
                    color: #78350f;
                }

                .photo-cell {
                    text-align: center;
                    width: 120px;
                    padding: 4px;
                }

                .photo-thumb {
                    max-width: 100px;
                    max-height: 80px;
                    border-radius: 4px;
                    border: 1px solid #cbd5e1;
                    object-fit: cover;
                }

                .remarks-cell {
                    font-style: italic;
                    color: #475569;
                }

                .no-print {
                    text-align: center;
                    margin-top: 30px;
                    padding: 20px;
                    background: #f3f4f6;
                    border-radius: 8px;
                }

                .btn {
                    background: #7c3aed;
                    color: white;
                    border: none;
                    padding: 12px 24px;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 600;
                    transition: background 0.2s;
                    box-shadow: 0 4px 6px -1px rgba(124, 58, 237, 0.2);
                }

                .btn:hover {
                    background: #6d28d9;
                }

                @media print {
                    @page {
                        margin: 1cm;
                        size: auto;
                    }

                    .no-print {
                        display: none;
                    }

                    body {
                        padding: 0;
                        background: white;
                    }

                    .header {
                        margin-bottom: 20px;
                    }

                    table {
                        page-break-inside: auto;
                    }

                    tr {
                        page-break-inside: avoid;
                        page-break-after: auto;
                    }

                    thead {
                        display: table-header-group;
                    }

                    tfoot {
                        display: table-footer-group;
                    }
                }
            </style>
        </head>

        <body>
            <div class="header">
                <h1>Duty Manager Checklist</h1>
                <p>La Rose Noire Philippines • Facilities Management Department</p>
            </div>

            <div class="meta-grid">
                <div class="meta-item">
                    <span class="meta-label">Date</span>
                    <span class="meta-value"><?= $sessionDate ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Submitted At</span>
                    <span class="meta-value"><?= $submittedAt ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Submitted By</span>
                    <span class="meta-value"><?= htmlspecialchars($submittedBy) ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Assigned Duty Managers</span>
                    <span class="meta-value"><?= htmlspecialchars($assignedManagers) ?></span>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th width="4%" align="center">#</th>
                        <th width="12%">Area</th>
                        <th width="21%">Task</th>
                        <th width="8%">Shift</th>
                        <th width="5%" align="center">Coord</th>
                        <th width="12%">Dept</th>
                        <th>Remarks</th>
                        <th width="15%">Photo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($entriesData) > 0): ?>
                        <?php
                        if (!isset($pdfIndex))
                            $pdfIndex = 1;
                        foreach ($entriesData as $entry):
                            ?>
                            <tr>
                                <td align="center" style="font-weight: bold; color: #475569;"><?= $pdfIndex++ ?></td>
                                <td style="font-weight: 600; color: #475569;"><?= htmlspecialchars($entry['Area']) ?></td>
                                <td style="font-weight: 500;">
                                    <?= htmlspecialchars($entry['TaskName']) ?>
                                    <?php if (!empty($entry['Temperature'])): ?>
                                        <span
                                            style="display: inline-block; background: #ecfeff; color: #0891b2; border: 1px solid #cffafe; padding: 1px 4px; border-radius: 3px; font-size: 9px; margin-left: 4px; vertical-align: middle; font-weight: bold;">
                                            <span style="margin-right: 2px;">🌡️</span><?= htmlspecialchars($entry['Temperature']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['Shift_Selection']): ?>
                                        <span class="badge badge-<?= strtolower(str_replace(' ', '', $entry['Shift_Selection'])) ?>">
                                            <?= htmlspecialchars($entry['Shift_Selection']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td align="center"
                                    style="font-weight: bold; color: <?= ($entry['Coordinated'] ?? 0) == 1 ? '#10b981' : '#cbd5e1' ?>">
                                    <?= ($entry['Coordinated'] ?? 0) == 1 ? '✓' : '-' ?>
                                </td>
                                <td><?= htmlspecialchars($entry['Dept_In_Charge'] ?? '') ?: '<span style="color:#cbd5e1">-</span>' ?>
                                </td>
                                <td class="remarks-cell">
                                    <?php
                                    $remarks = $entry['Remarks'] ?? '';
                                    if (empty($remarks)) {
                                        echo '<span style="color:#cbd5e1">-</span>';
                                    } else {
                                        $decoded = json_decode($remarks, true);
                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                            $hasContent = false;
                                            foreach (['1st', '2nd'] as $shift) {
                                                if (!empty($decoded[$shift])) {
                                                    $hasContent = true;
                                                    echo '<div style="margin-bottom: 2px;"><span style="font-weight:bold; font-size:9px; color:#64748b; margin-right:4px;">[' . $shift . ']</span>' . htmlspecialchars($decoded[$shift]) . '</div>';
                                                }
                                            }
                                            if (!$hasContent)
                                                echo '<span style="color:#cbd5e1">-</span>';
                                        } else {
                                            echo htmlspecialchars($remarks);
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="photo-cell">
                                    <?php
                                    $itemPhotos = $photosByItem[$entry['ItemID']] ?? [];
                                    $photosToShow = array_slice($itemPhotos, 0, 2); // Limit to 2 for PDF
                    
                                    if (!empty($photosToShow)) {
                                        foreach ($photosToShow as $photo) {
                                            $imgSrc = 'actions/serve_image.php?photo_id=' . $photo['ID'];
                                            echo '<img src="' . $imgSrc . '" class="photo-thumb" style="margin: 2px;">';
                                        }
                                        if (count($itemPhotos) > 2) {
                                            echo '<div style="font-size: 9px; color: #64748b; margin-top:2px;">+' . (count($itemPhotos) - 2) . ' more</div>';
                                        }
                                    } else {
                                        echo '<span style="color: #cbd5e1; font-size: 10px;">No Photo</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" align="center" style="padding: 30px; color: #64748b;">No entries found for this session.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="no-print">
                <button class="btn" onclick="window.print()">
                    <span style="margin-right: 8px;">🖨️</span> Print / Save as PDF
                </button>
                <div style="margin-top: 10px; font-size: 11px; color: #64748b;">
                    (Checklist will automatically trigger print dialog)
                </div>
            </div>

            <script>
                // Auto-print when loaded
                window.onload = function () {
                    setTimeout(function () {
                        window.print();
                    }, 500);
                };
            </script>
        </body>


        </html>
        <?php
        exit();
    }
}

// Handle view session details
$viewSession = null;
$viewEntries = [];
if (isset($_GET['view'])) {
    $viewSessionId = (int) $_GET['view'];
    $viewSession = dbQueryOne("SELECT cs.ID, cs.SessionDate, cs.SubmittedAt, u.Name as ManagerName,
                                      (
                                          SELECT STRING_AGG(u2.Name, ' & ' ORDER BY u2.Name)
                                          FROM DM_Schedules s2
                                          JOIN DM_Users u2 ON s2.ManagerID = u2.ID
                                          WHERE s2.ScheduledDate = s.ScheduledDate
                                      ) as AllManagers
                               FROM DM_Checklist_Sessions cs
                               JOIN DM_Schedules s ON cs.ScheduleID = s.ID
                               JOIN DM_Users u ON s.ManagerID = u.ID
                               WHERE cs.ID = ?", [$viewSessionId]);

    if ($viewSession) {
        $viewEntries = dbQuery("SELECT e.*, i.Area, i.TaskName, i.AC_Status
                                FROM DM_Checklist_Entries e
                                JOIN DM_Checklist_Items i ON e.ItemID = i.ID
                                WHERE e.SessionID = ?
                                ORDER BY i.SortOrder", [$viewSessionId]);

        // Fetch photos for View Mode
        $viewPhotos = dbQuery("SELECT * FROM DM_Checklist_Photos WHERE SessionID = ?", [$viewSessionId]);
        $photosByItem = [];
        foreach ($viewPhotos as $p) {
            $photosByItem[$p['ItemID']][] = $p;
        }

        // Group entries by area for cleaner display
        $entriesByArea = [];
        $areasOrder = [];
        foreach ($viewEntries as $entry) {
            $area = $entry['Area'];
            if (!isset($entriesByArea[$area])) {
                $entriesByArea[$area] = [];
                $areasOrder[] = $area;
            }
            $entriesByArea[$area][] = $entry;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History • Duty Manager Checklist</title>
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
            <div class="flex items-center gap-4">
                <a href="index.php"
                    class="w-10 h-10 rounded-xl bg-slate-800 hover:bg-slate-700 flex items-center justify-center transition-colors group">
                    <i class="fas fa-arrow-left text-slate-400 group-hover:text-white transition-colors"></i>
                </a>
                <div>
                    <h1 class="text-lg font-bold text-white leading-none">Checklist History</h1>
                    <p class="text-xs text-slate-400 mt-1">View past submissions</p>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <?php if ($viewSession): ?>
            <!-- Detail View -->
            <div class="animate-fade-in space-y-6">
                <div class="flex items-center justify-between">
                    <a href="history.php"
                        class="inline-flex items-center text-brand-400 hover:text-brand-300 text-sm font-medium transition-colors">
                        <i class="fas fa-chevron-left mr-2"></i>Back to List
                    </a>

                    <a href="history.php?export=1&session_id=<?= $viewSession['ID'] ?>" target="_blank"
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/5 text-white text-sm font-medium transition-all flex items-center gap-2">
                        <i class="fas fa-file-pdf text-rose-400"></i>
                        Export PDF
                    </a>
                </div>

                <div class="glass-card rounded-2xl p-8">
                    <div class="mb-8">
                        <div
                            class="inline-flex items-center gap-2 text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider mb-3">
                            <i class="fas fa-check-circle"></i> Completed
                        </div>
                        <h2 class="text-3xl font-bold text-white mb-2">
                            <?= (new DateTime($viewSession['SessionDate']))->format('F j, Y') ?>
                        </h2>
                        <div class="flex items-center gap-2 text-slate-400 text-sm">
                            <i class="fas fa-user-tie text-slate-500"></i>
                            <span><?= htmlspecialchars($viewSession['AllManagers'] ?? $viewSession['ManagerName']) ?></span>
                            <span class="text-slate-600">•</span>
                            <i class="far fa-clock text-slate-500"></i>
                            <span>Submitted:
                                <?= $viewSession['SubmittedAt'] ? (new DateTime($viewSession['SubmittedAt']))->format('M j, g:i A') : 'N/A' ?></span>
                        </div>
                    </div>

                    <!-- Mobile/Tablet Portrait Card Layout -->
                    <div class="space-y-4 lg:hidden md:landscape:hidden">
                        <?php
                        if (!isset($mobileItemIndex))
                            $mobileItemIndex = 1;
                        foreach ($viewEntries as $entry):
                            $hasImage = !empty($entry['ImageData']) || !empty($entry['ImagePath']);
                            $imgSrc = '';
                            if ($hasImage) {
                                $imgSrc = !empty($entry['ImageData'])
                                    ? 'actions/serve_image.php?session=' . $viewSessionId . '&item=' . $entry['ItemID']
                                    : htmlspecialchars($entry['ImagePath']);
                            }
                            $currentNumber = $mobileItemIndex++;
                            ?>
                            <div class="glass-card rounded-xl p-5 space-y-3">
                                <!-- Area Badge -->
                                <div class="flex items-center justify-between">
                                    <span
                                        class="px-3 py-1 rounded-lg bg-slate-800 text-slate-300 text-xs border border-slate-700 font-medium">
                                        <?= htmlspecialchars($entry['Area']) ?>
                                    </span>
                                    <?php if ($entry['Shift_Selection']): ?>
                                        <span class="px-2 py-1 rounded text-xs font-medium 
                                            <?= $entry['Shift_Selection'] === '1st' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' :
                                                ($entry['Shift_Selection'] === '2nd' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' :
                                                    'bg-amber-500/10 text-amber-400 border border-amber-500/20') ?>">
                                            <?= htmlspecialchars($entry['Shift_Selection']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Task Name -->
                                <p class="text-white font-medium text-sm leading-relaxed">
                                    <span
                                        class="text-brand-400 font-bold mr-1"><?= $currentNumber ?>.</span><?= htmlspecialchars($entry['TaskName']) ?>
                                </p>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-2 gap-3 pt-3 border-t border-slate-700/50">
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-1">Coordinated</label>
                                        <p class="text-sm text-slate-300">
                                            <?= $entry['Coordinated'] ? '<i class="fas fa-check text-brand-400"></i> Yes' : '<span class="text-slate-600">No</span>' ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-1">Photo</label>
                                        <div class="flex flex-wrap gap-2">
                                            <?php
                                            $itemPhotos = $photosByItem[$entry['ItemID']] ?? [];
                                            if (!empty($itemPhotos)):
                                                foreach ($itemPhotos as $photo):
                                                    $imgSrc = 'actions/serve_image.php?photo_id=' . $photo['ID'];
                                                    ?>
                                                    <button type="button" onclick="openImageModal('<?= $imgSrc ?>')"
                                                        class="inline-flex items-center gap-1 px-2 py-1 rounded bg-indigo-500/20 text-indigo-400 hover:bg-indigo-500 hover:text-white text-xs transition-all">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <?php
                                                endforeach;
                                            else: ?>
                                                <span class="text-slate-600 text-xs">No photo</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Department -->
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Department</label>
                                    <p class="text-sm text-slate-300">
                                        <?= htmlspecialchars($entry['Dept_In_Charge'] ?? '-') ?>
                                    </p>
                                </div>

                                <!-- Remarks -->
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Remarks</label>
                                    <p class="text-sm text-slate-300">
                                        <?php
                                        $remarks = $entry['Remarks'] ?? '';
                                        if (empty($remarks)) {
                                            echo '-';
                                        } else {
                                            $decoded = json_decode($remarks, true);
                                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                $hasContent = false;
                                                foreach (['1st', '2nd'] as $shift) {
                                                    if (!empty($decoded[$shift])) {
                                                        $hasContent = true;
                                                        echo '<div class="mt-1"><span class="text-xs font-bold text-slate-500 uppercase tracking-wider mr-1">' . $shift . ':</span>' . htmlspecialchars($decoded[$shift]) . '</div>';
                                                    }
                                                }
                                                if (!$hasContent)
                                                    echo '-';
                                            } else {
                                                echo htmlspecialchars($remarks);
                                            }
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Desktop/Tablet Landscape Table Layout -->
                    <div class="hidden lg:block md:landscape:block">
                        <div class="overflow-x-auto rounded-xl border border-white/5">
                            <table class="w-full text-sm min-w-[850px]">
                                <thead
                                    class="bg-slate-900/50 text-xs uppercase tracking-wider text-slate-400 font-semibold">
                                    <tr>
                                        <th class="text-center py-3 px-2 w-[40px]">#</th>
                                        <th class="text-left py-3 px-3 w-[250px]">Task</th>
                                        <th class="text-center py-3 px-2 w-[70px]">Shift</th>
                                        <th class="text-center py-3 px-2 w-[60px]">Coord</th>
                                        <th class="text-left py-3 px-2 w-[120px]">Dept</th>
                                        <th class="text-left py-3 px-2">Remarks</th>
                                        <th class="text-center py-3 px-2 w-[70px]">Photo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php
                                    if (!isset($historyDesktopIndex))
                                        $historyDesktopIndex = 1;
                                    foreach ($areasOrder as $area):
                                        ?>
                                        <!-- Area Header Row -->
                                        <tr class="bg-brand-900/30 border-l-4 border-brand-500">
                                            <td colspan="8" class="py-3 px-4">
                                                <span
                                                    class="text-brand-400 font-bold text-sm uppercase tracking-wider"><?= htmlspecialchars($area) ?></span>
                                            </td>
                                        </tr>
                                        <?php foreach ($entriesByArea[$area] as $entry):
                                            $showTemp = stripos($entry['TaskName'], 'Chiller') !== false || stripos($entry['TaskName'], 'Freezer') !== false;
                                            ?>
                                            <tr class="hover:bg-slate-800/30 transition-colors">
                                                <td class="py-3 px-2 align-middle text-center text-slate-400 font-bold text-xs">
                                                    <?= $historyDesktopIndex++ ?>
                                                </td>
                                                <td
                                                    class="py-3 px-3 align-top text-slate-200 text-sm whitespace-normal break-words">
                                                    <div><?= htmlspecialchars($entry['TaskName']) ?></div>
                                                    <?php if ($showTemp && !empty($entry['Temperature'])): ?>
                                                        <div class="mt-1">
                                                            <span
                                                                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                                                                <i class="fas fa-temperature-half text-[9px]"></i> Temp:
                                                                <?= htmlspecialchars($entry['Temperature']) ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-2 align-middle text-center">
                                                    <?php if ($entry['Shift_Selection']): ?>
                                                        <span
                                                            class="px-2 py-1 rounded text-xs font-medium 
                                                    <?= $entry['Shift_Selection'] === '1st' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' :
                                                        ($entry['Shift_Selection'] === '2nd' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' :
                                                            'bg-amber-500/10 text-amber-400 border border-amber-500/20') ?>">
                                                            <?= htmlspecialchars($entry['Shift_Selection']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-slate-600">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-2 align-middle text-center">
                                                    <?php if ($entry['Coordinated']): ?>
                                                        <i class="fas fa-check text-brand-400"></i>
                                                    <?php else: ?>
                                                        <span class="text-slate-600">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-2 align-middle text-center text-slate-400 text-sm">
                                                    <?= htmlspecialchars($entry['Dept_In_Charge'] ?? '') ?>
                                                </td>
                                                <td
                                                    class="py-3 px-2 align-top text-slate-400 text-sm whitespace-normal break-words max-w-[250px]">
                                                    <?php
                                                    $remarks = $entry['Remarks'] ?? '';
                                                    if (empty($remarks)) {
                                                        echo '-';
                                                    } else {
                                                        $decoded = json_decode($remarks, true);
                                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                            $hasContent = false;
                                                            foreach (['1st', '2nd'] as $shift) {
                                                                if (!empty($decoded[$shift])) {
                                                                    $hasContent = true;
                                                                    echo '<div class="mb-1"><span class="text-xs font-bold uppercase tracking-wider text-slate-600 mr-1">[' . $shift . ']</span>' . htmlspecialchars($decoded[$shift]) . '</div>';
                                                                }
                                                            }
                                                            if (!$hasContent)
                                                                echo '-';
                                                        } else {
                                                            echo htmlspecialchars($remarks);
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                                <td class="py-3 px-2 align-middle text-center">
                                                    <?php
                                                    $itemPhotos = $photosByItem[$entry['ItemID']] ?? [];
                                                    $hasImage = !empty($itemPhotos) || !empty($entry['ImageData']) || !empty($entry['ImagePath']);

                                                    if ($hasImage):
                                                        ?>
                                                        <div class="flex flex-wrap justify-center gap-1 max-w-[80px]">
                                                            <?php
                                                            if (!empty($itemPhotos)):
                                                                foreach ($itemPhotos as $photo):
                                                                    $imgSrc = 'actions/serve_image.php?photo_id=' . $photo['ID'];
                                                                    ?>
                                                                    <button type="button" onclick="openImageModal('<?= $imgSrc ?>')"
                                                                        class="w-6 h-6 rounded bg-indigo-500/20 text-indigo-400 hover:bg-indigo-500 hover:text-white flex items-center justify-center transition-all border border-indigo-500/30">
                                                                        <i class="fas fa-eye text-[10px]"></i>
                                                                    </button>
                                                                    <?php
                                                                endforeach;
                                                            else: // Fallback
                                                                $imgSrc = !empty($entry['ImageData'])
                                                                    ? 'actions/serve_image.php?session=' . $viewSessionId . '&item=' . $entry['ItemID']
                                                                    : htmlspecialchars($entry['ImagePath']);
                                                                ?>
                                                                <button type="button" onclick="openImageModal('<?= $imgSrc ?>')"
                                                                    class="px-2 py-1 rounded-lg bg-indigo-500/20 text-indigo-400 hover:bg-indigo-500 hover:text-white text-xs transition-all">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-slate-600">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div><!-- end desktop table wrapper -->
                </div>
            </div>
        <?php else: ?>
            <!-- Filters -->
            <div class="glass-card rounded-2xl p-6 mb-8">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="relative">
                        <select name="manager"
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 appearance-none cursor-pointer hover:bg-slate-800 transition-colors">
                            <option value="">All Managers</option>
                            <?php foreach ($managers as $manager): ?>
                                <option value="<?= $manager['ID'] ?>" <?= $filterManager == $manager['ID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($manager['Name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i
                            class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                    </div>

                    <div class="relative">
                        <select name="month"
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 appearance-none cursor-pointer hover:bg-slate-800 transition-colors">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <i
                            class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                    </div>

                    <div class="relative">
                        <select name="year"
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 appearance-none cursor-pointer hover:bg-slate-800 transition-colors">
                            <?php for ($y = date('Y'); $y >= 2026; $y--): ?>
                                <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                        <i
                            class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 text-xs pointer-events-none"></i>
                    </div>

                    <button type="submit"
                        class="w-full bg-brand-600 hover:bg-brand-500 text-white font-medium rounded-xl px-4 py-3 transition-colors shadow-lg shadow-brand-600/20">
                        <i class="fas fa-filter mr-2"></i>Apply Filters
                    </button>
                </form>
            </div>

            <!-- List -->
            <div class="space-y-4">
                <h2 class="text-xl font-bold text-white px-1">
                    <i class="fas fa-history text-brand-400 mr-2"></i>Past Checklists
                </h2>

                <?php if ($sessions && count($sessions) > 0): ?>
                    <div class="grid gap-4">
                        <?php foreach ($sessions as $session):
                            $sessionDate = new DateTime($session['SessionDate']);
                            ?>
                            <div
                                class="glass-card p-5 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 group hover:border-brand-500/30 transition-all duration-300">
                                <div>
                                    <h3 class="text-lg font-bold text-white mb-1"><?= $sessionDate->format('F j, Y') ?></h3>

                                    <div class="space-y-1">
                                        <div class="text-sm text-slate-400">
                                            <span
                                                class="text-slate-500 text-xs uppercase tracking-wide font-semibold mr-1">Submitted
                                                By:</span>
                                            <span
                                                class="text-brand-300 font-medium"><?= htmlspecialchars($session['ManagerName']) ?></span>
                                        </div>

                                        <?php if (!empty($session['AllManagers']) && $session['AllManagers'] !== $session['ManagerName']): ?>
                                            <div class="text-sm text-slate-400">
                                                <span
                                                    class="text-slate-500 text-xs uppercase tracking-wide font-semibold mr-1">Assigned:</span>
                                                <span class="text-slate-300"><?= htmlspecialchars($session['AllManagers']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($session['SubmittedAt']): ?>
                                            <div class="text-xs text-slate-500 mt-1 flex items-center gap-2">
                                                <i class="fas fa-clock"></i>
                                                <span>Submitted
                                                    <?= (new DateTime($session['SubmittedAt']))->format('M j, g:i A') ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>


                                <div class="flex gap-3">
                                    <a href="history.php?view=<?= $session['SessionID'] ?>"
                                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-300 text-sm font-medium transition-all">
                                        View Details
                                    </a>
                                    <a href="history.php?export=1&session_id=<?= $session['SessionID'] ?>" target="_blank"
                                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/5 hover:border-white/10 text-slate-400 hover:text-white text-sm transition-all"
                                        title="Download PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="glass-card rounded-2xl p-12 text-center">
                        <div class="w-20 h-20 bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-inbox text-4xl text-slate-600"></i>
                        </div>
                        <h3 class="text-xl font-medium text-white mb-2">No records found</h3>
                        <p class="text-slate-400">There are no completed checklists matching your filters.</p>
                    </div>
                <?php endif; ?>
            </div>
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

    <!-- Image Modal -->
    <div id="image-modal" class="fixed inset-0 z-[100] items-center justify-center bg-black/95 backdrop-blur-sm hidden"
        onclick="closeImageModal()">
        <div class="relative max-w-5xl w-full p-4 flex flex-col items-center">
            <button onclick="closeImageModal()"
                class="absolute top-4 right-4 w-10 h-10 bg-white/10 hover:bg-white/20 rounded-full flex items-center justify-center text-white transition-all">
                <i class="fas fa-times"></i>
            </button>
            <img id="modal-image" src="" class="max-w-full max-h-[85vh] rounded-xl shadow-2xl border border-white/10"
                onclick="event.stopPropagation()">
        </div>
    </div>

    <script>
        function openImageModal(path) {
            const modal = document.getElementById('image-modal');
            const img = document.getElementById('modal-image');
            img.src = path;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeImageModal() {
            const modal = document.getElementById('image-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Close modal on ESC key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });
    </script>
</body>

</html>
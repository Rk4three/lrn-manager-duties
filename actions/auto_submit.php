<?php
/**
 * Auto-Submit Expired Checklists
 * 
 * This script automatically submits checklists that have passed their deadline.
 * For example, a checklist for January 27 will be auto-submitted when the date 
 * becomes January 28.
 * 
 * Logic:
 * - Find all schedules where ScheduledDate < Today
 * - For each schedule, check if there's a session
 * - If session exists and Status = 'In Progress', mark as 'Completed' (auto-submitted)
 * - If no session exists, create one and mark as 'Completed' with empty/partial data
 */

require_once __DIR__ . '/../config/db.php';

function autoSubmitExpiredChecklists()
{
    // Get current Philippines date
    $phTime = getPhilippinesTime();
    $today = $phTime->format('Y-m-d');

    // Find all schedules that are past due (before today) and have sessions that are NOT completed
    // This covers both:
    // 1. Sessions that exist but are 'In Progress'
    // 2. Schedules with no session at all

    // First, handle existing sessions that are still 'In Progress'
    $expiredSessionsQuery = "
        SELECT cs.ID as SessionID, cs.ScheduleID, cs.SessionDate
        FROM DM_Checklist_Sessions cs
        WHERE cs.Status = 'In Progress'
          AND cs.SessionDate < ?
    ";

    $expiredSessions = dbQuery($expiredSessionsQuery, [$today]);

    $autoSubmittedCount = 0;

    if ($expiredSessions) {
        foreach ($expiredSessions as $session) {
            // Auto-submit this session
            $result = dbExecute(
                "UPDATE DM_Checklist_Sessions 
                 SET Status = 'Completed', 
                     SubmittedAt = CURRENT_TIMESTAMP 
                 WHERE ID = ?",
                [$session['SessionID']]
            );

            if ($result) {
                $autoSubmittedCount++;

            }
        }
    }

    // Second, handle schedules that have no session at all (managers never started the checklist)
    $noSessionQuery = "
        SELECT s.ID as ScheduleID, s.ScheduledDate, s.ManagerID
        FROM DM_Schedules s
        WHERE s.ScheduledDate < ?
          AND NOT EXISTS (
              SELECT 1 FROM DM_Checklist_Sessions cs 
              WHERE cs.ScheduleID = s.ID 
                AND cs.SessionDate = s.ScheduledDate
          )
    ";

    $schedulesWithoutSession = dbQuery($noSessionQuery, [$today]);

    if ($schedulesWithoutSession) {
        // Group by ScheduledDate to avoid duplicate sessions for same date with multiple managers
        $processedDates = [];

        foreach ($schedulesWithoutSession as $schedule) {
            $scheduleId = $schedule['ScheduleID'];
            $scheduledDate = $schedule['ScheduledDate'];

            // Check if ANY session exists for this DATE across ANY schedule
            // This prevents duplicate sessions when multiple managers are on the same day
            $anySessionForDate = dbQueryOne(
                "SELECT ID FROM DM_Checklist_Sessions WHERE SessionDate = ?",
                [$scheduledDate]
            );

            if ($anySessionForDate) {
                // A session already exists for this date (likely under a colleague's schedule)
                // Skip causing a duplicate
                continue;
            }

            // Only create one session per schedule (not per manager, since sessions are shared)
            // Check if we already created a session for this specific schedule ID
            $existingCheck = dbQueryOne(
                "SELECT ID FROM DM_Checklist_Sessions WHERE ScheduleID = ? AND SessionDate = ?",
                [$scheduleId, $scheduledDate]
            );

            if ($existingCheck) {
                // Session already exists
                continue;
            }

            // Create a completed session for this schedule
            $createResult = dbExecute(
                "INSERT INTO DM_Checklist_Sessions (ScheduleID, SessionDate, Status, SubmittedAt) 
                 VALUES (?, ?, 'Completed', CURRENT_TIMESTAMP)",
                [$scheduleId, $scheduledDate]
            );

            if ($createResult) {
                $autoSubmittedCount++;
            }
        }
    }

    return $autoSubmittedCount;
}

// If this file is called directly (e.g., via cron job), run the function
if (
    php_sapi_name() === 'cli' ||
    (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__))
) {
    $count = autoSubmitExpiredChecklists();
    if (php_sapi_name() === 'cli') {
        echo "Auto-submitted $count expired checklists.\n";
    }
}

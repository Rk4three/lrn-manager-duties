-- Seed Data

-- 1. Users
INSERT INTO "DM_Users" ("Name", "Username", "Password", "Department", "IsSuperAdmin") VALUES
('Super Admin', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administration', TRUE), -- password: password
('John Doe', 'jdoe', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Production', FALSE),
('Jane Smith', 'jsmith', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Logistics', FALSE);

-- 2. Checklist Items (Sample)
INSERT INTO "DM_Checklist_Items" ("Area", "TaskName", "SortOrder", "RequiresTemperature") VALUES
('Entrance / Lobby', 'Check cleanliness of main entrance glass doors', 1, FALSE),
('Entrance / Lobby', 'Ensure security guard is present and in uniform', 2, FALSE),
('Production Area', 'Check room temperature (Target: 20-24°C)', 3, TRUE),
('Production Area', 'Verify staff are wearing proper PPE', 4, FALSE),
('Warehouse', 'Check for any obstructions in aisles', 5, FALSE),
('Perimeter', 'Inspect perimeter fence for damages', 6, FALSE);

-- 3. Schedules (For the current month - dynamic dates would be better but static for seed is fine)
-- Assuming we run this in 2024/2025, let's just add some for "CURRENT_DATE"
INSERT INTO "DM_Schedules" ("ManagerID", "ScheduledDate", "Timeline") VALUES
(2, CURRENT_DATE, '08:00 AM - 05:00 PM'), -- John Doe today
(3, CURRENT_DATE + INTERVAL '1 day', '08:00 AM - 05:00 PM'), -- Jane Smith tomorrow
(2, CURRENT_DATE + INTERVAL '2 day', '08:00 AM - 05:00 PM');

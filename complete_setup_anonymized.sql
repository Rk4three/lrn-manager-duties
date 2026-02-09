-- ============================================
-- COMPLETE SETUP SCRIPT (ANONYMIZED)
-- 1. Creates Mock External Tables (lrn_master_list, lrnph_users)
-- 2. Creates Core App Tables (DM_Users, DM_Schedules, etc.)
-- 3. Inserts Consistent Dummy Data (26 Users)
-- ============================================

-- ============================================
-- PART 1: MOCK EXTERNAL TABLES & DATA
-- ============================================

IF OBJECT_ID('lrn_master_list', 'U') IS NOT NULL DROP TABLE lrn_master_list;
CREATE TABLE lrn_master_list (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    EmployeeID NVARCHAR(20),
    BiometricsID NVARCHAR(20),
    FirstName NVARCHAR(50),
    LastName NVARCHAR(50),
    FullName NVARCHAR(150),
    Department NVARCHAR(100),
    PositionTitle NVARCHAR(100),
    IsActive BIT DEFAULT 1
);

IF OBJECT_ID('lrnph_users', 'U') IS NOT NULL DROP TABLE lrnph_users;
CREATE TABLE lrnph_users (
    id INT IDENTITY(1,1) PRIMARY KEY,
    username NVARCHAR(50),
    password NVARCHAR(255),
    role NVARCHAR(50),
    empcode NVARCHAR(20),
    is_active BIT DEFAULT 1
);

-- Common Variables
DECLARE @PasswordHash NVARCHAR(255) = '$2y$10$GKQJ9S.wUcnEvqS9GUDG8.xXsCngiR7IuYnpqp.qU0g2ghlXzN8mu'; -- 'password123'

-- INSERT FAKE USERS (26 Total)
-- 1. John Doe (Super Admin)
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP001', '1001', 'John', 'Doe', 'John Doe', 'Administration', 'Super Admin');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1001', @PasswordHash, 'Super Admin', 'EMP001');

-- 2. Jane Smith
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP002', '1002', 'Jane', 'Smith', 'Jane Smith', 'Production', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1002', @PasswordHash, 'Manager', 'EMP002');

-- 3. Robert Brown
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP003', '1003', 'Robert', 'Brown', 'Robert Brown', 'QA', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1003', @PasswordHash, 'Manager', 'EMP003');

-- 4. Emily White
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP004', '1004', 'Emily', 'White', 'Emily White', 'HR', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1004', @PasswordHash, 'Manager', 'EMP004');

-- 5. Michael Green
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP005', '1005', 'Michael', 'Green', 'Michael Green', 'Engineering', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1005', @PasswordHash, 'Manager', 'EMP005');

-- 6. David Wilson
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP006', '1006', 'David', 'Wilson', 'David Wilson', 'Finance', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1006', @PasswordHash, 'Manager', 'EMP006');

-- 7. Sarah Johnson
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP007', '1007', 'Sarah', 'Johnson', 'Sarah Johnson', 'Logistics', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1007', @PasswordHash, 'Manager', 'EMP007');

-- 8. James Jones
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP008', '1008', 'James', 'Jones', 'James Jones', 'Sales', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1008', @PasswordHash, 'Manager', 'EMP008');

-- 9. Maria Garcia
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP009', '1009', 'Maria', 'Garcia', 'Maria Garcia', 'IT', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1009', @PasswordHash, 'Manager', 'EMP009');

-- 10. Patricia Martinez
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP010', '1010', 'Patricia', 'Martinez', 'Patricia Martinez', 'Purchasing', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1010', @PasswordHash, 'Manager', 'EMP010');

-- 11. Jennifer Davis
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP011', '1011', 'Jennifer', 'Davis', 'Jennifer Davis', 'Marketing', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1011', @PasswordHash, 'Manager', 'EMP011');

-- 12. Charles Rodriguez
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP012', '1012', 'Charles', 'Rodriguez', 'Charles Rodriguez', 'Production', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1012', @PasswordHash, 'Manager', 'EMP012');

-- 13. Linda Martinez
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP013', '1013', 'Linda', 'Martinez', 'Linda Martinez', 'Facilities', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1013', @PasswordHash, 'Manager', 'EMP013');

-- 14. Elizabeth Hernandez
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP014', '1014', 'Elizabeth', 'Hernandez', 'Elizabeth Hernandez', 'HR', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1014', @PasswordHash, 'Manager', 'EMP014');

-- 15. Barbara Lopez
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP015', '1015', 'Barbara', 'Lopez', 'Barbara Lopez', 'Finance', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1015', @PasswordHash, 'Manager', 'EMP015');

-- 16. Susan Gonzalez
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP016', '1016', 'Susan', 'Gonzalez', 'Susan Gonzalez', 'Logistics', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1016', @PasswordHash, 'Manager', 'EMP016');

-- 17. Joseph Wilson
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP017', '1017', 'Joseph', 'Wilson', 'Joseph Wilson', 'Production', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1017', @PasswordHash, 'Manager', 'EMP017');

-- 18. Thomas Anderson
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP018', '1018', 'Thomas', 'Anderson', 'Thomas Anderson', 'Engineering', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1018', @PasswordHash, 'Manager', 'EMP018');

-- 19. Jessica Thomas
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP019', '1019', 'Jessica', 'Thomas', 'Jessica Thomas', 'IT', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1019', @PasswordHash, 'Manager', 'EMP019');

-- 20. Christopher Taylor
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP020', '1020', 'Christopher', 'Taylor', 'Christopher Taylor', 'Marketing', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1020', @PasswordHash, 'Manager', 'EMP020');

-- 21. Daniel Moore
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP021', '1021', 'Daniel', 'Moore', 'Daniel Moore', 'Facilities', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1021', @PasswordHash, 'Manager', 'EMP021');

-- 22. Paul Jackson
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP022', '1022', 'Paul', 'Jackson', 'Paul Jackson', 'Production', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1022', @PasswordHash, 'Manager', 'EMP022');

-- 23. Mark Martin
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP023', '1023', 'Mark', 'Martin', 'Mark Martin', 'QA', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1023', @PasswordHash, 'Manager', 'EMP023');

-- 24. Donald Thompson
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP024', '1024', 'Donald', 'Thompson', 'Donald Thompson', 'Safety', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1024', @PasswordHash, 'Manager', 'EMP024');

-- 25. George White
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP025', '1025', 'George', 'White', 'George White', 'Security', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1025', @PasswordHash, 'Manager', 'EMP025');

-- 26. Kenneth Harris
INSERT INTO lrn_master_list (EmployeeID, BiometricsID, FirstName, LastName, FullName, Department, PositionTitle) VALUES ('EMP026', '1026', 'Kenneth', 'Harris', 'Kenneth Harris', 'HR', 'Manager');
INSERT INTO lrnph_users (username, password, role, empcode) VALUES ('1026', @PasswordHash, 'Manager', 'EMP026');


-- ============================================
-- PART 2: CORE APPLICATION SCHEMA & DATA
-- ============================================

-- DM_Users
IF OBJECT_ID('DM_Users', 'U') IS NOT NULL DROP TABLE DM_Users;
CREATE TABLE DM_Users (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    Name NVARCHAR(100) NOT NULL,
    Role NVARCHAR(50) DEFAULT 'Manager',
    IsSuperAdmin BIT DEFAULT 0,
    IsActive BIT DEFAULT 1,
    CreatedAt DATETIME DEFAULT GETDATE()
);
CREATE UNIQUE INDEX IX_DM_Users_Name ON DM_Users(Name);

-- DM_Schedules
IF OBJECT_ID('DM_Schedules', 'U') IS NOT NULL DROP TABLE DM_Schedules;
CREATE TABLE DM_Schedules (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    ManagerID INT NOT NULL,
    ScheduledDate DATE NOT NULL,
    CreatedAt DATETIME DEFAULT GETDATE(),
    CreatedBy NVARCHAR(100),
    CONSTRAINT FK_DM_Schedules_Manager FOREIGN KEY (ManagerID) REFERENCES DM_Users(ID)
);
CREATE INDEX IX_DM_Schedules_Date ON DM_Schedules(ScheduledDate);
CREATE INDEX IX_DM_Schedules_Manager ON DM_Schedules(ManagerID);

-- DM_Checklist_Items
IF OBJECT_ID('DM_Checklist_Items', 'U') IS NOT NULL DROP TABLE DM_Checklist_Items;
CREATE TABLE DM_Checklist_Items (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    Area NVARCHAR(100) NOT NULL,
    TaskName NVARCHAR(500) NOT NULL,
    Description NVARCHAR(1000),
    SortOrder INT DEFAULT 0,
    IsActive BIT DEFAULT 1
);

-- Checklist Items Data
INSERT INTO DM_Checklist_Items (Area, TaskName, SortOrder) VALUES 
('General Office', 'No personnel loitering in the office', 1),
('General Office', 'Lights & ACU are off except for those required', 2),
('General Office', 'All desks are neat and tidy', 3),
('Outside Building', 'Clinic is for medical issues (no loitering)', 4),
('Outside Building', 'Main gate to be manned at all times', 5),
('Outside Building', 'Canteen hygiene standards followed', 6),
('Outside Building', 'Pamisanmetung Hall - clean & tidy', 7),
('Outside Building', 'Scrap Area - Collected scraps are properly stored (if bin not yet full)', 8),
('Outside Building', 'Changing/Toilet Room (Female) - clean and tidy', 9),
('Outside Building', 'Changing/Toilet Room (male) - clean and tidy', 10),
('Outside Building', 'Locker Room (Female) - clean & tidy', 11),
('Outside Building', 'Locker Room (male) - clean & tidy', 12),
('Prodn P1 Ingredient', 'All areas are cleaned, no garbages left and materials out of place', 13),
('Prodn P1 Dough Room', 'All areas are cleaned, no garbages left and materials out of place', 14),
('Prodn P1 Dough Room', 'Machines are turned off, clean, no dust and dirt', 15),
('Prodn P1 Moulding Area', 'All areas are cleaned, no garbages left and materials out of place', 16),
('Prodn P3 Chiller Packaging', 'All areas are cleaned, no garbages left and materials out of place', 17),
('Prodn P3 Chiller Packaging', 'Machines are turned off, clean, no dust and dirt', 18),
('Prodn P3 Kitchen', 'All areas are cleaned, no garbages left and materials out of place', 19),
('Prodn P3 Kitchen', 'Machines are turned off, clean, no dust and dirt', 20),
('Prodn P3 Pasteurizer', 'All areas are cleaned, no garbages left and materials out of place', 21),
('Prodn P3 Pasteurizer', 'Machines are turned off, clean, no dust and dirt', 22),
('Prodn P1 Utensils Room', 'Machines are turned off, clean, no dust and dirt', 23),
('Prodn P3 Assembly', 'All areas are cleaned, no garbages left and materials out of place', 24),
('Prodn P3 Assembly', 'Machines are turned off, clean, no dust and dirt', 25),
('Prodn P1 Oven Area', 'All areas are cleaned, no garbages left and materials out of place', 26),
('Prodn P1 Oven Area', 'Machines are turned off, clean, no dust and dirt', 27),
('Prodn P3 Cheesecake', 'All areas are cleaned, no garbages left and materials out of place', 28),
('Prodn P3 Cheesecake', 'Machines are turned off, clean, no dust and dirt', 29),
('Prodn P1 Coating Area', 'All areas are cleaned, no garbages left and materials out of place', 30),
('Prodn P1 Coating Area', 'Machines are turned off, clean, no dust and dirt', 31),
('Prodn P1 Outer Packing', 'All areas are cleaned, no garbages left and materials out of place', 32),
('Prodn P1 Outer Packing', 'Machines are turned off, clean, no dust and dirt', 33),
('P1 FGW Chiller', 'All areas are cleaned, no garbages left and materials out of place', 34),
('P1 FGW Old Freezer', 'All areas are cleaned, no garbages left and materials out of place', 35),
('P1 FGW Extension Freezer', 'All areas are cleaned, no garbages left and materials out of place', 36),
('P1 FGW Loading Area', 'All areas are cleaned, no garbages left and materials out of place', 37),
('P1 FGW AC Room', 'All areas are cleaned, no garbages left and materials out of place', 38),
('P2 Employee entrance', 'All areas are cleaned, no garbages left and materials out of place', 39),
('Prodn P2 Ingredient Room', 'All areas are cleaned, no garbages left and materials out of place', 40),
('Prodn P2 Ingredient Room', 'Machines are turned off, clean, no dust and dirt', 41),
('Prodn P2 Mixing Room', 'All areas are cleaned, no garbages left and materials out of place', 42),
('Prodn P2 Mixing Room', 'Machines are turned off, clean, no dust and dirt', 43),
('Prodn P2 Danish Room', 'All areas are cleaned, no garbages left and materials out of place', 44),
('Prodn P2 Danish Room', 'Machines are turned off, clean, no dust and dirt', 45),
('Prodn P2 Chiller', 'All areas are cleaned, no garbages left and materials out of place', 46),
('Prodn P2 Chiller', 'Machines are turned off, clean, no dust and dirt', 47),
('Prodn P2 Freezer', 'Lights are off except for those required', 48),
('Prodn P2 Freezer', 'Machines are turned off, clean, no dust and dirt', 49),
('Prodn P2 Utensils Room', 'Lights are off except for those required', 50),
('Prodn P2 Utensils Room', 'Machines are turned off, clean, no dust and dirt', 51),
('Prodn P2 Proofer Room', 'Machines are turned off, clean, no dust and dirt', 52),
('Prodn P2 Oven Room', 'Lights are off except for those required', 53),
('Prodn P2 Oven Room', 'Machines are turned off, clean, no dust and dirt', 54),
('Prodn P2 Blast Freezer/Holding room', 'All areas are cleaned, no garbages left and materials out of place', 55),
('Prodn P2 Inner Packing', 'All areas are cleaned, no garbages left and materials out of place', 56),
('Prodn P2 Outer Packing', 'All areas are cleaned, no garbages left and materials out of place', 57),
('Prodn P2 Outer Packing', 'Machines are turned off, clean, no dust and dirt', 58),
('P2 FGW Chiller', 'All areas are cleaned, no garbages left and materials out of place', 59),
('P2 FGW Freezer', 'All areas are cleaned, no garbages left and materials out of place', 60),
('P2 FGW Loading Bay', 'All areas are cleaned, no garbages left and materials out of place', 61),
('P3 Gluten Ingredient', 'All areas are cleaned, no garbages left and materials out of place', 62),
('P3 Gluten Ingredient', 'Machines are turned off, clean, no dust and dirt', 63),
('P3 Gluten Mixing Room', 'All areas are cleaned, no garbages left and materials out of place', 64),
('P3 Gluten Mixing Room', 'Machines are turned off, clean, no dust and dirt', 65),
('P3 Gluten Chiller Room 1', 'All areas are cleaned, no garbages left and materials out of place', 66),
('P3 Gluten Chiller Room 2', 'All areas are cleaned, no garbages left and materials out of place', 67),
('P3 Gluten Freezer Room', 'All areas are cleaned, no garbages left and materials out of place', 68),
('P3 Gluten Assembly', 'All areas are cleaned, no garbages left and materials out of place', 69),
('P4 Chocolate Assembly', 'All areas are cleaned, no garbages left and materials out of place', 70),
('P4 Chocolate Assembly', 'Machines are turned off, clean, no dust and dirt', 71),
('P4 Chocolate Packing', 'All areas are cleaned, no garbages left and materials out of place', 72),
('P4 Chocolate Packing', 'Machines are turned off, clean, no dust and dirt', 73),
('P4 Chocolate Storage', 'All areas are cleaned, no garbages left and materials out of place', 74),
('P1 to P2 backhallway', 'All areas are cleaned, no garbages left and materials out of place', 75),
('MWH A', 'All areas are cleaned, no garbages left and materials out of place', 76),
('MWH B', 'All areas are cleaned, no garbages left and materials out of place', 77),
('Garbage Area', 'All areas cleaned', 78),
('Parking Area', 'All areas cleaned', 79);


-- DM_Checklist_Sessions
IF OBJECT_ID('DM_Checklist_Sessions', 'U') IS NOT NULL DROP TABLE DM_Checklist_Sessions;
CREATE TABLE DM_Checklist_Sessions (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    ScheduleID INT NOT NULL,
    SessionDate DATE NOT NULL,
    Status NVARCHAR(20) DEFAULT 'In Progress',
    SubmittedAt DATETIME,
    CreatedAt DATETIME DEFAULT GETDATE(),
    CONSTRAINT FK_DM_Sessions_Schedule FOREIGN KEY (ScheduleID) REFERENCES DM_Schedules(ID)
);
CREATE INDEX IX_DM_Sessions_Date ON DM_Checklist_Sessions(SessionDate);

-- DM_Checklist_Entries
IF OBJECT_ID('DM_Checklist_Entries', 'U') IS NOT NULL DROP TABLE DM_Checklist_Entries;
CREATE TABLE DM_Checklist_Entries (
    ID INT IDENTITY(1,1) PRIMARY KEY,
    SessionID INT NOT NULL,
    ItemID INT NOT NULL,
    Shift_Selection NVARCHAR(10),
    Coordinated BIT DEFAULT 0,
    Dept_In_Charge NVARCHAR(200),
    Remarks NVARCHAR(1000),
    ImagePath NVARCHAR(500),
    UpdatedAt DATETIME DEFAULT GETDATE(),
    CONSTRAINT FK_DM_Entries_Session FOREIGN KEY (SessionID) REFERENCES DM_Checklist_Sessions(ID),
    CONSTRAINT FK_DM_Entries_Item FOREIGN KEY (ItemID) REFERENCES DM_Checklist_Items(ID)
);
CREATE INDEX IX_DM_Entries_Session ON DM_Checklist_Entries(SessionID);


-- INSERT FAKE DM_USERS
-- Must match 'lrn_master_list' data created above
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('John Doe', 'Super Admin', 1);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Jane Smith', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Robert Brown', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Emily White', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Michael Green', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('David Wilson', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Sarah Johnson', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('James Jones', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Maria Garcia', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Patricia Martinez', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Jennifer Davis', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Charles Rodriguez', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Linda Martinez', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Elizabeth Hernandez', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Barbara Lopez', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Susan Gonzalez', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Joseph Wilson', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Thomas Anderson', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Jessica Thomas', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Christopher Taylor', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Daniel Moore', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Paul Jackson', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Mark Martin', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Donald Thompson', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('George White', 'Manager', 0);
INSERT INTO DM_Users (Name, Role, IsSuperAdmin) VALUES ('Kenneth Harris', 'Manager', 0);

-- INSERT SCHEDULES (January - April 2026)
-- Uses Fake Names
-- ============================================

-- Jan 11 (Mark Martin, George White)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-01-11', 'System Import' FROM DM_Users WHERE Name = 'Mark Martin';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-01-11', 'System Import' FROM DM_Users WHERE Name = 'George White';

-- Jan 18 (Jennifer Davis, Paul Jackson)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-01-18', 'System Import' FROM DM_Users WHERE Name = 'Jennifer Davis';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-01-18', 'System Import' FROM DM_Users WHERE Name = 'Paul Jackson';

-- Jan 25 (Elizabeth Hernandez, Barbara Lopez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-01-25', 'System Import' FROM DM_Users WHERE Name = 'Elizabeth Hernandez';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-01-25', 'System Import' FROM DM_Users WHERE Name = 'Barbara Lopez';

-- Feb 1 (James Jones, Charles Rodriguez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-01', 'System Import' FROM DM_Users WHERE Name = 'James Jones';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-01', 'System Import' FROM DM_Users WHERE Name = 'Charles Rodriguez';

-- Feb 8 (Sarah Johnson, Patricia Martinez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-08', 'System Import' FROM DM_Users WHERE Name = 'Sarah Johnson';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-08', 'System Import' FROM DM_Users WHERE Name = 'Patricia Martinez';

-- Feb 15 (Paul Jackson, Linda Martinez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-15', 'System Import' FROM DM_Users WHERE Name = 'Paul Jackson';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-15', 'System Import' FROM DM_Users WHERE Name = 'Linda Martinez';

-- Feb 22 (James Jones, Susan Gonzalez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-22', 'System Import' FROM DM_Users WHERE Name = 'James Jones';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-02-22', 'System Import' FROM DM_Users WHERE Name = 'Susan Gonzalez';

-- Mar 1 (Mark Martin, Maria Garcia)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-01', 'System Import' FROM DM_Users WHERE Name = 'Mark Martin';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-01', 'System Import' FROM DM_Users WHERE Name = 'Maria Garcia';

-- Mar 8 (Patricia Martinez, Charles Rodriguez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-08', 'System Import' FROM DM_Users WHERE Name = 'Patricia Martinez';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-08', 'System Import' FROM DM_Users WHERE Name = 'Charles Rodriguez';

-- Mar 15 (James Jones, Joseph Wilson)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-15', 'System Import' FROM DM_Users WHERE Name = 'James Jones';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-15', 'System Import' FROM DM_Users WHERE Name = 'Joseph Wilson';

-- Mar 22 (Joseph Wilson, Susan Gonzalez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-22', 'System Import' FROM DM_Users WHERE Name = 'Joseph Wilson';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-22', 'System Import' FROM DM_Users WHERE Name = 'Susan Gonzalez';

-- Mar 29 (Thomas Anderson, Daniel Moore)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-29', 'System Import' FROM DM_Users WHERE Name = 'Thomas Anderson';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-03-29', 'System Import' FROM DM_Users WHERE Name = 'Daniel Moore';

-- Apr 5 (Thomas Anderson, Barbara Lopez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-05', 'System Import' FROM DM_Users WHERE Name = 'Thomas Anderson';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-05', 'System Import' FROM DM_Users WHERE Name = 'Barbara Lopez';

-- Apr 12 (Jane Smith, Charles Rodriguez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-12', 'System Import' FROM DM_Users WHERE Name = 'Jane Smith';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-12', 'System Import' FROM DM_Users WHERE Name = 'Charles Rodriguez';

-- Apr 19 (Charles Rodriguez, Linda Martinez)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-19', 'System Import' FROM DM_Users WHERE Name = 'Charles Rodriguez';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-19', 'System Import' FROM DM_Users WHERE Name = 'Linda Martinez';

-- Apr 26 (Linda Martinez, Donald Thompson)
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-26', 'System Import' FROM DM_Users WHERE Name = 'Linda Martinez';
INSERT INTO DM_Schedules (ManagerID, ScheduledDate, CreatedBy) SELECT ID, '2026-04-26', 'System Import' FROM DM_Users WHERE Name = 'Donald Thompson';

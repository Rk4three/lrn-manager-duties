-- PostgreSQL Schema for Duty Manager Checklist

-- Users Table
CREATE TABLE IF NOT EXISTS "DM_Users" (
    "ID" SERIAL PRIMARY KEY,
    "Name" VARCHAR(255) NOT NULL,
    "Username" VARCHAR(50) UNIQUE,
    "Password" VARCHAR(255),
    "EmployeeID" VARCHAR(50),
    "Department" VARCHAR(100),
    "PhotoURL" VARCHAR(255),
    "Role" VARCHAR(50) DEFAULT 'Manager',
    "IsActive" BOOLEAN DEFAULT TRUE,
    "IsSuperAdmin" BOOLEAN DEFAULT FALSE,
    "CreatedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Schedules Table
CREATE TABLE IF NOT EXISTS "DM_Schedules" (
    "ID" SERIAL PRIMARY KEY,
    "ManagerID" INTEGER REFERENCES "DM_Users"("ID"),
    "ScheduledDate" DATE NOT NULL,
    "Timeline" VARCHAR(100), -- e.g. "08:00 AM - 05:00 PM"
    "CreatedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Checklist Items (The questions)
CREATE TABLE IF NOT EXISTS "DM_Checklist_Items" (
    "ID" SERIAL PRIMARY KEY,
    "Area" VARCHAR(100), -- e.g. "Kitchen", "Security"
    "TaskName" TEXT NOT NULL,
    "SortOrder" INTEGER DEFAULT 0,
    "AC_Status" VARCHAR(20), -- 'Yes'/'No' for AC Check requirements
    "RequiresTemperature" BOOLEAN DEFAULT FALSE,
    "IsActive" BOOLEAN DEFAULT TRUE
);

-- Checklist Sessions (A specific instance of a checklist for a schedule)
CREATE TABLE IF NOT EXISTS "DM_Checklist_Sessions" (
    "ID" SERIAL PRIMARY KEY,
    "ScheduleID" INTEGER REFERENCES "DM_Schedules"("ID"),
    "SessionDate" DATE,
    "Status" VARCHAR(50) DEFAULT 'In Progress', -- 'In Progress', 'Completed'
    "SubmittedAt" TIMESTAMP,
    "CreatedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Checklist Entries (Answers to items)
CREATE TABLE IF NOT EXISTS "DM_Checklist_Entries" (
    "ID" SERIAL PRIMARY KEY,
    "SessionID" INTEGER REFERENCES "DM_Checklist_Sessions"("ID") ON DELETE CASCADE,
    "ItemID" INTEGER REFERENCES "DM_Checklist_Items"("ID"),
    "Shift_Selection" VARCHAR(50), -- '1st', '2nd', '3rd'
    "Coordinated" BOOLEAN DEFAULT FALSE,
    "Dept_In_Charge" VARCHAR(100),
    "Remarks" TEXT,
    "Temperature" VARCHAR(50),
    "UpdatedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Checklist Photos
CREATE TABLE IF NOT EXISTS "DM_Checklist_Photos" (
    "ID" SERIAL PRIMARY KEY,
    "SessionID" INTEGER REFERENCES "DM_Checklist_Sessions"("ID") ON DELETE CASCADE,
    "ItemID" INTEGER REFERENCES "DM_Checklist_Items"("ID"),
    "FilePath" VARCHAR(500),
    "MimeType" VARCHAR(100),
    "UploadedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Manager Calendar (Work schedules and leave entries)
CREATE TABLE IF NOT EXISTS "Manager_Calendar" (
    "ID" SERIAL PRIMARY KEY,
    "ManagerID" INTEGER REFERENCES "DM_Users"("ID"),
    "ManagerName" VARCHAR(255),
    "EmployeeID" VARCHAR(50),
    "Department" VARCHAR(100),
    "EntryDate" DATE NOT NULL,
    "EntryType" VARCHAR(20) NOT NULL DEFAULT 'WORK', -- 'WORK' or 'LEAVE'
    "StartTime" VARCHAR(20),
    "EndTime" VARCHAR(20),
    "LeaveNote" TEXT,
    "CreatedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "UpdatedAt" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

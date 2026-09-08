USE RecruitmentManagementSystem;
GO

IF OBJECT_ID(N'dbo.Department', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Department (
        department_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        department_name NVARCHAR(150) NOT NULL UNIQUE,
        description NVARCHAR(MAX) NULL
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Department)
BEGIN
    INSERT INTO dbo.Department (department_name, description)
    VALUES
        (N'Human Resources', N'Human resources and administration'),
        (N'Operations', N'Terminal and distribution operations'),
        (N'Engineering', N'Engineering and maintenance'),
        (N'Finance', N'Finance and accounting'),
        (N'Information Technology', N'Information systems and technology');
END;
GO

IF COL_LENGTH(N'dbo.users', N'department_id') IS NULL
    ALTER TABLE dbo.users ADD department_id BIGINT NULL;
GO

IF COL_LENGTH(N'dbo.vacancies', N'vacancy_grade') IS NULL
    ALTER TABLE dbo.vacancies ADD vacancy_grade NVARCHAR(1) NOT NULL
        CONSTRAINT DF_vacancies_vacancy_grade DEFAULT N'C';
GO

IF COL_LENGTH(N'dbo.vacancies', N'audience') IS NULL
    ALTER TABLE dbo.vacancies ADD audience NVARCHAR(20) NOT NULL
        CONSTRAINT DF_vacancies_audience DEFAULT N'External';
GO

IF COL_LENGTH(N'dbo.vacancies', N'hr_approved_at') IS NULL
    ALTER TABLE dbo.vacancies ADD hr_approved_at DATETIME2 NULL;
GO

-- Assign every Head of Department user to the correct department after
-- replacing the example PIN and department name below.
-- UPDATE dbo.users
-- SET department_id = (
--     SELECT department_id FROM dbo.Department WHERE department_name = N'Operations'
-- )
-- WHERE employee_pin = N'HOD_EMPLOYEE_PIN';
-- GO

SELECT department_id, department_name FROM dbo.Department ORDER BY department_name;
GO

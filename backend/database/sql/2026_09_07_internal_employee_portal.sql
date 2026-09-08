USE RecruitmentManagementSystem;
GO

IF COL_LENGTH(N'dbo.users', N'phone') IS NULL
    ALTER TABLE dbo.users ADD phone NVARCHAR(30) NULL;
GO

IF OBJECT_ID(N'dbo.internal_otp_challenges', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.internal_otp_challenges (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        user_id BIGINT NOT NULL,
        code_hash NVARCHAR(255) NOT NULL,
        expires_at DATETIME2 NOT NULL,
        attempts TINYINT NOT NULL CONSTRAINT DF_internal_otp_attempts DEFAULT 0,
        verified_at DATETIME2 NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL
    );
    CREATE INDEX IX_internal_otp_user_expiry ON dbo.internal_otp_challenges(user_id, expires_at);
END;
GO

IF OBJECT_ID(N'dbo.internal_access_tokens', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.internal_access_tokens (
        id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        user_id BIGINT NOT NULL,
        token_hash NVARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME2 NOT NULL,
        last_used_at DATETIME2 NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL
    );
    CREATE INDEX IX_internal_token_user_expiry ON dbo.internal_access_tokens(user_id, expires_at);
END;
GO

IF COL_LENGTH(N'dbo.applications', N'applicant_type') IS NULL
    ALTER TABLE dbo.applications ADD applicant_type NVARCHAR(20) NOT NULL
        CONSTRAINT DF_applications_applicant_type DEFAULT N'External';
GO

IF COL_LENGTH(N'dbo.applications', N'employee_id') IS NULL
    ALTER TABLE dbo.applications ADD employee_id BIGINT NULL;
GO

-- Add the registered phone number to an existing Internal Employee account.
-- Replace the values before running this UPDATE.
-- UPDATE dbo.users
-- SET phone = N'0771234567'
-- WHERE employee_epf = N'EMPLOYEE_EPF';
-- GO

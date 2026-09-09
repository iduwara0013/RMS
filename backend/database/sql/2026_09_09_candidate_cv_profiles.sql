USE RecruitmentManagementSystem;
GO

IF OBJECT_ID(N'dbo.candidate_cv_profiles', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.candidate_cv_profiles (
        cv_profile_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        application_id BIGINT NOT NULL,
        professional_summary NVARCHAR(MAX) NULL,
        skills NVARCHAR(MAX) NULL,
        education NVARCHAR(MAX) NULL,
        experience NVARCHAR(MAX) NULL,
        certifications NVARCHAR(MAX) NULL,
        languages NVARCHAR(MAX) NULL,
        parse_status NVARCHAR(30) NOT NULL
            CONSTRAINT DF_candidate_cv_profiles_parse_status DEFAULT N'Pending',
        parse_message NVARCHAR(500) NULL,
        extracted_at DATETIME2 NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
        CONSTRAINT UQ_candidate_cv_profiles_application UNIQUE (application_id),
        CONSTRAINT FK_candidate_cv_profiles_application
            FOREIGN KEY (application_id)
            REFERENCES dbo.applications(application_id)
            ON DELETE CASCADE
    );
END;
GO

SELECT cv_profile_id, application_id, parse_status, extracted_at
FROM dbo.candidate_cv_profiles
ORDER BY cv_profile_id DESC;
GO

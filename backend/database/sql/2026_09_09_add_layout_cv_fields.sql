USE RecruitmentManagementSystem;
GO

IF COL_LENGTH(N'dbo.candidate_cv_profiles', N'projects') IS NULL
    ALTER TABLE dbo.candidate_cv_profiles ADD projects NVARCHAR(MAX) NULL;
GO

IF COL_LENGTH(N'dbo.candidate_cv_profiles', N'confidence_score') IS NULL
    ALTER TABLE dbo.candidate_cv_profiles ADD confidence_score DECIMAL(5,2) NULL;
GO

IF COL_LENGTH(N'dbo.candidate_cv_profiles', N'review_status') IS NULL
    ALTER TABLE dbo.candidate_cv_profiles ADD review_status NVARCHAR(30) NULL;
GO

IF COL_LENGTH(N'dbo.candidate_cv_profiles', N'parser_version') IS NULL
    ALTER TABLE dbo.candidate_cv_profiles ADD parser_version NVARCHAR(50) NULL;
GO

IF COL_LENGTH(N'dbo.candidate_cv_profiles', N'parser_metadata') IS NULL
    ALTER TABLE dbo.candidate_cv_profiles ADD parser_metadata NVARCHAR(MAX) NULL;
GO

SELECT cv_profile_id, application_id, parse_status, confidence_score, review_status, parser_version
FROM dbo.candidate_cv_profiles
ORDER BY cv_profile_id DESC;
GO

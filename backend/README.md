# CPSTL Recruitment Management API

Laravel 12 backend for the Recruitment Management System.

## Local setup

1. Make sure SQL Server is running and create the `RecruitmentManagementSystem` database.
2. Update `.env` with your SQL Server password (`DB_PASSWORD`).
3. Enable the PHP `pdo_sqlsrv` and `sqlsrv` extensions.
4. Run the migrations:

```bash
php artisan migrate
```

If the PHP SQL Server driver cannot run migrations, execute
`database/sql/2026_09_09_add_layout_cv_fields.sql` in SSMS for the dynamic CV fields.

5. Start the layout-aware CV parser in a separate PowerShell terminal:

```powershell
cd cv_parser
.\start.ps1
```

6. Start Laravel:

```powershell
php artisan serve
```

The API is available at `http://127.0.0.1:8000/api`.
The local CV parser health endpoint is `http://127.0.0.1:8001/health`. Laravel falls back to its built-in text parser when this service is unavailable.

## Authentication endpoints

- `POST /api/login` with `{ "identifier": "EmployeePIN-or-EPF", "password": "..." }`
- `POST /api/change-password` with `{ "identifier": "...", "password": "...", "password_confirmation": "..." }`

The first login response includes `first_login: true` when the temporary password matches the employee PIN or EPF. The production frontend should use that flag to show the password-change screen.

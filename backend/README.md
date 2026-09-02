# CPSTL Recruitment Management API

Laravel 12 backend for the Recruitment Management System.

## Local setup

1. Make sure SQL Server is running and create the `RecruitmentManagementSystem` database.
2. Update `.env` with your SQL Server password (`DB_PASSWORD`).
3. Enable the PHP `pdo_sqlsrv` and `sqlsrv` extensions.
4. Run:

```bash
php artisan migrate
php artisan serve
```

The API is available at `http://127.0.0.1:8000/api`.

## Authentication endpoints

- `POST /api/login` with `{ "identifier": "EmployeePIN-or-EPF", "password": "..." }`
- `POST /api/change-password` with `{ "identifier": "...", "password": "...", "password_confirmation": "..." }`

The first login response includes `first_login: true` when the temporary password matches the employee PIN or EPF. The production frontend should use that flag to show the password-change screen.

# User and department management

Run `php artisan migrate` from the backend directory before opening these screens. Migration `2026_09_09_000014_add_management_status` adds `is_active` flags to `users` and `Department`; existing records start active. It does not delete existing records. Do not run `migrate:fresh` against your existing database.

Sign in as an HR Manager or System Administrator and open **Users** or **Departments** in the shared sidebar.

- Create a department first, then add an employee with PIN, EPF, contact details and roles.
- To assign a HOD, edit that employee, select **Head of Department** and their department. The department screen shows active employees with that role. This uses the same department assignment as vacancy approval authorization; no separate, conflicting HOD list is created.
- Internal employees need a registered phone for the existing OTP portal.
- New and reset accounts use PIN or EPF as the initial password, following the existing project behavior. A password change is required before staff operations. This identifier-based temporary password is for the current development workflow; use unique, expiring activation credentials before public production deployment.
- Edit an employee and clear **Account active** to deactivate, or check it to reactivate. Account edits and resets revoke existing staff and internal sessions. Editing your own account requires signing in again.
- HR cannot modify administrator accounts or assign the administrator role. An account cannot deactivate itself, remove its own management access, or remove the last active administrator.
- Departments cannot be deactivated while active employees or ongoing vacancies reference them. Inactive departments are excluded from new vacancy choices. Existing historical records are retained.
- Permission rules remain defined in the backend; this update assigns existing roles, not arbitrary permissions.

Checks: `php artisan test` uses isolated SQLite test data; `npm run lint`, `npx tsc --noEmit --incremental false`, and `npm run build` run from the frontend directory. These checks do not replace a live SQL Server/browser acceptance test.

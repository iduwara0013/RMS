# Vacancy-specific application forms

## Setup

From the backend directory, run `php artisan migrate`. The new migration creates `vacancy_form_versions` and `application_form_submissions`; it does not rewrite existing applications or the legacy ERD `Application_Form` table. Existing applications have no custom answers and continue to display their standard details and CV.

Keep the Laravel and frontend development servers running. Sign in as HR Manager and open **Application forms** in the sidebar.

## Example: Software Engineer

1. Create the vacancy in Vacancies first.
2. Open Application forms and select that vacancy.
3. Set the form title to `Software Engineer — Additional details`.
4. Add these questions:
   - Highest qualification: Dropdown; required; options `Degree`, `Diploma`, `Other` on separate lines.
   - Years of relevant experience: Number; required.
   - Programming languages: Text answer; required.
   - Available start date: Date; optional.
   - Willing to work onsite?: Yes / No; required.
   - Degree certificate: Document upload; optional or required according to the vacancy criteria.
5. Use Preview candidate form to check the questions and order.
6. Save & link version 1. This links the form, but does not publish the vacancy or bypass HR/HOD/MD approval.
7. Complete the normal vacancy approval and publication workflow.
8. Public applicants see the questions on the external application page. Verified employees see the same questions for internal/both vacancies in the internal portal.
9. After submission, HR, the relevant HOD and MD can open Applications, click the candidate name and view Application answers, including its original form version. Supporting files use the existing protected document-download endpoint.

## Version behavior

- Every save creates an immutable version for that vacancy; existing versions are not edited.
- New applications must submit the current version. An old open browser form receives a conflict message and must be reloaded.
- Previous applicants retain their original questions, labels and answers even after a new version is linked.
- To return future applicants to standard fields only, save a version containing no extra questions.
- Closed and cancelled vacancies are read-only in the builder.
- Editor changes are not saved until Save & link is clicked. There is a discard warning when changing vacancies and a browser unload warning for unsaved changes.

## Limits and security

- Maximum 30 extra questions per vacancy form.
- Dropdowns require at least two unique options, at most 30.
- Text answers have a 4,000-character limit.
- Supporting files allow PDF, DOC, DOCX, JPG and PNG, up to 5 MB each. CV remains required separately.
- Files are stored on Laravel's private local disk; database answers contain document references, not public file paths.
- Required fields, types, dropdown choices, version and vacancy availability are checked on the server. Failed database submissions clean up files stored by that request.
- PHP/web-server request limits also apply to the combined CV and supporting files. Check `upload_max_filesize`, `post_max_size`, and `max_file_uploads` for the deployment's chosen aggregate upload limit; no PHP configuration is changed by this feature.

## Validation

Run `php artisan test` in backend, and `npm run lint`, `npx tsc --noEmit --incremental false`, and `npm run build` in frontend. Automated feature tests use isolated SQLite, not the live SQL Server database. CV extraction is mocked in form tests; existing CV parsing remains a separate feature.

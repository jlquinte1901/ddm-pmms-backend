# AGENTS.md — DDM-PMMS (Backend and Frontend)

## Project Context: Digital-Based Document Management and Project-Based Monitoring Management System

### 1. Project Background

DDM-PMMS stands for **Digital-Based Document Management and Project-Based Monitoring Management System**. It is a web-based platform intended for the City Engineering Department, with the Data Management Section responsible for overseeing system administration, document governance, and construction project monitoring.

The platform consists of two distinct business modules that share a common backend foundation, authentication, authorization, audit infrastructure, and reporting capabilities.

The primary objective is to centralize departmental document management and provide reliable visibility into construction project implementation, progress, timelines, and historical changes.

This file applies to both backend and frontend work in this repository. You are the AI software engineering assistant for the project. Do not treat it as a generic CRUD application: understand the business processes, access restrictions, data integrity requirements, document lifecycle, and project monitoring rules before implementing features. Work incrementally, analyze the existing codebase before changing anything, explain important decisions, and never assume a feature is required merely because a package or technology is available.

Detailed project rules, the confirmed schema, and the implementation order are in `ddm-pmms-backend.md` (the backend engineering guide) and in the DDM-PMMS Backend Design Document. Read `ddm-pmms-backend.md` before planning backend work. Section 16 of that guide lists decisions still awaiting the user's confirmation. Do not treat them as settled.

### 2. System Architecture

Follow this established high-level architecture:

```text
DDM-PMMS Platform
│
├── Shared Core
│   ├── Authentication
│   ├── Roles
│   ├── Users
│   ├── Permissions
│   ├── Audit Trail
│   └── Reports Engine
│
├── DDM Module
│   ├── Document Repository
│   ├── Categories
│   ├── Versioning
│   ├── Search
│   ├── Archive
│   └── Document Reports
│
└── PMMS Module
    ├── Project Registry
    ├── Progress Tracking
    ├── Critical Threshold Monitoring
    ├── Project History
    ├── Dashboard
    └── Project Reports
```

The architecture represents one platform with two separate business modules. Do not merge DDM and PMMS into one undifferentiated document-management workflow.

Reuse shared infrastructure where appropriate, but preserve module-specific validation, authorization, business logic, and reporting requirements.

The `documents` table is owned by DDM. A document with no `project_id` is a DDM document. A document with a `project_id` is a PMMS supporting document that reuses the same storage and versioning code, with PMMS ownership rules applied first. DDM routes never return project documents, and PMMS never calls DDM endpoints.

Do not introduce additional architectural layers, modules, packages, or integrations without a demonstrated need and approval where required.

### 3. Digital-Based Document Management (DDM)

DDM manages general departmental documents, including incoming and outgoing records, administrative files, and other departmental records. It is not restricted to construction projects.

Examples include:

- Human Resources documents
- Financial documents
- Contracts
- Memoranda
- Permits and correspondence
- Engineering plans and other departmental records

The backend must support the following capabilities as required by the approved implementation scope:

- Document registration and metadata management
- Secure document upload, storage, retrieval, and download
- Document categorization and classification
- Search and filtering
- Document versioning and version history
- Archiving and restoration
- Download access levels and download requests
- Document activity tracking
- Document inventory and summary reports

#### DDM Business Rules

1. Document metadata and document file contents must be managed appropriately. Store file contents through Laravel's configured storage system and retain the necessary metadata in the database.
2. A document must not require a project association. A project association is optional and must not be used to restrict DDM to project-related records.
3. When a new version is uploaded, add a new `document_versions` row. Versions are immutable and are never overwritten or deleted. There is no overwrite endpoint.
4. The current version is the row where `version_number = documents.latest_version_number`. Do not add a `current_version_id` foreign key, because it would make the `documents` and `document_versions` relationship circular. Assign version numbers under a row lock on the parent document.
5. Archiving is a lifecycle operation and must not automatically delete a document or its historical versions. Archived documents stay searchable and downloadable under the usual rules, but cannot receive a new version until restored.
6. Restrict document operations according to the authenticated user's permissions:
   - Admin and Super Admin upload, add versions, edit metadata, archive, and restore.
   - Only Super Admin soft-deletes and undeletes, with a required reason.
   - PEs and Viewers only view DDM documents.
7. Every category has a default access level (`open`, `restricted`, or `confidential`), and a document may override it. Open documents download directly for users who can view them. Restricted documents need an approved, unexpired request decided by any Admin. Confidential documents need one decided by Super Admin only. Nobody approves their own request, and approvals expire.
8. Validate uploaded files, file types, sizes, metadata, and storage operations on the backend.
9. Do not expose private storage paths or permit unauthorized direct file access.
10. Record relevant document creation, updates, version changes, archive operations, download requests, decisions, downloads, and other significant actions in accordance with the audit requirements.
11. Do not implement permanent deletion, purge, or retention expiry without an approved business rule.

### 4. Project-Based Monitoring Management (PMMS)

PMMS manages construction and infrastructure project records. Project Engineers (PEs) are responsible for entering their assigned projects and maintaining progress information. The Data Management Section oversees the project registry, monitors implementation, reviews project status, and identifies projects requiring attention.

Each project may contain the following information:

- Project category
- Implementation year
- Project name
- Contract number
- Location
- Region, LGU, department, and implementing office (from the actual paper project forms)
- Contract amount
- Revised amount
- Fund source
- Planned start date
- Planned finish date
- Revised finish date (set when resuming a paused project)
- Actual start date
- Actual finish date (informational only)
- Assigned Project Engineer
- Project manager (display text from the paper form, never used for authorization)
- Contractor
- Project status
- Progress percentage
- Remarks, including explanations for delays, postponement, suspension, or termination where applicable

The backend must support:

- Project registration and management
- Manual progress updates
- Project status management
- Critical-threshold monitoring
- Project-specific history
- Dashboard data
- Filtered project lists and reports

#### PMMS Authorization Rules

**Super Admin:** The Data Management Section Head, responsible for overall system oversight, administration, access governance, and authorized supervision of records. Manages users, roles, and permissions, reads the audit trail, and approves confidential download requests. There is no blanket bypass: every ability is an explicit permission.

**Admin:** Personnel under the Data Management Section. Runs DDM, views all PMMS projects, and holds the narrow `pmms.projects.administer` permission.

**Project Engineer (PE):** The only role that writes PMMS project data. A PE creates projects for himself, updates his own projects, submits progress, pauses and resumes them, and uploads supporting documents for them. A PE must not access or modify another PE's project.

**Viewer:** Has read-only access. The Viewer role has no permissions by itself. Super Admin grants module access (DDM, PMMS, or both) per user.

`pmms.projects.administer`, held by Admin and Super Admin, covers only these actions: terminate, reopen a completed or terminated project, reassign the PE, and soft-delete or restore a project. Each requires a reason and is recorded in project history and the audit trail. Admin and Super Admin cannot otherwise edit project data.

Ownership rules:

- A project has exactly one owner, the persisted `projects.assigned_pe_id`. A PE can own many projects.
- When a PE creates a project, set `assigned_pe_id` and `created_by` to the authenticated user. Ignore any ownership value sent by the client.
- Only the administer endpoint reassigns a project, and the new owner must be an active user with the PE role.
- A PE who does not own a project gets `404` on every project route, so its existence is not confirmed.

Enforce these restrictions in the backend for every applicable operation, including listing, viewing, creating, updating, deleting, exporting, and accessing related project resources.

Never rely solely on frontend visibility or disabled controls to enforce permissions.

Derive the authenticated actor from the server-side authentication context. Never trust client-supplied user IDs, role IDs, or project ownership values as proof of authorization.

Do not hardcode role IDs throughout business logic. Use named roles and explicit permissions (named `module.resource.action`, for example `ddm.documents.upload`). Follow the repository's existing authorization conventions. If the repository already has a permission package, map the design's permission names onto it. If not, confirm the approach with the user before writing migrations.

### 5. The 35-Day Critical-Threshold Rule

The 35-day rule is a project monitoring and accountability mechanism intended to highlight projects approaching their planned completion dates while still unfinished.

The indicator is not a disciplinary mechanism and must not automatically suspend, terminate, or punish a project or Project Engineer.

Project statuses are `not_started`, `ongoing`, `suspended`, `postponed`, `completed`, and `terminated`. Status is separate from the indicator.

Today is the current calendar date in `Asia/Manila`. `days_remaining` is `effective_finish_date` minus today, in calendar days, where `effective_finish_date` is `revised_finish_date` if set, otherwise `planned_finish_date`. The finish date itself is day 0.

Use the following indicator definitions:

- **Completed:** The project status is `completed`. A PE completes a project by submitting 100% progress, which also records `completed_at`. `actual_finish_date` does not decide completion.
- **Terminated:** The project status is `terminated`. It is excluded from Critical and Overdue.
- **Paused:** The project status is `suspended` or `postponed`. It is not counted as Critical or Overdue. The dashboard shows days paused from `paused_since`.
- **Normal:** Status is `not_started` or `ongoing`, with more than 35 days remaining.
- **Critical:** Status is `not_started` or `ongoing`, with 0 to 35 days remaining, inclusive.
- **Overdue:** Status is `not_started` or `ongoing`, with fewer than 0 days remaining.

#### Implementation Rules

1. Use `effective_finish_date` as the reference for calculating remaining time. Never overwrite `planned_finish_date` to extend a deadline.
2. Use calendar days.
3. Keep the monitoring indicator distinct from the project's actual status. Compute it at read time. Never store it.
4. Do not automatically change a project's status because it enters the critical period or becomes overdue. There is no scheduled job and no write when a project becomes Critical.
5. The PE controls pausing. A PE suspends or postpones a project (from `not_started` or `ongoing`) with a required reason, which sets `paused_since`.
6. To resume (from `suspended` or `postponed` only), the PE must enter a `revised_finish_date` that is not before today, plus a remark. The status becomes `ongoing` and `paused_since` is cleared.
7. Allow authorized PEs to provide remarks explaining project delays and relevant circumstances. A progress report may keep the same percentage so a PE can explain a delay without changing progress. Lowering progress requires remarks.
8. Planned dates can be edited only while a project is `not_started`. Handle missing dates and invalid date ranges (planned finish before planned start) through validation rather than silently calculating misleading indicators.
9. Implement one tested indicator class and matching query scopes, with today passed in as a parameter so tests can freeze the date. Use them in project lists, dashboards, project details, and reports. Do not duplicate threshold calculations in controllers or report endpoints.
10. Do not implement automatic email, SMS, or push notifications as part of the initial scope unless separately approved.

### 6. Project History and System Audit Trail

DDM-PMMS requires both project-specific history and a system-wide audit trail. These serve related but distinct purposes.

#### Project History

Project history (`project_history`) provides a chronological record of important events affecting an individual project. It is read by project users on the project detail view.

Relevant events include:

- Project creation (one `created` row holding the full snapshot)
- Changes to project information (one row per changed field, grouped by a `change_set_id`)
- Progress percentage updates
- Changes to project status, including pause, resume, completion, termination, and reopen
- Project Engineer reassignment
- Changes to planned or actual dates
- Changes to financial or contractual information
- Remarks and other relevant project updates

Where applicable, capture:

- Project identifier
- Authenticated actor
- Action performed
- Date and time
- Changed fields
- Previous values
- New values
- Relevant remarks or reason (required for pause, resume, terminate, reopen, reassign, delete, and restore)

A project history record must be associated with the correct project and accessible only to authorized users. Store money as a decimal string and dates as `YYYY-MM-DD` in the JSON values.

#### System Audit Trail

The audit trail (`audit_logs`) provides a broader record of significant actions across the platform. It is read only with the `audit.view` permission.

Audited events include logins, failed logins, logouts, user, role, and permission changes, every document action, download request, decision, and download, project administer actions, and CSV exports. Failed logins and refused sensitive actions are written even though the request fails.

#### Audit Requirements

1. Distinguish business history from general system auditing. A project event usually writes both.
2. History and audit rows are append-only. There is no endpoint, model method, or database right to update or delete them. The runtime database account has only `INSERT` and `SELECT` on `audit_logs` and `project_history`.
3. Record the authenticated actor and server-generated timestamps.
4. Do not trust client-supplied audit actors or timestamps.
5. Write history and audit rows inside the same transaction as the change, from services (a history recorder and a shared audit service), not controllers. If either write fails, the change fails.
6. Avoid recording passwords, authentication tokens, secrets, file contents, or unnecessary sensitive information.
7. Apply appropriate access controls to audit and history records.
8. Follow the existing project's auditing package, middleware, observer, or service conventions rather than creating overlapping audit mechanisms without justification. If none exists, use the design's `audit_logs` table and shared audit service.

A progress change from 40% to 90%, for example, must be traceable to the authenticated user who performed the update, including the previous and new values.

### 7. Reports and Dashboard Data

The backend provides the data required by DDM and PMMS dashboards and reports.

PMMS reports must support appropriate filters, including:

- Implementation year
- Project category
- Project status
- Assigned Project Engineer (available only to users who can view all projects)
- Critical monitoring indicator
- Fund source, region, LGU, contractor
- Other approved project attributes

Filtering must be applied by the backend and must respect the requesting user's permissions.

A PE must not gain access to another PE's projects by manipulating query parameters, filters, identifiers, pagination, or export requests. A PE is forced to his own projects whatever filters he sends.

Use one shared filter object for the project list, dashboard, and reports, so a dashboard number can be reproduced in a report. Map `group_by` and `sort` through fixed allow-lists and never place client text into SQL.

DDM reports may include document counts, category summaries, access levels, archive status, version information, activity, download request summaries, and other approved document management metrics.

Exports are CSV only unless the user approves more. Stream CSV in chunks and write an audit row for each export.

Prefer consistent, reusable query logic for dashboard and report calculations. Apply database indexes and pagination where appropriate, and avoid unnecessary queries or loading entire datasets into memory.

### 8. Database and Data Integrity Principles

The database design must support the established architecture and business rules.

- Use normalized relational tables where appropriate.
- Use snake_case names and unsigned `bigint` primary and foreign keys everywhere. Do not mix string and integer ids.
- Define primary keys, foreign keys, indexes, unique constraints, and appropriate nullability.
- Preserve referential integrity between users, projects, documents, document versions, categories, project history, and audit records.
- Use `decimal(15,2)` for monetary fields. Never use floating-point types for currency.
- Store progress as `tinyint unsigned` with a check between 0 and 100.
- Use `date` for project dates and `timestamp` (app timezone `Asia/Manila`) for event times.
- Use stored generated column `effective_finish_date` (`COALESCE(revised_finish_date, planned_finish_date)`) so the indicator filters can use an index.
- Validate status and access-level values with PHP backed enums and, where useful, `CHECK` constraints.
- Avoid duplicating project or user information unnecessarily.
- Use database transactions for multi-step operations requiring consistency.
- Define deletion behavior deliberately. Use `RESTRICT` on foreign keys, except cascade on the two permission pivot tables. Users, documents, and projects are deactivated or soft-deleted, never hard-deleted. `audit_logs` and `project_history` have no soft deletes.
- Do not use cascading deletion in a way that unintentionally destroys required historical records.
- Use migrations as the authoritative mechanism for schema changes. Create `projects` before `documents`, because `documents.project_id` references it.
- Follow existing naming conventions and inspect related migrations and models before modifying the schema.

The schema is critical to this project. The proposed design has 15 tables (the rules behind it are confirmed, but five structural choices are still pending approval in `ddm-pmms-backend.md` section 16, decisions 15 to 19). Do not create, change, or migrate any table, column, index, or constraint without the user's explicit approval: `users`, `roles`, `permissions`, `permission_role`, `permission_user`, `audit_logs`, `personal_access_tokens`, `document_categories`, `documents`, `document_versions`, `document_download_requests`, `project_categories`, `projects`, `project_updates`, and `project_history`. Check the repository for existing tables before creating any of them.

Do not assume that every conceptual entity needs a separate table. Do not create the optional future tables (`document_types`, `contractors`, `fund_sources`, `saved_reports`, `project_assignments`, `project_phases`) without approval.

### 9. API Design and Error Handling

The backend exposes a REST API for the frontend.

- Prefix routes with `/api/v1`, grouped by shared core, `/ddm`, and `/pmms`, unless existing API route conventions establish otherwise.
- Use Laravel API Resources for response transformation unless existing project conventions justify another approach.
- Validate incoming requests using Form Requests, one per write endpoint.
- Keep controllers focused on HTTP concerns and delegate complex business logic to suitable application services. Use one database transaction per service method.
- Use consistent response structures and appropriate HTTP status codes.
- Use one error body: `message`, a stable `code`, and `errors` keyed by field.
- Distinguish validation failures (`422`), authentication failures (`401`), authorization failures (`403`), missing or out-of-scope records (`404`), state conflicts (`409`), throttling (`429`), and unexpected server errors (`500`).
- Return `404` for records outside the caller's scope. Login errors are generic and throttled.
- Paginate collection endpoints with a maximum page size of 100.
- Do not expose stack traces, secrets, internal file paths, or sensitive implementation details in production responses.
- Protect against mass assignment and insecure direct object references. Use an explicit `$fillable` allow-list. Ownership, status, progress, pause, completion, and revised-date fields are excluded from mass assignment and changed only by named service methods.
- Ensure API endpoints enforce authorization even when called directly without the frontend.
- Read permissions from the database on each request, never from the token. Revoke tokens on logout, deactivation, role change, and password change.

Do not create unnecessary abstraction layers or new base folders without approval.

### 10. File Storage and Document Security

Use Laravel's configured storage mechanism for document files.

- Store document metadata separately from file contents.
- Validate uploads on the server using an allow-list of extensions. Detect the MIME type from the file content, require it to agree with the extension, and ignore the client-sent type.
- Use a configurable size limit and keep the PHP and web server limits aligned. Proposed types and size (PDF, Word, Excel, PowerPoint, JPEG, PNG, 50 MB) are awaiting confirmation.
- Generate safe, random storage paths rather than relying on user-supplied filenames. Keep the user's filename only in `original_name`, sanitized for display.
- Prevent path traversal and unauthorized file access.
- Ensure file replacement and version creation do not unintentionally overwrite historical files.
- Write the file before the commit and delete it if the transaction fails, so the database never points at a missing file.
- Use a private disk with no public URL or symlink. Every download goes through an authorized endpoint with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. Write the audit row first and stream only if it succeeds.
- Store a SHA-256 checksum for each version.
- Do not add malware scanning unless a requirement is approved.
- Follow approved backup, retention, and recovery procedures. Back up the database and the document disk to the same point in time.

Do not assume that storing a document's path in the database automatically secures the underlying file.

### 11. Testing Expectations

Use Pest for unit and feature tests, following the existing project conventions. Write tests inside each implementation phase, not only at the end.

Prioritize tests for:

- Authentication and authorization, including throttling, expired tokens, and deactivated users
- Role and permission enforcement through an endpoint-by-role matrix
- PE project ownership, including `404` on another PE's project through lists, dashboards, reports, history, and project documents
- Admin and Super Admin cannot edit project data, only the administer actions
- Viewers cannot write through any endpoint
- Project creation ignores a client-submitted `assigned_pe_id`
- Project creation and updates
- Progress, pause, resume, completion, and administer actions write history and audit records, and fail closed if either write fails
- Critical-threshold boundary conditions: 36 days (Normal), 35 and 0 days (Critical), minus 1 day (Overdue), completed, paused, and resumed projects
- The indicator is never stored and never changes status
- Document upload validation, storage cleanup after a failed transaction
- Download authorization for each access level, including approved, expired, and self-approved requests
- Document version creation, numbering under concurrent uploads, and retention
- Archive and restore behavior, and that only Super Admin soft-deletes
- `audit_logs` and `project_history` rows cannot be updated or deleted
- Report filters and authorization, and that dashboard numbers equal report numbers
- API validation and error responses

Use existing factories and factory states where available. Prefer feature tests for complete API behavior and unit tests for isolated business rules when appropriate.

Do not delete existing tests without approval. Run the narrowest relevant test suite after changes and follow the repository's testing instructions.

### 12. Technology Context (Backend and Frontend)

These are the intended technologies, not a requirement to use every listed package. Inspect the repository, `composer.json`, `composer.lock`, and `package.json` before relying on any of them. Do not install or replace dependencies without approval. Do not assume the frontend and backend share one directory layout. Inspect the actual structure first.

**Backend**

- Laravel 13 and PHP 8.5
- REST API under `/api/v1`
- Laravel Sanctum token-based authentication, with token expiry
- MySQL (InnoDB, `utf8mb4`)
- Laravel Storage on a private disk for document files
- Pest for unit and feature tests
- Roles and permissions in the design's own tables (`roles`, `permissions`, `permission_role`, `permission_user`). Spatie Laravel Permission is not part of the design. See decision 14 in `ddm-pmms-backend.md` section 16.
- Report exports are CSV only. Do not add Laravel Excel, DomPDF, or similar without approval.

**Frontend**

- React 19 with TypeScript
- Vite
- React Router
- Axios
- TanStack Query
- Zustand where client-side state management is appropriate
- React Hook Form with Zod or Yup
- MUI or shadcn/ui with Tailwind CSS (follow whichever is installed)
- TanStack Table
- ApexCharts where dashboard visualizations are needed

### 13. Frontend Rules

The frontend consumes the REST API described in the Backend Design Document. These rules follow from the confirmed backend behavior. They do not replace repository conventions: inspect existing components, routes, hooks, and state before adding anything new.

1. **The backend is the source of truth for security.** Hiding or disabling controls is a convenience only. Never rely on it for permissions, ownership, or access levels.
2. **Use the server's permissions.** Read the signed-in user's role and effective permissions from `GET /auth/me`, and use them to decide which navigation and actions to show. Do not hardcode role checks or numeric role IDs.
3. **Never send ownership or identity fields.** Do not submit `user_id`, `role_id`, `assigned_pe_id`, `created_by`, `uploaded_by`, or `decided_by` from forms. The backend ignores them. The only exceptions are the admin screens that explicitly take a `role_id` for user administration and an `assigned_pe_id` for the administer reassign action.
4. **Show the indicator the API returns.** Display `indicator` and `days_remaining` from the API (Normal, Critical, Overdue, Paused, Completed, Terminated). Never recompute the 35-day rule in the browser, so lists, details, dashboards, and reports cannot disagree. Show Paused as its own state and display days paused where the API provides it.
5. **Respect the project lifecycle.**
   - Pausing (suspend or postpone) needs a reason.
   - Resuming needs a new finish date (`revised_finish_date`) and a remark.
   - Lowering progress needs remarks.
   - Reporting 100% completes the project, so confirm it with the PE.
   - Terminate, reopen, reassign, and delete appear only for users with `pmms.projects.administer` and require a reason.
   - Planned dates are editable only while the project is not started.
6. **Show project history as the API returns it.** Group changed fields by `change_set_id` and show the actor, action, previous value, new value, time, and reason. Do not edit or delete history from the UI.
7. **Handle the standard error format.** Responses carry `message`, a stable `code`, and `errors` keyed by field. Show field errors from `422`. Treat `404` as not found (an out-of-scope project also returns `404`). Treat `403` as not allowed, `409` as a state conflict, and `429` as throttled. Handle `download_approval_required` by offering the download request flow. Never show stack traces or raw server errors to users.
8. **Documents.** Upload with multipart requests. Never build or display storage paths or public file URLs. Every download goes through the authorized download endpoint. Show a document's access level and whether the user can download it. Offer the request flow for restricted and confidential documents, and let eligible approvers approve or deny. Show version history, and make clear that a new upload creates a new version and never overwrites the old one. Archive and delete are different actions with different labels, and only Super Admin sees delete.
9. **Server-side filtering and pagination.** Send filters, sorting, and page parameters to the API. Do not load whole collections and filter in the browser. A PE sees only his own projects, and the UI must not pretend otherwise.
10. **Reports and exports.** Export CSV through the API. Do not generate reports from partial client data.
11. **Validation.** Client-side validation (React Hook Form with Zod or Yup) improves the experience, but the backend validation is authoritative. Match the backend rules (progress 0 to 100, required reasons, date order) without duplicating business logic.
12. **Do not log or persist secrets.** Never log tokens or passwords. Where the client keeps the Sanctum token has not been decided. Ask before choosing a storage approach for the login flow.
13. **Do not invent features.** Notifications by email, SMS, or push, automatic progress calculation, and automatic status changes are out of scope.

### 14. Development Workflow

Work in small, reviewable stages. Work only on the stage or task the user approves. The stages are a recommended order, not permission to implement everything automatically. The backend stages map to the eight implementation phases in `ddm-pmms-backend.md`.

1. **Repository assessment.** Inspect the structure, routes, migrations, models, authentication, permissions, dependencies, tests, installed skills, and the frontend. Report what exists and what is missing, including whether Spatie Laravel Permission is installed. Do not rewrite existing work without understanding it.
2. **Architecture and ERD.** Drafted in the Backend Design Document and awaiting closure of the pending decisions. Reconcile names and conventions with the repository.
3. **Database foundation, Shared Core, and audit.** Migrations (only after approval), seeders, factories, authentication, users, roles, permissions, and the shared audit service.
4. **DDM.** Categories, upload, versions, storage service, search, archive and restore, soft-delete, access levels, download requests, downloads, and reports.
5. **PMMS.** Projects, ownership scope, administer actions, the history recorder, progress, pause, resume, completion, the indicator class and scopes, project documents, dashboards, and reports.
6. **Frontend screens.** Build against the confirmed API contract, in the same module order, once the matching endpoints exist.
7. **Integration and verification.** Authorization across all modules, database integrity, API consistency, auditability, error handling, and tests.
8. **Deployment readiness.** Configuration, private storage, environment variables, backup and recovery, and logging, when explicitly requested.

### 15. How to Respond to Development Requests

When the user requests a feature:

1. Explain your understanding of the requirement.
2. Identify the affected module (Shared Core, DDM, or PMMS) and whether the work is backend, frontend, or both.
3. Inspect existing code and the applicable instructions.
4. Check `ddm-pmms-backend.md` section 16. If the work depends on a pending decision, say so and ask before any hard-to-reverse choice, especially migrations.
5. Identify the database changes, API endpoints, authorization requirements, and tests involved.
6. Explain significant design decisions or ambiguities.
7. Implement only the approved scope.
8. Run appropriate tests and checks.
9. Summarize the files changed, behavior implemented, tests run, and unresolved issues.

When the user asks for planning or architecture, provide explanations, diagrams, database relationships, and steps before writing code. Do not generate large amounts of code when only a plan, ERD, or explanation was requested. Never claim a migration, test, endpoint, screen, or feature works unless you have verified it.

The Laravel Boost guidelines below apply to backend (PHP) work.

---

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use APIs that match the installed major version of each package. Do not assume a package version.

Before relying on a package's API:

- PHP packages: run `composer show --direct` or `composer show <vendor/package>`.
- JavaScript packages: inspect `package.json` for installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever working in that domain. Do not wait until you are stuck.

## Conventions

- Follow existing code conventions. Check sibling files for the correct structure, approach, and naming.
- Use descriptive variable and method names.
- Check for existing components and implementations before creating new ones.

## Verification Scripts

Do not create verification scripts or use Tinker when existing tests cover the functionality and prove it works. Unit and feature tests are preferred.

## Application Structure and Architecture

- Stick to the existing directory structure.
- Do not create new base folders without approval.
- Do not change application dependencies without approval.

## Frontend Bundling

If a frontend change does not appear in the UI or results in an `Unable to locate file in Vite manifest` error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

Only create documentation files when explicitly requested by the user.

## Laravel Boost Project Rules

When `.ai/rules` exists, before entering plan mode or creating/editing any file:

1. Open `.ai/rules/index.md`.
2. Read every rule file whose path globs cover the files in scope.
3. Run `grep -rin 'keyword' .ai/rules` to identify additional relevant rules not captured by path matching.
4. Follow all applicable rules before making changes.

If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly through the command line.
- Use `php artisan list` to discover available commands.
- Use `php artisan [command] --help` to inspect command options.
- Inspect routes using `php artisan route:list` and relevant filters.
- Read configuration through `php artisan config:show` or inspect configuration files.
- Pass `--no-interaction` to Artisan commands and provide the appropriate options.

## Tinker

- Use Tinker for application-context debugging only when appropriate.
- Do not create models through Tinker without user approval.
- Prefer existing Artisan commands and automated tests when they cover the required behavior.
- Use single quotes around the shell argument to prevent shell expansion.

## PHP Rules

- Always use curly braces for control structures, including single-line bodies.
- Use PHP 8 constructor property promotion where appropriate.
- Declare explicit return types and type hints for method parameters.
- Use TitleCase for enum keys.
- Prefer PHPDoc blocks over inline comments.
- Use inline comments only for exceptionally complex logic.
- Use array-shape definitions in PHPDoc where appropriate.

## Deployment Rules

Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

## Laravel Core Rules

- Use `php artisan make:` commands to create files such as migrations, controllers, and models.
- Use `php artisan make:class` for generic PHP classes.
- Pass `--no-interaction` and appropriate options to Artisan commands.
- When creating models, create useful factories and seeders where appropriate.
- Ask the user whether additional model-related artifacts are needed when the requirements are unclear.

## APIs and Eloquent Resources

- Prefer Eloquent API Resources and API versioning unless existing API routes establish a different convention.
- Follow existing project patterns.

## URL Generation

Prefer named routes and Laravel's `route()` function when generating URLs.

## Testing Rules

- Use Pest.
- Create tests using `php artisan make:test --pest {name}`.
- Do not include the test suite directory in the test name.
- Read the applicable testing best-practices skill for coverage, naming, structure, dependency isolation, and review.
- Use existing factories and factory states when possible.
- Do not delete tests without approval.

## Running Tests

- Run the narrowest test suite that covers the change.
- Use `php artisan test --compact` with the relevant file or filter.
- Rerun a test after changing it.
- After relevant feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

## Laravel Pint

If PHP files are modified, run:

```bash
vendor/bin/pint --dirty --format agent
```

Do not run `vendor/bin/pint --test --format agent`. Use Pint to fix formatting issues.

---

# Final Instruction to the Agent

Before implementing a task:

1. Read this file and follow all applicable instructions.
2. Read `ddm-pmms-backend.md`, including its list of pending decisions in section 16.
3. Inspect the repository's current implementation and conventions.
4. Read `.ai/rules/index.md` and all applicable rules if `.ai/rules` exists.
5. Activate and read the relevant skills for the current task.
6. Identify whether the work belongs to Shared Core, DDM, or PMMS, and whether it is backend, frontend, or both.
7. Verify installed package versions (`composer.json` for PHP, `package.json` for JavaScript) before relying on package APIs.
8. Preserve the established architecture and business rules.
9. Implement only the requested scope.
10. Test the affected behavior. Format modified PHP files with Pint. For frontend changes, run the lint, type-check, and test scripts defined in `package.json` that apply.
11. Explain what changed, which tests were run, and any unresolved issues.

If a business rule is ambiguous, explain the ambiguity and request clarification rather than inventing behavior. Ask before writing migrations that depend on a decision still pending in `ddm-pmms-backend.md`. Do not claim that code, tests, or migrations were verified unless the relevant checks were actually performed.
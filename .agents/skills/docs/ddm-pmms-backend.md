# DDM-PMMS Backend Engineering Guide

## 1. Purpose of This File

This document is the persistent project context and implementation standard for AI coding agents working on the Digital-Based Document Management and Project-Based Monitoring Management System (DDM-PMMS) backend.

Read this file before planning or changing backend code. Do not require the user to repeat the project background, business rules, architecture, security model, or coding expectations in every prompt.

This guide reflects the decisions confirmed in the **DDM-PMMS Backend Design Document** (database design, API plan, business flows, security rules, and roadmap). If this file and the design document ever disagree, stop and ask the user which one is current. Section 16 lists decisions that are still awaiting confirmation; do not treat them as settled.

This is a project-specific guide. Follow the Laravel project's installed agent skills and rules as additional technical guidance. Do not assume every available package or technology must be implemented merely because it appears in the wider technology plan.

## 2. Project Background

DDM-PMMS is one web platform containing two distinct business modules:

1. **Digital-Based Document Management (DDM):** manages general departmental records, including HR, financial records, contracts, memoranda, permits, correspondence, and other organizational documents. It supports classification, storage, retrieval, versioning, archiving, and document reports.
2. **Project-Based Monitoring Management System (PMMS):** manages project registry data and provides project progress tracking, critical-threshold monitoring, project history, dashboards, and project reports.

The modules have different business workflows but share common platform services. DDM is not limited to documents belonging to a specific project. PMMS is not simply a document repository.

The main operational beneficiary is the City Engineering Department, Data Management Section. Data Management oversees records and monitors all registered projects. Project Engineers (PEs) maintain the information for projects assigned to them.

## 3. Canonical Architecture

Keep this logical architecture stable unless the user explicitly approves a change:

```
DDM-PMMS Platform
|
|-- Shared Core
|   |-- Authentication
|   |-- Roles
|   |-- Users
|   |-- Permissions
|   |-- Audit Trail
|   `-- Reports Engine
|
|-- DDM Module
|   |-- Document Repository
|   |-- Categories
|   |-- Versioning
|   |-- Search
|   |-- Archive
|   `-- Document Reports
|
`-- PMMS Module
    |-- Project Registry
    |-- Progress Tracking
    |-- Critical Threshold Monitoring
    |-- Project History
    |-- Dashboard
    `-- Project Reports
```

This is a modular monolithic backend unless the existing codebase or the user explicitly establishes otherwise. Do not split the application into microservices without a clear requirement.

How the modules interact:

- Shared Core provides users, roles, permissions, tokens, the audit trail, and report helpers. It depends on nothing else.
- DDM owns the `documents` table, categories, versions, download requests, and document reports.
- PMMS owns projects, progress updates, project history, the indicator logic, the dashboard, and project reports.
- A document with no `project_id` is a DDM document. A document with a `project_id` is a PMMS supporting document. It reuses the same storage and versioning code, but PMMS routes apply project-ownership rules before calling it. DDM never reads PMMS tables except through that optional link, and PMMS never calls DDM endpoints.

## 4. Backend Technology Direction

The intended backend direction is:

- Laravel 13
- REST API architecture, versioned under `/api/v1`
- Laravel Sanctum token-based API authentication, with token expiry
- MySQL (InnoDB, `utf8mb4`)
- Roles and permissions stored in relational tables (see section 5 for the Spatie question)
- Laravel Storage for managed files, on a private disk
- Pest for backend unit and feature tests
- Postman for manual and API collection testing

Report exports are CSV only unless the user approves more. Potential tools such as Laravel Excel, DomPDF, Reverb, SMTP, Sentry, and Laravel-Auditing are optional and must only be introduced when required by an approved feature and the current project setup. Do not add dependencies speculatively.

Before coding, inspect the actual repository, `composer.json`, installed packages, migrations, routes, existing conventions, and available `.agents/skills` guidance. Never assume a package is installed just because it is listed in this guide.

## 5. Roles and Authorization

The business roles are:

- **Super Admin:** Data Management Section Head. Manages users, roles, and permissions, reads the audit trail, is the only role that soft-deletes documents, and approves confidential download requests.
- **Admin:** personnel under the Data Management Section. Runs DDM (upload, new versions, archive, restore, restricted-download approvals) and has read access to all PMMS projects. Holds the narrow `pmms.projects.administer` permission.
- **Project Engineer (PE):** the only role that writes PMMS project data. Creates projects for himself, updates his own projects, submits progress, pauses and resumes, and uploads supporting documents for his own projects. Sees only projects assigned to him. Views DDM documents.
- **Viewer:** read-only. Has no permissions by default. Super Admin grants module access (DDM, PMMS, or both) per user.

Use backend authorization as the source of truth. Frontend hiding is not security.

### Permission model

Permissions are named `module.resource.action`. The effective permissions of a user are the role's permissions plus any per-user grants. Grants only add access. There is no blanket Super Admin bypass: every ability is an explicit permission.

| Permission | Super Admin | Admin | PE | Viewer |
| --- | --- | --- | --- | --- |
| `users.manage`, `roles.manage`, `audit.view` | Yes | No | No | No |
| `ddm.documents.view` | Yes | Yes | Yes | Grant |
| `ddm.documents.upload` (create, new version, edit metadata) | Yes | Yes | No | No |
| `ddm.documents.archive` (archive and restore) | Yes | Yes | No | No |
| `ddm.documents.delete` (soft-delete and undelete) | Yes | No | No | No |
| `ddm.downloads.request` | Yes | Yes | Yes | Grant |
| `ddm.downloads.approve-restricted` | Yes | Yes | No | No |
| `ddm.downloads.approve-confidential` | Yes | No | No | No |
| `ddm.categories.manage`, `pmms.categories.manage` | Yes | Yes | No | No |
| `ddm.reports.view` | Yes | Yes | No | Grant |
| `pmms.projects.view-all` | Yes | Yes | No | Grant |
| `pmms.projects.view-own` | No | No | Yes | No |
| `pmms.projects.create`, `pmms.projects.manage-own` | No | No | Yes | No |
| `pmms.projects.administer` | Yes | Yes | No | No |
| `pmms.dashboard.view`, `pmms.reports.view` | Yes | Yes | Own scope | Grant |

`pmms.projects.administer` covers only four actions: terminate, reopen, reassign the PE, and soft-delete or restore a project. Admin and Super Admin cannot otherwise edit project data. Every one of those actions requires a reason.

### Mandatory PMMS ownership rules

- A project has exactly one owner, the persisted `projects.assigned_pe_id`. A PE can own many projects.
- Never trust client-submitted `user_id`, `role_id`, `assigned_pe_id`, `created_by`, `uploaded_by`, `decided_by`, or any ownership value. Ignore them if sent.
- Derive the authenticated user from the token. When a PE creates a project, `assigned_pe_id` and `created_by` are set to that user.
- Only the administer endpoint reassigns a project, and the new owner must be an active user with the PE role.
- Enforce ownership in every query and mutation through scoped route binding, query scopes, and policies, not just in the UI.
- A PE who is not the owner gets `404` on every project route, so another PE's project is not confirmed to exist. A user who can see the record but lacks the permission gets `403`.
- `project_manager` is display text copied from the paper form. It is never used for authorization.
- Viewers never write, through any endpoint.

Use policies, gates, middleware, scoped queries, and the permission layer consistently. Avoid scattered role-ID magic numbers. If legacy code uses fixed role IDs, inspect and centralize that mapping rather than spreading numeric checks.

## 6. Database Design Principles

Design the database before implementing complex endpoints. Inspect existing migrations and data models first; do not duplicate existing tables or make destructive schema changes without approval.

General rules:

- Use relational integrity with foreign keys. Use `RESTRICT` on delete, except cascade on the two permission pivot tables. Users, documents, and projects are deactivated or soft-deleted, never hard-deleted.
- Use snake_case names and unsigned `bigint` primary and foreign keys everywhere. Do not mix string and integer ids.
- Add indexes for common filters, joins, ownership checks, dates, statuses, and searchable metadata, including `FULLTEXT` where search needs it.
- Use transactions for operations that must succeed or fail together.
- Store timestamps in the app timezone `Asia/Manila`. Project dates are `date` columns, because they have no time of day.
- Money is `decimal(15,2)`. Never use floating-point types for currency.
- Progress is `tinyint unsigned` with a check between 0 and 100.
- Status and level values are `varchar(20)` validated by PHP backed enums and, where useful, `CHECK` constraints.
- Do not treat archiving as deletion. Soft-delete only where the design says so.
- Do not store the 35-day indicator. It is computed.
- Avoid circular foreign keys. Document the current version through `documents.latest_version_number`, not a `current_version_id`.
- Document important constraints and assumptions.

### Database schema (15 tables): critical

**The schema is the foundation of this project and the most important part of this guide.** Every endpoint, permission, report, history record, and test depends on it, and mistakes become expensive to undo once real department data exists. Treat it with the highest care.

**Status:** the business rules behind the schema are confirmed. The table structure is the proposed design from the Backend Design Document. Five structural choices are still awaiting the user's explicit approval (listed below and in section 16, decisions 15 to 19).

Rules for the agent:

- Do not create, rename, drop, retype, or reorder any table, column, index, constraint, or foreign key without the user's explicit approval.
- Do not write any migration until: (1) the repository has been inspected for existing tables and models, (2) decisions 14 to 19 in section 16 are answered, and (3) you have presented the migration plan and the user has approved it.
- If a requirement seems to need a schema change, stop and explain. Never adjust the schema silently to make code or a test pass.
- Never edit a migration that has already been run. Schema changes after approval go in a new migration.
- Report any difference between the repository and this schema before building on it.

#### Confirmed versus pending

| Item | Status |
| --- | --- |
| One owner per project (`assigned_pe_id`); a PE owns many projects | Confirmed |
| Paper fields kept: `region`, `lgu`, `department`, `implementing_office`, `project_manager` | Confirmed |
| Planned, revised, and actual dates; `revised_finish_date` set when resuming | Confirmed |
| Status set, `paused_since`, `completed_at`; completion at 100% by the PE | Confirmed |
| Admin runs DDM; only Super Admin soft-deletes documents | Confirmed |
| Open, restricted, and confidential download levels with `document_download_requests` | Confirmed |
| Narrow `pmms.projects.administer` permission | Confirmed |
| `contract_number` unique and nullable | **Pending** (decision 15) |
| `project_categories` as a lookup table (`fund_source` and `contractor` stay plain text) | **Pending** (decision 16) |
| `document_categories.scope` (separate DDM and project category lists) | **Pending** (decision 17) |
| `documents.latest_version_number` instead of a `current_version_id` | **Pending** (decision 18) |
| `permission_user` per-user grants (needed for Viewer module access) | **Pending** (decision 19) |

#### The 15 tables

- **Shared Core:** `users` (with `role_id`, `is_active`, soft deletes), `roles` (four seeded roles), `permissions`, `permission_role`, `permission_user` (grant-only), `audit_logs`, and Sanctum's `personal_access_tokens`.
- **DDM:** `document_categories` (with `scope` of ddm or pmms and a `default_access_level`), `documents`, `document_versions`, `document_download_requests`.
- **PMMS:** `project_categories`, `projects`, `project_updates`, `project_history`.

Key `projects` fields: `project_category_id`, `assigned_pe_id`, `created_by`, `implementation_year`, `project_name`, `contract_number` (unique, nullable, pending), `location`, `region`, `lgu`, `department`, `implementing_office`, `project_manager`, `fund_source`, `contract_amount`, `revised_amount`, `planned_start_date`, `planned_finish_date`, `revised_finish_date`, `effective_finish_date` (stored generated column: `COALESCE(revised_finish_date, planned_finish_date)`), `actual_start_date`, `actual_finish_date`, `contractor`, `status`, `progress_percentage`, `paused_since`, `completed_at`, `remarks`, soft delete. The region, LGU, department, and implementing office fields come from the actual paper project forms and stay.

The full column list, types, keys, nullability, and indexes for every table are in section C (data dictionary) of the Backend Design Document. Use that as the source for migrations, not this summary.

#### Schema invariants (never violate)

- Unsigned `bigint` primary and foreign keys everywhere. No string ids, no mixed types.
- Ownership is the persisted `projects.assigned_pe_id`. `project_manager` is display text only.
- The monitoring indicator is never stored. `status` holds the lifecycle state only.
- `planned_finish_date` is never overwritten after the project starts. A new deadline goes in `revised_finish_date`.
- `document_versions`, `audit_logs`, and `project_history` are append-only and have no soft deletes.
- Money is `decimal(15,2)`. Progress is `tinyint unsigned` with a 0 to 100 check.
- Foreign keys use `RESTRICT` on delete, except cascade on the two permission pivot tables. Users, documents, and projects are never hard-deleted.
- No circular foreign keys between `documents` and `document_versions`.

Optional future tables, not to be created without approval: `document_types`, `contractors`, `fund_sources`, `saved_reports`, `project_assignments`, `project_phases`.

## 7. DDM Business Logic

### 7.1 Document creation and upload flow

1. Authenticate the user and authorize `ddm.documents.upload`. On the PMMS route, authorize owner plus `pmms.projects.manage-own` instead.
2. Validate the file (allow-listed extension, server-detected MIME type that agrees with the extension, size limit) and the metadata.
3. Validate the category (active, with scope matching the module) and other referenced values.
4. Open a transaction. Insert the `documents` row with `latest_version_number` 1, or lock the existing row with `lockForUpdate` and use the next number.
5. Store the file on the private disk under a random generated path. Never build the path from user input. Record size, detected MIME type, and SHA-256 checksum.
6. Insert the `document_versions` row and write the audit row.
7. Commit. On any failure, roll back and delete the stored file.
8. Return a safe response with document metadata. Never expose private paths or public storage URLs.

### 7.2 Document retrieval and search

- Authorize access before returning metadata or a download.
- DDM routes are scoped to documents with no project. A project document returns `404` on DDM routes.
- Support pagination (maximum 100) and validated filters: category, access level, state, uploader, reference number, and date range. Use `FULLTEXT` for the `q` search.
- "View" means metadata and the version list. Opening or previewing file contents follows the download rules.
- Compute `can_download` for listed rows in one batched query, not per row.
- Download only through an authorized endpoint with a sanitized `Content-Disposition` name and `X-Content-Type-Options: nosniff`. Write the audit row first and stream only if it succeeds.

### 7.3 Versioning

- A revised document is always a new version. There is no overwrite endpoint.
- `document_versions` rows are immutable and are never deleted. `unique(document_id, version_number)` prevents duplicates.
- The current version is the row where `version_number = documents.latest_version_number`.
- Version numbers are assigned under a row lock on the parent document, so two simultaneous uploads cannot share a number.
- Record who uploaded each version and when. Reject an upload whose checksum equals the current version of the same document.
- A new version cannot be added to an archived or soft-deleted document. Restore it first.

### 7.4 Archiving, restoring, and deleting

- Archiving is not deletion. It sets `is_archived`, `archived_at`, and `archived_by`. Files and versions are untouched. Archived documents stay searchable by filter and downloadable under the usual rules.
- Admin and Super Admin archive and restore (`ddm.documents.archive`).
- Only Super Admin soft-deletes and undeletes (`ddm.documents.delete`), and a reason is required. Soft-deleted documents are hidden from lists except `state=deleted` for Super Admin, and their downloads are blocked.
- There is no hard-delete or purge endpoint, and no automatic retention expiry, until the user supplies an approved retention policy.
- A document's state is derived from its flags: active, archived, or deleted.

### 7.5 Download access levels and requests

Every category has a `default_access_level`, and a document may override it with `access_level`. The effective level is `open`, `restricted`, or `confidential`.

- **Open:** any user with `ddm.documents.view` may download. The download is audit-logged.
- **Restricted:** needs an approved, unexpired request, decided by any Admin.
- **Confidential:** needs an approved, unexpired request, decided by Super Admin only.
- Nobody approves their own request. A requester has at most one pending request per document.
- An approval is valid until `expires_at` (proposed 7 days by default, 30 at most). "Expired" is derived from `expires_at`, not stored.
- Requests, decisions, and downloads are all audited.
- Decisions on requests for project documents reuse the same approve and deny endpoints.

## 8. PMMS Business Logic

### 8.1 Project registry

The project record includes: category, implementation year, project name, contract number, location, contract amount, revised amount, fund source, planned start date, planned finish date, revised finish date, actual start date, actual finish date, region, LGU, department, implementing office, project manager (display only), contractor, status, remarks, progress percentage, and the owning PE.

Use the approved field names from the design document. Validate date order (planned finish not before planned start), numeric ranges, required fields, and status-specific requirements. Durations are calendar days.

Planned dates can be edited only while the project is `not_started`. After that, the planned finish date is never overwritten, and a new deadline is expressed through `revised_finish_date`. How an entry error is corrected after a project starts is an open decision (section 16).

### 8.2 Status definitions

| Status | Meaning | Who sets it |
| --- | --- | --- |
| `not_started` | Registered, work not begun. Default on creation. | System on creation |
| `ongoing` | Work in progress | PE (start, first progress report, or resume) |
| `suspended` | Paused by the PE, with a reason | PE |
| `postponed` | Paused by the PE, with a reason | PE |
| `completed` | PE reported 100% progress | PE, by submitting 100% |
| `terminated` | Formally ended | Admin or Super Admin (`administer`) |

Allowed transitions: `not_started` to `ongoing`, `postponed`, or `completed`; `ongoing` to `suspended`, `postponed`, or `completed`; `suspended` or `postponed` to `ongoing` (resume); any status except `terminated` to `terminated`; `completed` or `terminated` back to `ongoing` through reopen. Anything else returns `409`.

### 8.3 Progress updates, pausing, resuming, completion

Progress percentage is manually maintained by the PE. Never infer it from dates.

Progress report:

1. Authenticate the PE and find the project through an ownership-scoped query. Lock the row.
2. The status must be `not_started` or `ongoing`. A paused project must be resumed first.
3. Validate 0 to 100 and a report date that is not in the future. If progress is lowered, remarks are required. A report may keep the same percentage, so a PE can explain a delay without changing progress.
4. Insert the `project_updates` row, update `projects.progress_percentage`, and (proposed) move `not_started` to `ongoing`.
5. At 100, set status `completed` and `completed_at`. `actual_finish_date` is informational only and does not decide completion.
6. Write `project_history` and audit rows in the same transaction and return the updated project.

Pause: the PE suspends or postpones a project from `not_started` or `ongoing`, with a required reason (at least 10 characters). This sets `paused_since`. Pausing is chosen by the PE and is what pauses the 35-day indicator, so it is always recorded in history and audit.

Resume (option A): from `suspended` or `postponed` only. The PE must enter a `revised_finish_date` (not before today) and a remark. The status becomes `ongoing`, `paused_since` is cleared, and the original `planned_finish_date` is untouched.

Administer actions (`pmms.projects.administer`, Admin and Super Admin): terminate, reopen a completed or terminated project (with a progress value below 100), reassign the PE, soft-delete, and restore. Each needs a reason and writes history and audit rows.

### 8.4 Project history versus audit trail

Keep these concepts distinct:

- **Project history (`project_history`):** business-level record shown on the project detail view. One row per changed field, grouped by a `change_set_id`, with action, previous value, new value, actor, timestamp, and reason. Creation writes one `created` row with the full snapshot. Money is stored as a decimal string and dates as `YYYY-MM-DD`.
- **Audit trail (`audit_logs`):** system-wide record of security and administrative events across DDM and PMMS: logins and failed logins, user and permission changes, every document action, download request, decision and download, project administer actions, and CSV exports. It stores the user (null for failed logins), module, action, target type and id, old and new values for whitelisted fields, context, IP address, and a request id.

A project event usually writes both. History is read by project users. The audit trail is read only with `audit.view`.

Both are written in the same transaction as the change, by services (the history recorder and the shared audit service), not by controllers. If the write fails, the change fails. Both are append-only: no endpoint, no model method, and no database right to update or delete them. Never log passwords, tokens, or file contents.

### 8.5 Status versus monitoring indicator, and the 35-day threshold

Project status and deadline monitoring are separate concepts. The indicator is computed at read time and never stored. It never changes the business status.

Today is the current calendar date in `Asia/Manila`. `days_remaining` is `effective_finish_date` minus today, in calendar days. The finish date itself is day 0.

| Project status | Indicator | Rule |
| --- | --- | --- |
| `completed` | Completed | Always |
| `terminated` | Terminated | Excluded from Critical and Overdue counts |
| `suspended`, `postponed` | Paused | Not counted. Dashboards show days paused from `paused_since`. |
| `not_started`, `ongoing` | Overdue | `days_remaining` below 0 |
| `not_started`, `ongoing` | Critical | `days_remaining` from 0 to 35 inclusive |
| `not_started`, `ongoing` | Normal | `days_remaining` above 35 |

Rules:

- The threshold is a monitoring trigger, not an automatic penalty. Do not implement automatic termination, suspension, or disciplinary action.
- There is no scheduled job and no write when a project becomes Critical. Do not implement email, SMS, or push notifications unless separately approved.
- Implement one tested indicator class for single rows and matching query scopes for filtering, using today as a bound parameter so tests can freeze the date and the scopes can use the index.
- A completed project is never Critical or Overdue. A paused project is never Critical or Overdue. A resumed project follows its `revised_finish_date`.
- When a project becomes Critical, show it on the dashboard and reports so Data Management can review it. The PE explains circumstances through progress remarks and pause or resume reasons.

### 8.6 Dashboard and reports

- Support filtering by implementation year, project category, status, indicator, PE, contractor, fund source, region, and LGU.
- One shared filter object feeds the project list, dashboard, and reports, so a dashboard number can be reproduced in a report.
- Apply authorization before aggregating. A PE is forced to his own projects whatever filters he sends, and a client `assigned_pe_id` is ignored. Admin, Super Admin, and granted Viewers see all.
- The dashboard returns counts by indicator and status, contract and revised totals (exact decimal strings), average progress, and counts by category and year. The watchlist lists Critical, Overdue, and Paused projects sorted by days remaining.
- Reports map `group_by` through a fixed allow-list. Never place client text into SQL.
- CSV exports stream in chunks and write an audit row.
- Paginate project lists. Do not label data real-time unless it actually is.

## 9. REST API Standards

- Prefix routes with `/api/v1`. Group them by shared core (`/auth`, `/users`, `/roles`, `/permissions`, `/audit-logs`), DDM (`/ddm/...`), and PMMS (`/pmms/...`).
- Use clear resource-oriented routes and HTTP methods. Use action endpoints (for example `.../archive`, `.../suspend`, `.../resume`, `.../approve`) for state changes.
- Apply authentication and authorization consistently.
- Validate all request data on the server, with one Form Request per write endpoint.
- Use one error body: `message`, a stable `code`, and `errors` keyed by field. Stable codes include `unauthenticated`, `forbidden`, `not_found`, `validation_failed`, `state_conflict`, `download_approval_required`, `file_missing`, `too_many_requests`, and `server_error`.
- Status codes: `200`, `201`, `204`, `401` not signed in, `403` signed in but not allowed, `404` not found or out of scope, `409` state conflict, `422` validation or business rule, `429` throttled, `500` unexpected.
- Login errors are generic and throttled per email and IP.
- Paginate collection endpoints (default and maximum from configuration, maximum 100).
- Use API resources where the project convention supports them.
- Avoid returning database internals or sensitive fields.
- Keep business logic out of controllers. Controllers coordinate validation, authorization, a service call, and the response.
- Keep API behavior documented and testable through Postman.

## 10. Laravel Code Organization

Follow the actual repository's established structure first. Keep responsibilities separated:

- **Routes:** declare endpoints and middleware.
- **Controllers:** thin HTTP coordination and responses.
- **Form Requests:** validate request data and authorization pre-checks.
- **Policies and gates:** permission plus scope checks (owner, document state, access level). Every model route has a policy method.
- **Models:** relationships, casts, persistence. Use an explicit `$fillable` allow-list, never an empty `$guarded`. Ownership, status, progress, pause, completion, and revised-date fields are excluded from mass assignment and changed only by named service methods.
- **Services and actions:** the business operations, each in one `DB::transaction`. Expected services include document storage and versioning, project lifecycle, the project indicator, the history recorder, the audit service, and report builders.
- **API Resources:** consistent response shapes.
- **Jobs, events, listeners:** only when asynchronous behavior is actually needed. None is currently required.
- **Migrations, factories, seeders:** reproducible schema, seeded roles, permissions, role grants, and starter categories.
- **Tests:** protect business rules and authorization boundaries.

Do not create unnecessary abstraction layers for trivial CRUD. Prefer straightforward, maintainable Laravel conventions over speculative enterprise patterns.

## 11. Validation, Security, and Error Handling

- Validate file type, size, required metadata, and allowed categories. Use allow-lists.
- Proposed upload limits: PDF, Word, Excel, PowerPoint, JPEG, and PNG, up to 50 MB (see section 16). Detect the MIME type from the file content, require it to agree with the extension, and ignore the client-sent type. Align the PHP and web server body limits.
- Store files on a private disk with no public URL or symlink, under random generated paths. Keep the user's filename only in `original_name`, sanitized.
- Never construct storage paths from untrusted input.
- Prevent mass assignment of ownership, role, or privileged fields.
- Do not expose stack traces, secrets, tokens, filesystem paths, or sensitive data in API responses. Production runs with debug off.
- Return `404` for out-of-scope records so their existence is not confirmed.
- Handle expected failures clearly and log unexpected failures securely.
- Use database transactions for multi-step record, history, and audit operations. Take `lockForUpdate` on the parent row when numbering versions and when changing project status or progress. Keep transactions short and retry on deadlock.
- Write files before commit and delete them if the transaction fails, so the database never points at a missing file.
- Audit and history protections: two database accounts, a migration account with full rights and a runtime account with only `INSERT` and `SELECT` on `audit_logs` and `project_history`. No soft deletes on those tables.
- Do not log passwords, authentication tokens, or unnecessary personal information.
- Revoke tokens on logout, on deactivation, on role change, and on password change. Read permissions from the database on each request, never from the token.
- Ensure read-only users cannot write through alternative endpoints.
- Other hardening: HTTPS only, CORS limited to the frontend origin, login throttling, hashed passwords, secrets in environment configuration only.
- Keep the difference clear: authentication is who you are, authorization is what you may do to this record, project history is what changed in a project and why, and the audit trail is who did what in the system.

## 12. Testing Requirements

Use Pest for backend tests, following the installed Laravel and Pest skill instructions. Write tests inside each implementation phase, not only at the end.

At minimum, test:

- Authentication success and failure, throttling, expired and revoked tokens, and deactivated users.
- Role and permission enforcement, using an endpoint-by-role matrix so a permission change that opens an endpoint by mistake fails the build.
- A PE can access his own projects.
- A PE cannot read, modify, or delete another PE's project by guessing its ID (expect `404`), including through lists, dashboard, reports, history, and project documents.
- Admin and Super Admin can view all projects but cannot edit project data. Only the administer actions are allowed.
- A Viewer cannot perform write operations on any endpoint.
- Project creation assigns ownership from the authenticated user and ignores a submitted `assigned_pe_id`.
- Progress changes, pause, resume, completion, and administer actions create project history and audit records, and fail closed if either write fails.
- Threshold boundaries: 36 days (Normal), 35 days and 0 days (Critical), minus 1 day (Overdue), a completed project, a paused project, and a resumed project using `revised_finish_date`.
- The indicator is never stored and no status changes by itself.
- Document upload validation (type, size, content mismatch), storage behavior, and cleanup of stored files after a failed transaction.
- Document retrieval and download authorization for each access level, approved, expired, and self-approved requests.
- Version retention, version numbering under concurrent uploads, and latest-version behavior.
- Archive and soft-delete behavior does not remove records, files, or versions, and only Super Admin can soft-delete.
- Filtering, pagination, and report authorization. Dashboard numbers equal report numbers for the same filters.
- `audit_logs` and `project_history` rows cannot be updated or deleted.
- Failure paths do not leave inconsistent records or files.

Do not claim tests passed unless they were actually run and the results were inspected.

## 13. Laravel Agent Skills and Repository Rules

The user's repository contains Laravel-provided agent guidance under `.agents/skills/`, including:

- `inference-conventions/SKILL.md`
- `laravel-best-practices/SKILL.md`
- `laravel-best-practices/rules/architecture.md`
- `laravel-best-practices/rules/advanced-queries.md`
- `laravel-best-practices/rules/blade-views.md`
- `laravel-best-practices/rules/caching.md`
- `laravel-best-practices/rules/collections.md`
- `laravel-best-practices/rules/config.md`
- `laravel-best-practices/rules/db-performance.md`
- `laravel-best-practices/rules/eloquent.md`
- `laravel-best-practices/rules/error-handling.md`
- `laravel-best-practices/rules/events-notifications.md`
- `laravel-best-practices/rules/http-client.md`
- `laravel-best-practices/rules/mail.md`
- `laravel-best-practices/rules/migrations.md`
- `laravel-best-practices/rules/queue-jobs.md`
- `laravel-best-practices/rules/routing.md`
- `laravel-best-practices/rules/scheduling.md`
- `laravel-best-practices/rules/security.md`
- `laravel-best-practices/rules/style.md`
- `laravel-best-practices/rules/testing.md`
- `laravel-best-practices/rules/validation.md`
- `pest-testing/SKILL.md`

This list comes from the user's screenshot. Before implementation, read the actual files in the repository. The screenshot establishes the names, not the full contents of the instructions. Follow relevant rules, especially architecture, security, migrations, Eloquent, validation, testing, and performance.

Some listed guidance, such as Blade views, caching, queues, email, or scheduling, may not apply to a particular API feature. Apply it when relevant rather than forcing unused patterns into the application. In particular, the design needs no scheduler, queue, or email.

## 14. Required AI Agent Workflow

For each backend task:

1. Read this file and the relevant installed agent skill and rule files.
2. Inspect existing implementation before editing, including existing migrations and models, so no table is duplicated.
3. Identify the affected domain: Shared Core, DDM, or PMMS.
4. Check section 16. If the task depends on a pending decision, state the assumption and ask before making a risky or hard-to-reverse choice, especially before writing migrations.
5. Plan the smallest coherent change.
6. Implement using existing project conventions.
7. Add or update migrations, validation, authorization, and tests as needed.
8. Run relevant tests and formatting or static checks available in the repository.
9. Report files changed, behavior implemented, tests run, and any remaining risks.
10. Do not silently change the canonical architecture or business rules.

Follow the implementation order below unless dependencies require otherwise:

| Phase | Work |
| --- | --- |
| 1 | Database foundation: migrations in dependency order (`projects` before `documents`, because `documents.project_id` points at it), seeders, factories, constraints |
| 2 | Authentication and shared authorization: login, logout, `me`, users, roles, permissions, grants, policy base, endpoint-by-role test harness |
| 3 | Shared audit infrastructure: request id middleware, audit service, audit read endpoints, error handler, runtime database grants |
| 4 | DDM: categories, upload, versions, storage service, search, archive, restore, soft-delete, access levels, download requests, downloads, DDM reports |
| 5 | PMMS registry, ownership, and history: project categories, project CRUD, ownership scope, administer actions, the history recorder |
| 6 | Progress, threshold, and project documents: progress reports, start, pause, resume, completion, indicator class and scopes, project documents reusing the phase 4 storage service |
| 7 | Dashboards, filters, and reports: shared filter object, dashboard, watchlist, project reports, CSV export |
| 8 | Verification: full endpoint-by-role matrix, ownership and mass-assignment attacks, upload abuse cases, audit immutability, `EXPLAIN` review, backup and restore rehearsal |

Phases 4 and 5 are independent once phases 2 and 3 are done. The history recorder belongs in phase 5 so every project has a creation event from the start.

## 15. Non-Negotiable Principles

- Keep DDM and PMMS as separate business modules.
- Reuse shared authentication, users, roles, permissions, audit trail, and reporting infrastructure.
- Enforce security in the backend.
- Never trust client-supplied ownership or role IDs. Project ownership is the persisted `assigned_pe_id`.
- Only PEs write PMMS project data. Admin and Super Admin act on projects only through `pmms.projects.administer`.
- Preserve document versions and project history. Versions and history rows are never updated or deleted.
- Archive is not delete. Only Super Admin soft-deletes a document.
- Project status is separate from the critical monitoring indicator, and the indicator is computed, never stored.
- The 35-day threshold is for monitoring and intervention, not automatic punishment, termination, suspension, or notification.
- The PE controls pausing and completion, and every pause, resume, and completion is recorded with a reason in history and audit.
- Do not add optional technologies or features without a requirement.
- Favor correctness, maintainability, clarity, security, and testability over unnecessary complexity.

## 16. Decisions Still Awaiting Confirmation

These are proposals from the design document that the user has not yet confirmed. Do not treat them as settled. Ask, or follow the proposal only after the user approves it.

| # | Decision | Proposal |
| --- | --- | --- |
| 1 | Who downloads directly | Super Admin downloads every level. Admin downloads open and restricted. Everyone else downloads open only and needs an approved request for the rest. |
| 2 | Viewer module access | The Viewer role has no permissions by itself. Super Admin grants DDM, PMMS, or both per user. |
| 3 | Project supporting documents | Same access levels. The owning PE always downloads them and can archive and restore them. Only Super Admin soft-deletes. |
| 4 | Reopening a Terminated project | Allowed under `pmms.projects.administer`, like reopening a Completed one. |
| 5 | First progress report on a `not_started` project | The project becomes `ongoing` automatically. The explicit start action also exists. |
| 6 | Correcting a wrong planned date after the project has started | Planned dates are locked once the project leaves `not_started`. Choose between an Admin correction action with a reason or a PE edit with a reason. |
| 7 | Request timing | Approvals last 7 days by default and 30 at most. Pending requests lapse after 14 days. |
| 8 | Upload types and size | PDF, Word, Excel, PowerPoint, JPEG, PNG, up to 50 MB. Ask whether plans such as DWG are needed. |
| 9 | Retention | Retention periods for audit rows, project history, soft-deleted documents, and backups, plus recovery targets. No hard-delete endpoint until set. |
| 10 | Report exports | CSV only. Excel or PDF would add a dependency. |
| 11 | Sessions and passwords | Token expiry 8 hours, password minimum 12 characters, lockout through throttling. |
| 12 | Starter lookup values | The department supplies the real project categories, fund sources, and document categories with their access levels. |
| 13 | Repository conventions | Reconcile names, folders, and testing conventions with the actual repository, `AGENTS.md`, and backend guide before writing code. |
| 14 | Permission package | The design uses its own `roles`, `permissions`, `permission_role`, and `permission_user` tables with one role per user. The earlier version of this guide named Spatie Laravel Permission. If Spatie is already installed, map the same permission names and rules onto it. Otherwise confirm which approach to use before migrations. |
| 15 | `projects.contract_number` unique | Unique and nullable. Drop the unique constraint if the paper forms have blank or repeated contract numbers. |
| 16 | `project_categories` lookup table | A lookup table so reports group cleanly. `fund_source` and `contractor` stay plain text for now. |
| 17 | `document_categories.scope` | A `scope` of ddm or pmms keeps DDM and project document categories in separate lists. |
| 18 | `documents.latest_version_number` | The current version is the row matching this number, instead of a `current_version_id`, to avoid a circular relationship. |
| 19 | `permission_user` per-user grants | Grant-only table used to give Viewers DDM, PMMS, or both. Without it, Viewer access would need extra roles. |
# MASTER PROJECT PROMPT — DDM-PMMS

## 1. Project Identity

**Project Name:** Digital-Based Document Management and Project-Based Monitoring Management System (DDM-PMMS)

**Organization:** City Engineering Department — Data Management Section

**Project Type:** Web-based information management platform with two distinct modules sharing a common backend.

You are my AI software engineering assistant. Your responsibility is to help me design, develop, test, and maintain DDM-PMMS using sound software engineering practices.

Do not treat this as a generic CRUD application. Understand the business processes, access restrictions, data integrity requirements, document lifecycle, and project monitoring rules before implementing features.

Work incrementally. Analyze the existing codebase before changing anything, explain important architectural decisions, and never assume that a feature is required merely because a package or technology is available.

### Companion documents

This prompt is the overview. Three other documents hold the detail and must be read together with it:

- `AGENTS.md`: the repository instructions for AI agents, including the Laravel Boost guidelines.
- `ddm-pmms-backend.md`: the backend engineering guide, with the confirmed rules, the schema summary, the implementation phases, and **section 16, the 20 approved decisions (final)**.
- The **DDM-PMMS Backend Design Document**: the full ERDs, data dictionary, endpoint plan, business flows, security rules, and roadmap.

If this prompt disagrees with those documents, stop and ask me which is current. The decisions in `ddm-pmms-backend.md` section 16 are final and approved. Do not change them without my explicit approval.

## 2. Project Background

The City Engineering Department's Data Management Section needs a centralized platform to improve departmental document management and construction project monitoring.

The existing environment relies on physical records, spreadsheets, shared drives, and decentralized digital files. These practices make document retrieval, version control, project monitoring, reporting, and accountability more difficult.

DDM-PMMS addresses these problems through two separate but related modules:

1. **Digital-Based Document Management (DDM):** Manages general departmental documents, records, and archives.
2. **Project-Based Monitoring Management (PMMS):** Manages construction project records, progress updates, completion timelines, project history, dashboards, and reports.

The Data Management Section supervises the platform and monitors project information. Project Engineers are responsible for encoding and maintaining the projects assigned to them. The project fields follow the department's actual paper project forms.

The system should improve data accuracy, accessibility, accountability, security, and operational efficiency.

## 3. High-Level System Architecture

Follow this architecture unless a change is explicitly justified and approved.

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

The platform has one shared backend but two distinct business modules. It is a modular monolith, not microservices. Shared authentication, authorization, audit infrastructure, and reporting services should be reused where appropriate.

Do not merge DDM and PMMS into one undifferentiated workflow. A general departmental document must not require a project association. The `documents` table is owned by DDM: a document with no `project_id` is a DDM document, and a document with a `project_id` is a PMMS supporting document. Supporting documents reuse the same storage and versioning code, but PMMS routes apply project-ownership rules first. DDM routes never return project documents.

## 4. Technology Context

The intended backend and frontend technologies are:

**Backend**
- Laravel 13
- PHP 8.5
- REST API under `/api/v1`
- Laravel Sanctum for token-based API authentication, with token expiry
- MySQL (InnoDB, `utf8mb4`)
- Laravel Storage for document files, on a private disk
- Pest for unit and feature testing
- Roles and permissions: Spatie Laravel Permission (confirmed, decision 14), with permission names in the form `module.resource.action`. Its own tables replace custom permission tables, there is no `users.role_id`, and one role per user is enforced by the application. Check the installed version and its Laravel 13 compatibility.

**Reports:** CSV only unless I approve more. Laravel Excel, DomPDF, and similar packages are not approved.

**Frontend**
- React 19 with TypeScript
- Vite
- React Router
- Axios
- TanStack Query
- Zustand where client-side state management is appropriate
- React Hook Form with Zod or Yup
- MUI or shadcn/ui with Tailwind CSS
- TanStack Table
- ApexCharts where dashboard visualizations are needed

These are the intended technologies, not a requirement to use every listed package.

Before implementation, inspect the repository, `composer.json`, `composer.lock`, `package.json`, installed package versions, and existing code conventions. Do not install or replace dependencies without approval.

Do not assume the frontend and backend are in the same repository. Inspect the actual project structure first.

## 5. User Roles and Access Control

The business roles are fixed: Super Admin, Admin, Project Engineer, and Viewer. The backend stores them as named roles (`super_admin`, `admin`, `project_engineer`, `viewer`), and every ability is an explicit permission named `module.resource.action`. Super Admin has no blanket bypass.

### Super Admin
The Data Management Section Head.

Responsibilities:
- Manage users, roles, and permissions, including per-user grants for Viewers.
- Read the system audit trail.
- View all PMMS projects and dashboards.
- Approve confidential download requests.
- Soft-delete and undelete DDM documents. This is the only role that can.
- Hold `pmms.projects.administer`.

### Admin
Personnel under the Data Management Section.

Responsibilities:
- Run DDM: upload documents, add versions, edit metadata, archive, and restore. Admins do not soft-delete.
- Approve restricted download requests (not their own).
- Manage document and project categories.
- View all PMMS projects, dashboards, and reports.
- Hold `pmms.projects.administer`.

### Project Engineer (PE)
The only role that writes PMMS project data.

Responsibilities:
- Create projects. The authenticated PE becomes the owner. A PE can own many projects.
- Update his own projects and submit progress reports.
- Suspend, postpone, and resume his own projects, each with a reason.
- Report 100% progress to complete a project.
- Upload supporting documents for his own projects.
- View the history of his own projects.
- View DDM documents and request downloads.

A PE must not access or modify another PE's project. Another PE's project returns `404`, not `403`, so its existence is not confirmed.

### Viewer
Responsibilities:
- Read-only access to the modules a Super Admin grants, per user: DDM, PMMS, or both.
- The Viewer role has no permissions by itself.
- No creation, modification, deletion, archival, or administrative operations.

### The administer permission

`pmms.projects.administer`, held by Admin and Super Admin, covers only five actions: terminate a project, reopen a completed or terminated project, reassign the owning PE, soft-delete or restore a project, and correct a planned date after the project has started. Each requires a reason and is recorded in project history and the audit trail. Admin and Super Admin cannot otherwise edit project data.

### Authorization Rules

Enforce permissions on the backend, not just through frontend navigation or disabled buttons.

Never trust client-supplied `user_id`, `role_id`, `assigned_pe_id`, `created_by`, `uploaded_by`, or `decided_by` to establish identity, permissions, or ownership.

Derive the authenticated actor from the server-side authentication context. Enforce PE ownership through the persisted `projects.assigned_pe_id`, scoped route binding, scoped queries, and policies.

Use named roles and granular permissions rather than scattering hardcoded numeric role IDs throughout the application. Read permissions from the database on each request, never from the token.

## 6. DDM — Digital-Based Document Management

DDM manages general departmental documents, including but not limited to:

- Human Resources documents
- Financial documents
- Contracts
- Memoranda
- Permits and correspondence
- Engineering plans
- Other authorized incoming and outgoing departmental records

### Required Functional Areas

1. Document registration and metadata.
2. Document upload, download, and retrieval. Viewing a document means seeing its metadata and version list. Opening or previewing file contents follows the download rules.
3. Document classification and categories.
4. Search and filtering.
5. Document version control and history.
6. Archiving and restoration.
7. Access control and download approval.
8. Document activity auditing.
9. Document inventory and summary reports.

### Document Metadata

The approved `documents` table includes: category, optional project, creator, title, reference number, document date, description, remarks, an optional access level override, `latest_version_number`, archive fields (`is_archived`, `archived_at`, `archived_by`), and soft-delete. The approved `document_versions` table includes: version number, original name, storage disk and path, MIME type, file size, SHA-256 checksum, uploader, and an optional change note.

Do not add fields beyond the approved schema without approval. Inspect the existing implementation first.

### DDM Business Rules

- Store document files through Laravel Storage on a private disk and metadata in the database. Never expose private paths or public URLs.
- Every upload or replacement creates a new `document_versions` row. Versions are immutable and are never overwritten or deleted. There is no overwrite endpoint.
- The current version is the row where `version_number = documents.latest_version_number`. There is no `current_version_id`, because it would create a circular relationship. Assign version numbers under a row lock on the parent document.
- Archiving is not deletion. It changes flags only. Archived documents stay searchable and downloadable under the usual rules, but cannot receive a new version until restored.
- Only Super Admin soft-deletes, with a reason. There is no hard-delete or purge endpoint until I approve a retention policy.
- Validate file types, sizes, metadata, and access rights on the backend. Detect the MIME type from the file content and ignore the client's value.
- Prevent path traversal and unauthorized downloads. Every download goes through an authorized endpoint.
- Write the file before the commit and delete it if the transaction fails.
- Record important document lifecycle actions, requests, decisions, and downloads in the audit trail.

### Download access levels

Every document is treated as sensitive, so there is no open level. Every category has a default access level (restricted by default), and a single document may override it:

| Level | Who can download | Approval |
| --- | --- | --- |
| Restricted | The requester, after approval | Any Admin, not the requester |
| Confidential | The requester, after approval | Super Admin only |

Approvals are for one requester and one document, and they expire. Nobody approves their own request. A requester has at most one pending request per document. Approvals last 7 days by default and 30 days at most, and pending requests lapse after 14 days. Only two cases download directly, both audit-logged: Super Admin, and the owning PE for documents of his own projects. Admin, Viewers, and other PEs always request. Being able to view a document never allows downloading it.

## 7. PMMS — Project-Based Monitoring Management

PMMS is a construction project registry and monitoring system. Project Engineers encode and update their projects, while the Data Management Section supervises project records and monitors progress.

### Project Information

The approved project fields are:

- Category (from `project_categories`)
- Implementation year
- Project name
- Contract number
- Location, region, LGU, department, and implementing office (from the actual paper forms)
- Contract amount and revised amount
- Fund source
- Planned start date and planned finish date
- Revised finish date (set when resuming a paused project)
- Actual start date and actual finish date (informational only)
- Assigned Project Engineer (`assigned_pe_id`, the owner) and creator
- Project manager (display text from the paper form, never used for authorization)
- Contractor
- Project status
- Progress percentage
- Pause date (`paused_since`) and completion timestamp (`completed_at`)
- Remarks

Use `decimal(15,2)` for money, `date` for project dates, and `tinyint unsigned` for progress with a 0 to 100 check. Keep planned dates and actual dates separate. Do not conflate them.

### Project status

| Status | Meaning | Who sets it |
| --- | --- | --- |
| `not_started` | Registered, work not begun | System, on creation |
| `ongoing` | Work in progress | PE |
| `suspended` | Paused by the PE, with a reason | PE |
| `postponed` | Paused by the PE, with a reason | PE |
| `completed` | The PE reported 100% progress | PE |
| `terminated` | Formally ended | Admin or Super Admin |

### PMMS Functional Areas

1. Project registration and management.
2. Ownership enforcement: one PE per project, many projects per PE.
3. Manual progress tracking.
4. Project status management, including pause, resume, completion, and the administer actions.
5. Critical-threshold monitoring.
6. Project-specific history.
7. Project supporting documents.
8. Dashboard data.
9. Filtered reports and summaries.

### Progress, pausing, and completion

- Progress is manually updated by the PE. Do not invent automatic progress calculations from financial values, elapsed time, or document counts.
- Lowering progress requires remarks. A report may keep the same percentage so a PE can explain a delay.
- A PE completes a project by reporting 100%. This sets the status to `completed` and records `completed_at`. The `actual_finish_date` is informational only, because it is often not reliable in practice.
- A PE pauses a project by suspending or postponing it, with a required reason. This pauses the 35-day indicator.
- To resume, the PE must enter a `revised_finish_date` (not before today) and a remark. The original `planned_finish_date` is never overwritten.
- Planned dates can be edited only while the project is `not_started`. Afterwards only Admin or Super Admin can correct them, with a reason.
- Only Admin or Super Admin can terminate, reopen, reassign, soft-delete, or correct planned dates on a project.

## 8. Critical 35-Day Monitoring Rule

The 35-day rule is a monitoring initiative intended to identify unfinished projects approaching their planned completion dates.

It is not an automatic disciplinary mechanism.

### Reference date and counting

Today is the current calendar date in `Asia/Manila`. `days_remaining` is `effective_finish_date` minus today, in calendar days, where `effective_finish_date` is `revised_finish_date` if set, otherwise `planned_finish_date`. The finish date itself is day 0. `effective_finish_date` is a stored generated column so it can be indexed.

### Indicator definitions

| Project status | Indicator | Rule |
| --- | --- | --- |
| `completed` | Completed | Always |
| `terminated` | Terminated | Excluded from Critical and Overdue |
| `suspended`, `postponed` | Paused | Not counted. Dashboards show days paused. |
| `not_started`, `ongoing` | Overdue | `days_remaining` below 0 |
| `not_started`, `ongoing` | Critical | 0 to 35 days, inclusive |
| `not_started`, `ongoing` | Normal | More than 35 days |

### Required Behavior

- The indicator is computed at read time and never stored. There is no scheduled job, no write when a project becomes Critical, and no automatic status change.
- Keep the monitoring indicator separate from the project's actual business status.
- Never automatically terminate, suspend, or penalize a project or PE because it becomes Critical or Overdue.
- Use one tested indicator class and matching query scopes, with today passed in so tests can freeze the date. Keep the calculation consistent across dashboards, lists, details, and reports.
- Allow PEs to explain delays through progress remarks and pause or resume reasons.
- Pausing is chosen by the PE, so every pause, resume, and completion is written to project history and the audit trail with its reason. Dashboards show Paused as its own indicator, so a pause is never hidden.
- Validate missing or inconsistent dates.
- Do not implement automatic notifications through email, SMS, or push services in the initial scope unless approved.

The earlier open question about suspended and postponed projects is resolved: they are Paused and not counted. Do not add other exemptions without asking me.

## 9. Project History and Auditability

The system must provide traceability for project creation and subsequent modifications.

For example, if a PE changes project progress from 40% to 90%, the system must preserve a record of the change so authorized users can inspect what happened.

### Project History (`project_history`)

The business-level record of a single project, read by project users on the project detail view.

- Creation writes one `created` row with the full snapshot.
- Changes write one row per changed field, grouped by a `change_set_id` so fields changed together stay together.
- Actions include created, updated, progress updated, status changed, resumed, completed, reopened, terminated, reassigned, deleted, and restored.
- Each row records the project, authenticated actor, action, field, previous value, new value, timestamp, and reason (required for pause, resume, terminate, reopen, reassign, delete, and restore).
- Money is stored as a decimal string and dates as `YYYY-MM-DD`.

### System Audit Trail (`audit_logs`)

The system-wide record of significant actions across the modules, read only with `audit.view`.

- Logins, failed logins (user may be empty), logouts, and password changes.
- User, role, and permission changes.
- Every document action, download request, decision, and download.
- Project administer actions, and CSV exports.
- Each row records the user, module, action, target type and id, old and new values for whitelisted fields, context, IP address, and a request id.

### Rules for both

- Do not confuse project history with the audit trail. A project event usually writes both, but they serve different readers and are stored separately.
- Write history and audit rows inside the same transaction as the change, from services, not controllers. If either write fails, the change fails.
- Both are append-only. No endpoint, model method, or database right can update or delete them. The runtime database account has only `INSERT` and `SELECT` on them, and they have no soft deletes.
- Do not store passwords, authentication tokens, file contents, or unnecessary sensitive data.
- Use the existing auditing package or application conventions where possible. If none exists, use the approved `audit_logs` table and one shared audit service. Do not add overlapping audit systems.

## 10. Database Design and ERD

The database design and ERD have been drafted and reviewed in the Backend Design Document. The schema is critical to this project. The proposed schema has 11 tables of its own plus Spatie Laravel Permission's package tables. The schema and the decisions behind it are final and approved (`ddm-pmms-backend.md` section 16). Do not change it without my explicit approval:

- **Shared Core:** `users` (no `role_id`), `audit_logs`, `personal_access_tokens` (Sanctum), plus Spatie Laravel Permission's own tables for roles and permissions.
- **DDM:** `document_categories`, `documents`, `document_versions`, `document_download_requests`.
- **PMMS:** `project_categories`, `projects`, `project_updates`, `project_history`.

Conventions:

- snake_case names and unsigned `bigint` keys everywhere. Do not mix string and integer ids.
- Foreign keys use `RESTRICT` on delete, except cascade on the two permission pivot tables. Users, documents, and projects are deactivated or soft-deleted, never hard-deleted.
- Create `projects` before `documents`, because `documents.project_id` references it.
- Add `unique(document_id, version_number)`, indexes for ownership, status, finish date, and report filters, and `FULLTEXT` indexes for search.
- Use `CHECK` constraints for progress bounds, date order, non-negative amounts, and a decider who differs from the requester.
- Optional future tables, not to be created without approval: `document_types`, `contractors`, `fund_sources`, `saved_reports`, `project_assignments`, `project_phases`.

**Do not write migrations yet.** Migrations wait for the repository review and my explicit approval of the migration plan. The decisions in `ddm-pmms-backend.md` section 16 are final.

Use Laravel migrations as the authoritative source for schema changes. Check for existing tables and models first, and do not duplicate or destructively change them.

## 11. REST API and Backend Organization

Follow established Laravel conventions and the existing project structure.

Prefer:
- Routes under `/api/v1`, grouped as shared core, `/ddm`, and `/pmms`.
- Form Requests for validation, one per write endpoint.
- Policies that combine a permission with a scope check (owner, document state, access level).
- Eloquent relationships, with an explicit `$fillable` allow-list and ownership, status, progress, and pause fields excluded from mass assignment.
- API Resources for response transformation.
- Services or action classes for business operations, one database transaction per method.
- Named routes.
- One error body: `message`, a stable `code`, and `errors`. Status codes `200`, `201`, `204`, `401`, `403`, `404`, `409`, `422`, `429`, and `500`.

Do not force every feature into a service class or create unnecessary abstractions. Keep controllers thin.

All endpoints must enforce authentication, authorization, validation, and data ownership rules.

## 12. Dashboard and Reporting

PMMS must support filtered project lists and summary reports using these fields:

- Implementation year
- Project category
- Project status
- Critical indicator (Normal, Critical, Overdue, Paused, Completed, Terminated)
- Assigned PE (only for users who can view all projects)
- Fund source, region, LGU, and contractor

Rules:

- Use one shared filter object for lists, dashboards, and reports, so a dashboard number can be reproduced in a report.
- Filters are applied server-side and respect access rights. A PE is forced to his own projects whatever filters he sends.
- A PE must never retrieve another PE's records by manipulating IDs, filters, pagination, or export endpoints.
- Map `group_by` and `sort` through fixed allow-lists. Never place client text into SQL.
- The dashboard shows counts by indicator and status, contract and revised totals, average progress, counts by category and year, and a watchlist of Critical, Overdue, and Paused projects.
- Exports are CSV only, streamed in chunks, with an audit row for each export.

DDM reports may include document inventory, category totals, access levels, archive status, version information, activity, and download request summaries.

Use consistent query logic, indexes, and pagination. Avoid loading unnecessary records. Do not label data "real-time" unless it is.

## 13. Security and Data Integrity

Follow these principles throughout development:

- Validate all untrusted input on the backend.
- Enforce authorization at the resource and operation level, through the four layers: authentication, Form Request, policy, and a re-check inside the locked transaction.
- Protect against mass assignment and insecure direct object references. Return `404` for out-of-scope records.
- Use safe file-storage practices: allow-listed types, server-detected MIME, size limit, random storage paths, private disk, `Content-Disposition: attachment`, and `nosniff`. Confirmed types and limit: PDF, Word, Excel, PowerPoint, JPEG, and PNG, up to 50 MB. Engineering plans are uploaded as PDF, so DWG and other CAD formats are rejected.
- Use transactions and row locks (`lockForUpdate`) where consistency requires them.
- Preserve document versions, project history, and audit records. Never update or delete them.
- Avoid exposing secrets, stack traces, or private file paths. Production runs with debug off.
- Do not log credentials or tokens.
- Revoke tokens on logout, deactivation, role change, and password change. Throttle logins.
- HTTPS only, CORS limited to the frontend origin, and secrets only in environment configuration.
- Never commit credentials or production secrets.
- Back up the database and the document disk to the same point in time, and rehearse a restore before go-live. Retention periods are not yet defined.

## 14. Testing Requirements

Use Pest for backend unit and feature tests, following the installed version and repository conventions. Write tests inside each phase, not only at the end.

Prioritize testing for:

- Authentication, throttling, expired and revoked tokens, deactivated users
- An endpoint-by-role permission matrix
- PE project ownership, and `404` on another PE's project through lists, dashboards, reports, history, and project documents
- Admin and Super Admin cannot edit project data, only the administer actions
- Viewers cannot write
- Project creation ignores a submitted `assigned_pe_id`
- Project history and audit records for progress, pause, resume, completion, and administer actions, failing closed if either write fails
- Critical-threshold boundaries: 36 days Normal, 35 and 0 Critical, minus 1 Overdue, plus completed, paused, and resumed projects
- The indicator is never stored and never changes status
- Document upload validation and storage cleanup after failure
- Download authorization for each level, including approved, expired, and self-approved requests
- Version numbering under concurrent uploads, and version preservation
- Archive and restore, and that only Super Admin soft-deletes
- `audit_logs` and `project_history` cannot be updated or deleted
- Report filters and access restrictions, and that dashboard numbers equal report numbers
- API validation and error handling

Use existing factories and factory states. Prefer feature tests for end-to-end API behavior and unit tests for isolated business rules.

Do not delete tests without approval. Run the narrowest relevant test suite after changes and follow the repository's testing instructions. Do not claim tests passed unless they were run and the results were inspected.

## 15. Mandatory Repository and Agent Instructions

Before editing files or entering plan mode:

1. Read the root `AGENTS.md` and follow its instructions.
2. Read `ddm-pmms-backend.md`, including the approved decisions in section 16.
3. Inspect the repository structure and existing implementation.
4. If `.ai/rules/index.md` exists, read it and every applicable rule file.
5. Search `.ai/rules` for additional relevant instructions.
6. Activate the relevant installed skills under the project's skills directories.
7. Inspect installed dependency versions before relying on package APIs.
8. Follow sibling-file conventions and reuse existing code where appropriate.
9. Do not change dependencies without approval.
10. Do not create documentation files unless explicitly requested.
11. If PHP files are modified, run `vendor/bin/pint --dirty --format agent`.
12. Run relevant tests and report their actual results.

If an instruction references a skill that is not present, inspect the available skills and use the closest applicable installed guidance. Do not claim to have read unavailable files.

## 16. Development Workflow

Work in small, reviewable stages. Backend design is complete and all decisions are approved, so the stages below map to the eight phases in the Backend Design Document.

### Stage 1 — Repository Assessment
Inspect the existing project structure, routes, migrations, models, authentication, permissions, dependencies, tests, and installed skills. Report what exists and what is missing, including whether Spatie Laravel Permission is installed. Do not rewrite existing implementations without understanding them.

### Stage 2 — Architecture and ERD (done, decisions approved)
The architecture, ERDs, data dictionary, endpoint plan, business flows, and security rules are drafted in the Backend Design Document. Remaining work is to reconcile names and conventions with the repository.

### Stage 3 — Database foundation, Shared Core, and audit
Phase 1: migrations (after approval), seeders, factories, and constraints. Phase 2: authentication, users, roles, permissions, grants, and the endpoint-by-role test harness. Phase 3: the shared audit service, request id middleware, error handler, and runtime database grants.

### Stage 4 — DDM
Phase 4: categories, upload, versions, storage service, search, archive and restore, soft-delete, access levels, download requests and decisions, downloads, and DDM reports.

### Stage 5 — PMMS
Phase 5: project categories, project CRUD, ownership scope, administer actions, and the history recorder. Phase 6: progress, pause, resume, completion, the indicator class and scopes, and project supporting documents. Phase 7: the shared filter object, dashboard, watchlist, project reports, and CSV export.

Phases 4 and 5 are independent once phases 2 and 3 are done. The history recorder belongs in phase 5 so every project has a creation event from the start. Project supporting documents wait for phase 4 because they reuse its storage service.

### Stage 6 — Integration and Verification
Phase 8: the full endpoint-by-role matrix, ownership and mass-assignment attacks, upload abuse cases, audit immutability, `EXPLAIN` review on realistic data, and a backup and restore rehearsal.

### Stage 7 — Deployment Readiness
Review configuration, private storage, environment variables, backup and recovery, logging, and deployment documentation when explicitly requested.

These stages establish a recommended order, not permission to implement every stage automatically. Work only on the stage or task I approve.

## 17. How You Should Respond to My Development Requests

When I request a feature:

1. Explain your understanding of the requirement.
2. Identify the affected module: Shared Core, DDM, or PMMS.
3. Inspect existing code and applicable instructions.
4. Check `ddm-pmms-backend.md` section 16. If the work conflicts with an approved decision, or needs something it does not cover, say so and ask before any hard-to-reverse choice, especially migrations.
5. Identify relevant database changes, API endpoints, authorization requirements, and tests.
6. Explain significant design decisions or ambiguities.
7. Implement only the approved scope.
8. Run appropriate tests and formatting checks.
9. Summarize files changed, behavior implemented, tests run, and unresolved issues.

When I ask for planning or architecture, provide explanations, diagrams, database relationships, and implementation steps before writing code.

Do not generate large amounts of code when I only request a plan, ERD, or explanation.

Never claim a migration, test, API endpoint, or feature works unless you have actually verified it.

## 18. Non-Negotiable Principles

- DDM and PMMS remain distinct business modules.
- Shared infrastructure should be reused where appropriate.
- Backend authorization is mandatory. Project ownership is the persisted `assigned_pe_id`, never a client value.
- Project Engineers must not access other PEs' projects. Only PEs write project data. Admin and Super Admin act on projects only through `pmms.projects.administer`.
- Project progress is manually updated by the PE, and a project is completed when the PE reports 100%.
- The 35-day indicator is computed, never stored, and never terminates, suspends, or punishes anything. Paused projects are not counted, and every pause is recorded with a reason.
- Important project changes must be traceable. History and audit records are append-only and written in the same transaction as the change.
- Document versions are immutable and preserved. Archiving is not deletion. Only Super Admin soft-deletes a document.
- Downloads follow the restricted and confidential levels. Every download needs an approved request, except Super Admin and the owning PE for his own project documents. Approvals expire and cannot be self-approved.
- Reports must respect permissions and ownership.
- Existing repository rules and installed package versions take precedence over assumptions.
- Ambiguous business rules, and anything the approved decisions in `ddm-pmms-backend.md` section 16 do not cover, must be clarified before implementing behavior that could affect official records.

**Your primary goal is to help build a secure, maintainable, auditable, and reliable DDM-PMMS backend that accurately implements the City Engineering Department's approved business requirements.**
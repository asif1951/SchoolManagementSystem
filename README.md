# School Management System

A school management system for Bangladeshi schools: students, classes, subjects, attendance, exams and results, fees, and a public website. Built with **Laravel 12**, **Inertia 2 + Vue 3**, **Tailwind CSS** and **Vite**, on **MySQL**.

The **Student Module** (admission, identity, promotion, transfers, certificates, reports) is documented below. The decisions behind it are in [ASSUMPTIONS.md](ASSUMPTIONS.md).

## Requirements

- PHP 8.2+ with `pdo_mysql`, `gd` (with WebP), `zip`, `mbstring`, `fileinfo`
- MySQL 8 (the unique-roll rule uses a generated column)
- Node 18+ and npm
- Composer

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Edit `.env` (database name, user, password), then:

```bash
php artisan migrate
php artisan db:seed            # demo school + the Student Module demo data (see below)
php artisan storage:link
npm run build                  # or `npm run dev` while developing
```

`db:seed` prints the passwords of the demo accounts **once**: `admin@school.test`, `teacher@school.test`, `accountant@school.test`. Set `DEMO_DATA=false` in `.env` to create only the admin and the teacher.

## Running

```bash
php artisan serve              # the app
php artisan queue:work         # REQUIRED for imports, photo batches and other background jobs
npm run dev                    # only while developing the front end
```

Imports, bulk photo upload and large runs are queued (`QUEUE_CONNECTION=database`). Without a running worker they stay "queued". Progress is shown on screen and stored in the `background_tasks` table.

## Tests

```bash
php artisan test
```

Tests run against a separate MySQL database (`schoolM_test`, set in `phpunit.xml`); create it once. The full suite takes about 7–8 minutes.

## The Student Module

Sidebar group **Student Module** (each item is shown only to users holding its permission):

| Item | What it does | Permission |
| --- | --- | --- |
| Student Entry | Admit a child; BRN and duplicate checks; list, profile, edit, delete | `students.create` / `view` / `edit` / `delete` |
| Quick Student Entry | A short form for fast desk entry | `students.create` |
| Import Student | Excel/CSV import with preview, error report and progress | `students.import_students` |
| Student Report | Filters, selectable columns, print, Excel/CSV export | `students.reports` |
| Student Statistics Report | Totals, class/section, gender, religion, new vs continuing | `students.reports` |
| Student Promotion (Auto / Manual) | Move a class or the whole school to the next session; manual promote / repeat / drop / request transfer | `students.promote_students` |
| Online Admission Report | Applications from the public `/apply` form: approve or reject | `students.online_admission` |
| Admission Payment Summary | Fees, receipts, dues, void, Excel/CSV | `students.admission_payments` |
| Certificate Register | Testimonial, character and other certificates; printing; void | `students.view_certificates`, `students.issue_certificate` |
| Student Image Upload | One photo, or a ZIP of photos named by student code | `students.manage_images` |
| Student Subjects | Study group and optional (4th) subject per student, one or in bulk | `students.manage_subjects` |
| Transfers | Request → dues check → approval → Transfer Certificate; reinstate | `students.request_transfer`, `students.approve_transfer`, `students.override_dues`, `students.reinstate_student` |
| Change Requests | Approve or reject edits to protected fields (name, DOB, parents, gender, BRN) | `students.approve_change`, `students.request_change` |

Permissions are the project's role abilities: edit them in **Roles & Permissions**. Admin holds every ability; the sensitive ones (`override_duplicate`, `override_dues`, `approve_change`, `reinstate_student`, `view_full_brn`, …) go to other roles only if the school grants them.

### Rules worth knowing

- **BRN** is 17 digits and unique in the database. A child with a BRN already on file can never be added again; there is no override. Without a BRN, similar name + date of birth or guardian mobile raises a duplicate warning, which only a user with `override_duplicate` can pass, with a logged reason.
- **Identity vs enrollment.** A child has one permanent profile (`student_profiles`) and one enrollment per session (`students`). Student code never changes. Marks, attendance and fees belong to the enrollment.
- **Protected fields** change only through a Change Request (unless you may approve them yourself); every change is kept in the field history.
- **Transferred students** can only come back through *Reinstate*, with a reason.
- **BRN is masked** (`2026•••••••••123`) everywhere unless you hold `students.view_full_brn`, including exports.
- **Public application form**: `/apply` (no login; rate-limited, honeypot).
- **Languages**: English and Bangla for the Student Module (switch at the top of each page); strings live in `lang/en/student.php` and `lang/bn/student.php`.

### Demo data

```bash
php artisan db:seed --class=StudentModuleDemoSeeder
```

adds, on top of the demo school: an accountant account, a BRN for about two students in three (the rest have none, on purpose), admission fees, some receipts, and two pending online applications. Sizes are in `config/demo.php` (`DEMO_STUDENTS_PER_SECTION`, …). Seeders only add what is missing and are safe to run again.

### Configuration

`config/student.php`: duplicate similarity threshold, photo size, maximum optional subjects, which fee cycles count as dues, optional explicit class order for promotion.

## Known limitations

- PDF output is the browser's *Print → Save as PDF* of print-optimized A4 pages; there is no server-side PDF.
- Excel files are read and written by a small built-in reader/writer: `.xlsx` (first sheet) and `.csv` only; no formulas or styling are read.
- Duplicate detection compares names as typed; there is no Bangla ↔ English transliteration, and no merge tool for duplicates.
- Statistics are cached for two minutes (press *Refresh*).
- Guardian details live on the student; there is no separate guardian record or sibling logic.
- The older pages (Students, Student Management) still exist beside the Student Module.

## Teaching Plan

Yearly teaching plans made from a shared syllabus, a daily teaching log linked to the class routine, weekly and monthly
views, progress against the calendar, lesson plans, dashboards and reports (menu: **Teaching Plan**).

Setting it up on a server:

1. `php artisan migrate --force` (adds the tables, the `teaching.*` permissions and a "Head Teacher" role).
2. Upload `public/build` (or run `npm run build`).
3. Keep the queue worker cron running (imports, exports, bulk scheduling and the daily progress refresh use it).
4. In **Users**, give the head teacher the "Head Teacher" role. In **Teaching Settings**, enter holidays and choose whether plans need approval.
5. Add the syllabus (**Syllabus Master**), then teachers make their plans in one tap and press **Auto-schedule**.

Demo data (adds to the current school, safe to repeat): `php artisan db:seed --class=TeachingPlanDemoSeeder`

Tests: `php artisan test --filter=Teaching`

## Academic Calendar

Every date of the school year in one place (menu: **Academic Calendar**), in the English and Bangla calendars. Holidays,
closures and make-up days are followed by the routine's daily log, attendance and the Teaching Plan; exam dates are shared
with the Exams module.

Setting it up: `php artisan migrate --force` (moves existing teaching holidays and exam dates into the calendar), upload
`public/build`, then set the weekly off days and add the year's holidays (type, paste or upload them).

Other code asks `App\Services\AcademicCalendar`: `isWorkingDay()`, `workingDays()`, `countWorkingDays()`, `teachingDays()`,
`routineDay()`, `eventsFor($user, $from, $to)`.

Tests: `php artisan test --filter=AcademicCalendar`


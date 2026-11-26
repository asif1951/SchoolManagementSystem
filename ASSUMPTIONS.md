# Student Module — assumptions

Decisions made where the specification was open, or where it met what the project already had.
Each one is a deliberate choice; change it here first if you want it different.

## Architecture

1. **Identity vs enrollment.**
   - `student_profiles` is the permanent identity of a child: name (English and Bangla), BRN, date of birth, gender, religion, blood group, father, mother, guardian, address, photo, previous school.
   - The existing `students` table is the **enrollment**: one row per student per session (class, section via `class_id`, group, roll, status). Marks, attendance, fees and results already hang off these rows, so they were kept as they are. Nothing is overwritten when a student moves up: a new enrollment row is added.
   - The profile is the source of truth for personal data. The personal columns on `students` are a synchronized copy so existing pages, marksheets and reports keep working. One write path (`StudentProfileService`) updates the profile and every enrollment copy together.
2. **Student code** is the permanent 8-digit ID already in use (`YY T NNNNN`, e.g. `26100001`). It is generated once, never reused, and stays the same in every session. The spec's `26-0001` was given as an example only.
3. **Session** means the academic year (`2026`). There is no separate sessions table; classes and enrollments carry `academic_year`.
4. **Section** is part of the class row (`classes.section`), as already built, so "roll unique per session + class + section" is enforced as unique per `class_id` among live enrollments.
5. **Status** lives on the enrollment (`active`, `suspended`, `transferred`, `dropped`, `passed_out`, `inactive`). A transferred student is transferred in their latest enrollment; earlier enrollments keep the status they had.
6. **Soft delete** is used for profiles, enrollments and certificates. Student serial numbers are never reused, even after a soft delete.

## Permissions

7. Permissions are the existing role abilities (`config/abilities.php`, edited from Roles & Permissions). Every sensitive permission of the spec is an ability named `students.<permission>`:
   `override_duplicate`, `override_dues`, `request_transfer`, `approve_transfer`, `reinstate_student`, `request_change`, `approve_change`, `import_students`, `promote_students`, `issue_certificate`, `view_full_brn`.
8. **Admin = the role with `*`**, never a hard-coded name, so by default only Admin holds all of them. A school can grant any one to another role or give it to a user's role from the settings screen.
9. A user holding `approve_change` edits protected fields directly. Everyone else with edit rights who changes a protected field creates a **Change Request**, but only if they hold `request_change`; without it the edit of a protected field is refused with an explanation.
10. Seeded roles: Admin, Teacher (class teacher), Accountant (new), plus the roles that already existed (Exam controller, Finance head, Cashier).

## Duplicate prevention

11. BRN is 17 digits, stored as text (leading zeros matter), `UNIQUE` at the database level, optional. There is no override for a BRN match.
12. Without a BRN the check is: normalized name (lower-case, punctuation and honorifics such as *Md.*, *Mst.* removed) + date of birth + guardian mobile (normalized to the last 10 digits). Candidates are found with indexed columns first; the name is then compared with a similarity score (default 85%). Only a holder of `override_duplicate` may continue, with a written reason that is logged.
13. Names are compared as typed (no Bangla ↔ English transliteration).

## Data and files

14. Guardian details live on the student; there is no guardian entity and no sibling logic.
15. **Excel**: no spreadsheet library was installed in the project. Import and export read and write `.xlsx` and `.csv`; if `phpoffice/phpspreadsheet` is not present the module uses a small built-in XLSX reader/writer. Spreadsheet formula injection is neutralized on both import and export.
16. **PDF**: reports, receipts and certificates are print-optimized A4 pages (use the browser's *Save as PDF*). Server-side PDF generation is not installed.
17. **Images**: resized to 400×400 (centre crop), saved as WebP, random file names, on the `public` disk.
18. **Background jobs** use the database queue already configured (`QUEUE_CONNECTION=database`). Start a worker with `php artisan queue:work`. Progress is stored in the database and shown on screen.
19. **Languages**: labels go through Laravel translation files (`lang/en`, `lang/bn`).

## Known limitations

20. No merge tool and no duplicate-review page (as specified).
21. Transfer-in is done through Student Entry or Online Admission, with the optional previous-school field.
22. Dues for the transfer check come from admission payments and the existing fee records.

## Added while building the later stages

23. **Fuzzy duplicate rule (no BRN)**: a possible duplicate = name similarity ≥ 85% **and** (same date of birth **or** same guardian mobile). Setting: `config/student.php` → `duplicate_threshold`.
24. **Dues** for a transfer = unpaid admission fee of the session + unpaid non-monthly fee structures (`config/student.php` → `fee_cycles_counted_as_dues`). Monthly fees are not counted. The result is stored with the transfer so the decision stays explainable later.
25. **Transfer Certificates** can only come out of an approved transfer (never typed in by hand). With unpaid dues the approval is refused unless the approver holds `override_dues` and writes a reason; the reason is stored on the transfer and in the audit log. `reinstate_student` (with a reason) is the only way back from *transferred*, and the legacy status screens refuse the change.
26. **Certificate serials** are `PREFIX-YEAR-NNNNN` (TC, TS, CH, OT), counted per type and year under a row lock, never reused, even for voided certificates. A certificate is never deleted; it is voided with a reason and cannot be printed again. Every opening of the print page increments `print_count`.
27. **Receipts**: serial `AR-YEAR-NNNNN`; voiding keeps the row (reason, user, time) and removes it from totals. Payment methods: cash, bKash, Nagad, bank.
28. **Online admission**: the public form `/apply` needs no login; it is rate-limited (5 per 10 minutes per IP), has a hidden honeypot field and strict validation. The applicant is never told whether the child already exists. Approval goes through the same duplicate guard as Student Entry; a staff member with `override_duplicate` may approve a flagged application with a reason. Applications target the latest session that has classes.
29. **Automatic promotion** follows the natural order of class names (`Class 1 … Class 10`), or the explicit order in `config/student.php` → `class_order`. The target class in the next session is matched by section, shift, medium and group. The whole run is one database transaction: it applies completely or not at all.
30. **Student Subjects**: a student's *4th/optional subject* is one of the class's subjects flagged optional; by default one per student (`max_optional_subjects`). The study group and the choice are what exams and results already read (`students.study_group_id`, `student_optional_subjects`). Permission: `students.manage_subjects`, scoped to the classes a user may manage.
31. **Performance** (measured on 50,000 enrollments, MySQL 8, one request, no warm cache): search by code, name prefix or BRN ≈ 1–3 ms; student list page ≈ 0.3 s; a filtered report page < 0.1 s; unfiltered report ≈ 0.7 s (the total is cached 60 s). Statistics take several seconds to build the first time at this size and are then cached for 2 minutes. Lists sort by the indexed numeric roll (`students.roll_no`, generated).
32. **Old screens**: `/students/create`, `/students/{id}` and `/students/{id}/edit` now redirect to the Student Module. The older edit forms still work for non-protected fields; changing a protected field there needs `approve_change`, and they cannot transfer a student out or bring one back.

## Routine module

33. **One routine per class row** (class + section + shift + medium + group), so sections of one class can differ. The class module stays the source of the facts: a class's subjects, its subject teachers and its class teacher are read from it, never typed again. Copying from a sister section uses the *target* section's own subject teachers.
34. **Periods** are a bell schedule (name, start, end, break or not). One common set serves every shift; a shift that runs at other times can have a set of its own. Changing a period's time keeps the subjects placed in it; removing a period removes its cells. A standard first set (1st–4th, Break, 5th–7th) is created the first time an admin opens the module.
35. **Working days** are a school-wide setting (default Sunday–Thursday). The week is shown Saturday first.
36. **A teacher cannot be in two places at once.** The check compares clock times, so it also holds between shifts with different bells. The teacher of a period defaults to the class module's subject teacher and can be changed for one period (a substitute); the clash check applies either way.
37. **Draft and published.** Editing changes the draft. Publishing freezes a copy; teachers and read-only users see only that copy, and the editor shows "changes waiting" until the next publish. Unpublishing hides it and keeps the draft. A period that is not a subject (Games, Library, Assembly …) is kept as a label.
38. **Permissions:** `routine.manage` (make, edit, publish, set periods) and `routine.view` (see published routines; a teacher sees only their own timetable). Admin holds both; the Teacher role gets `routine.view`.
39. **Printing:** A4 landscape in the usual school layout (days down, periods across, a break column, subject with teacher, subject-and-teacher list, signatures), in English or Bangla (Bangla day names and digits). Drafts print marked DRAFT. Teacher timetables print the same way.
40. **Periods per class.** A class can have periods of its own (its own period length, its own breaks, any number of them); otherwise it uses its shift's set, otherwise the common set. When a class's periods change, what is placed moves with its position among the teaching periods (the 3rd stays the 3rd), in the draft and in the published copy; cells with nowhere to go, and cells in a period turned into a break, are dropped. A set must contain at least one teaching period.
41. **Automatic routine.** Input per class: for each subject, periods a week and the most in one day (the "weekly plan", saved per class and copyable to the other sections of the class). The generator places each period in the best free slot (spreads a subject over days and positions, avoids the same subject back to back, balances a teacher's day), hardest teachers first, tries up to 25 arrangements and keeps the one with fewest left out. A teacher is never placed at an overlapping clock time, inside the run or against routines outside it. If a plan asks for more periods than the week has, that class is refused with the numbers. What cannot be placed is reported with the reason (usually: the teacher is busy in every free period). Nothing is saved until "Use this routine"; the same seed reproduces the same result. A subject with no teacher is placed without one.
42. **Limits of the generator:** it does not know teacher preferences, unavailable days, double periods or lab rooms; it is a greedy search with one level of repair, not an exact solver, so an extremely tight timetable (every period of every teacher used) may leave a few periods out, and the report says which and why.
43. **Several teachers for one subject.** A class's subject may have any number of current teachers (e.g. Science: a Physics and a Chemistry teacher). Each one's stint is recorded separately in the history; changing the list only records who joined or left. Every teacher of a subject may enter its marks. Where one name is needed (reports, the older screens) the main teacher is the first one assigned; reports show all names. `assignSubjectTeacher()` still means "this is THE teacher" (it replaces the list); `setSubjectTeachers()` sets the whole list.
44. **In the routine**, a subject with several teachers: the editor asks which of them takes the period (one click each); the automatic builder picks, for every period, a teacher of the subject who is free at that time, sharing the periods between them evenly. A teacher is never placed in two places at overlapping times, whichever way the routine was made.
45. **One class at a time.** The owner asked to remove generating routines for many classes at once: automatic building is only available inside a class's own routine.

## Teaching Plan module

46. **Syllabus key.** A syllabus belongs to a class level (all sections), a subject and a session. A session without its own syllabus uses the latest earlier one; "copy to class or session" makes an editable copy. Deleting a chapter is reversible (soft delete); plans and logs that used it keep it.
47. **Plans.** One plan per teacher, class section and subject. A teacher may plan only what the class module assigns to them. Chapters without suggested periods share the subject's yearly periods (periods a week x 36).
48. **Session and teaching days.** A session runs 1 January to 31 December. Teaching days are the routine's working days minus Teaching Plan holidays (new, entered in Teaching Settings; the system had no holiday list) and minus exam date ranges from the Exams module. "Today" is Asia/Dhaka (the application itself runs in UTC).
49. **Periods a week** come from the class routine, else the routine's weekly plan, else 4.
50. **Auto-schedule** spreads unfinished chapters over the chosen range in proportion to planned periods, and warns when the routine has fewer periods than planned. One plan is scheduled immediately; "schedule all undated plans" and imports/exports run as background jobs.
51. **Progress.** Chapter marked complete = 100%. With topics: the average of the best coverage logged per topic (Completed 100, Partly 50). Without topics: coverage added up against planned periods. Expected = share of the chapter's teaching days passed, including today. Plan figures are weighted by planned periods. Status is "behind" only beyond the thresholds (defaults 5% amber, 15% red).
52. **Daily log.** One entry per class period per date; extra classes have no period. The day's list comes from the published routine of each class, or its draft if never published. A substitute is recorded by the class's own teacher or someone with "manage all"; the substitute sees that class but cannot edit it. Dates older than the window (default 7 days) need "edit past logs"; every change to a past date is audited.
53. **Approval** is off by default. When on, changing the dates or periods of a submitted or approved plan returns it to draft.
54. **Permissions** are the teaching.* abilities. A new "Head Teacher" role is created; teachers get teaching.own. A person can be given extra teaching permissions in Teaching Settings (needs "manage roles"), stored in users.extra_abilities.
55. **Notifications** are inside the system (shown on the Teaching dashboard); no email or SMS.
56. **PDF** is the browser's "Save as PDF" from the print pages, as in the rest of the system.


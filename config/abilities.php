<?php

/*
|--------------------------------------------------------------------------
| Abilities catalogue
|--------------------------------------------------------------------------
| Every permission the system knows about. Roles (table `roles`) are lists of
| these keys. A key can be granted two ways:
|
|   "attendance.take"      → for every class in the school
|   "attendance.take:own"  → only for classes the user is class teacher of
|
| `scoped => true` marks abilities where the ":own" form makes sense.
| Add a new ability here and it appears in the role editor automatically.
*/

return [

    'groups' => [

        'Students' => [
            'students.view'   => ['label' => 'View students',            'scoped' => true],
            'students.create' => ['label' => 'Admit (add) students',     'scoped' => true],
            'students.edit'   => ['label' => 'Edit students',            'scoped' => true],
            'students.delete' => ['label' => 'Delete students',          'scoped' => true],
        ],

        // The sensitive student actions. Each is its own permission so a school decides who holds it;
        // by default only the Admin role (which holds "*") has them.
        'Student module' => [
            'students.override_duplicate' => ['label' => 'Save a student despite a likely duplicate (a reason is required and logged)', 'scoped' => false],
            'students.view_full_brn'      => ['label' => 'See the full Birth Registration Number (others see it masked)',               'scoped' => false],
            'students.request_change'     => ['label' => 'Request a change to a protected field (name, birth date, parents, gender, BRN)', 'scoped' => false],
            'students.approve_change'     => ['label' => 'Approve or reject change requests; edit protected fields directly',          'scoped' => false],
            'students.request_transfer'   => ['label' => 'Start a transfer-out request',                                              'scoped' => false],
            'students.approve_transfer'   => ['label' => 'Approve a transfer and issue the Transfer Certificate',                     'scoped' => false],
            'students.override_dues'      => ['label' => 'Issue a Transfer Certificate even though dues are unpaid (reason logged)',   'scoped' => false],
            'students.reinstate_student'  => ['label' => 'Make a transferred student active again (reason logged)',                   'scoped' => false],
            'students.import_students'    => ['label' => 'Import students from Excel / CSV',                                          'scoped' => false],
            'students.promote_students'   => ['label' => 'Promote students (automatic and manual)',                                   'scoped' => false],
            'students.issue_certificate'  => ['label' => 'Issue certificates (Transfer, Testimonial, Character, Other)',              'scoped' => false],
            'students.view_certificates'  => ['label' => 'See the certificate register',                                              'scoped' => false],
            'students.reports'            => ['label' => 'Student report and statistics',                                             'scoped' => true],
            'students.manage_images'      => ['label' => 'Upload student photos (single and bulk)',                                   'scoped' => false],
            'students.manage_subjects'    => ['label' => 'Assign subjects to students',                                               'scoped' => true],
            'students.online_admission'   => ['label' => 'See and approve online admission applications',                            'scoped' => false],
            'students.admission_payments' => ['label' => 'See the admission payment summary and receipts',                            'scoped' => false],
        ],

        'Routine' => [
            'routine.view'   => ['label' => 'See published class and teacher routines (a teacher sees their own timetable)', 'scoped' => false],
            'routine.manage' => ['label' => 'Make, edit and publish routines; set periods and working days',                   'scoped' => false],
        ],

        'Classes' => [
            'classes.view'   => ['label' => 'View classes',                       'scoped' => true],
            'subjects.manage' => ['label' => 'Manage the academic setup: subjects, study groups and curriculum templates', 'scoped' => false],
            'classes.manage' => ['label' => 'Create / edit / delete classes and assign class teachers', 'scoped' => false],
        ],

        'Attendance' => [
            'attendance.view' => ['label' => 'View attendance and records', 'scoped' => true],
            'attendance.take' => ['label' => 'Take / edit attendance',      'scoped' => true],
        ],

        'Results' => [
            'results.view'  => ['label' => 'Open the marks-entry sheet (subject teachers see only their own subject)', 'scoped' => true],
            'results.enter' => ['label' => 'Enter / edit marks (class teacher: any unlocked subject; subject teacher: own subject)', 'scoped' => true],
            'results.compile' => ['label' => 'See the complete class result — all subjects, totals and ranking', 'scoped' => true],
            'results.lock'  => ['label' => 'Lock results',              'scoped' => true],
            'results.unlock' => ['label' => 'Unlock locked results',    'scoped' => false],
        ],

        'Exams' => [
            'exams.manage'  => ['label' => 'Set up exams: weights, result rules, grading scale, subject parts and marks', 'scoped' => false],
            'exams.publish' => ['label' => 'Open, close and publish exams / results',                                    'scoped' => false],
        ],

        'Promotion' => [
            'promotion.propose' => ['label' => 'Propose yearly promotion for a class', 'scoped' => true],
            'promotion.run'     => ['label' => 'Run / approve yearly promotion',       'scoped' => false],
        ],

        'Fees & finance' => [
            'fees.view'              => ['label' => 'View fee screens and student dues', 'scoped' => false],
            'fees.view_dues'         => ['label' => 'View which students of a class have dues (read only)', 'scoped' => true],
            'fees.collect'           => ['label' => 'Collect payments / issue receipts', 'scoped' => false],
            'fees.discount'          => ['label' => 'Give discounts and waivers',        'scoped' => false],
            'fees.manage_structures' => ['label' => 'Create / delete fee structures',    'scoped' => false],
            'fees.reverse'           => ['label' => 'Reverse a payment',                 'scoped' => false],
            'fees.reports'           => ['label' => 'Finance reports',                   'scoped' => false],
        ],

        'Administration' => [
            'dashboard.view'  => ['label' => 'Admin dashboard',                 'scoped' => false],
            'users.manage'    => ['label' => 'Manage user accounts',            'scoped' => false],
            'roles.manage'    => ['label' => 'Manage roles and permissions',    'scoped' => false],
            'website.manage'  => ['label' => 'Manage the public website',       'scoped' => false],
            'gallery.manage'  => ['label' => 'Manage the photo gallery',        'scoped' => false],
            'audit.view'      => ['label' => 'View the audit log',              'scoped' => false],
        ],
    ],

    /*
    | Roles created by the migration. Admin can edit them (except `admin`, which
    | always has everything) and add new roles from the Roles screen.
    */
    'default_roles' => [

        'admin' => [
            'name'      => 'Administrator',
            'description' => 'Full access to everything.',
            'abilities' => ['*'],
        ],

        'teacher' => [
            'name'      => 'Teacher (class teacher)',
            'description' => 'Works only with the classes they are class teacher of.',
            'abilities' => [
                'students.view:own',
                'classes.view:own',
                'attendance.view:own', 'attendance.take:own',
                'results.view:own', 'results.enter:own', 'results.lock:own', 'results.compile:own',
                'promotion.propose:own',
                'fees.view_dues:own',
                'routine.view',
            ],
        ],

        'exam_controller' => [
            'name'      => 'Exam controller',
            'description' => 'Sets up exams and result rules, oversees marking and publishes results.',
            'abilities' => [
                'exams.manage', 'exams.publish',
                'results.view', 'results.enter', 'results.lock', 'results.unlock', 'results.compile',
                'students.view', 'classes.view',
            ],
        ],

        'finance_head' => [
            'name'      => 'Finance head',
            'description' => 'Runs the finance department: all fee actions and reports.',
            'abilities' => [
                'students.view', 'classes.view',
                'fees.view', 'fees.view_dues', 'fees.collect', 'fees.discount',
                'fees.manage_structures', 'fees.reverse', 'fees.reports',
            ],
        ],

        'cashier' => [
            'name'      => 'Cashier',
            'description' => 'Looks up students and collects payments.',
            'abilities' => ['students.view', 'classes.view', 'fees.view', 'fees.collect'],
        ],

        'accountant' => [
            'name'      => 'Accountant',
            'description' => 'Looks up students and their dues, collects admission and other fees, reads finance reports.',
            'abilities' => [
                'students.view', 'classes.view',
                'fees.view', 'fees.view_dues', 'fees.collect', 'fees.reports',
                'students.admission_payments',
            ],
        ],

        'user' => [
            'name'      => 'Public user',
            'description' => 'Registered visitor — no staff access.',
            'abilities' => [],
        ],
    ],

    /** Roles that cannot be deleted or renamed (their slug is used by the code). */
    'system_roles' => ['admin', 'teacher', 'exam_controller', 'finance_head', 'cashier', 'accountant', 'user'],
];

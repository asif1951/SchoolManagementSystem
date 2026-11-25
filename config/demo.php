<?php

/*
|--------------------------------------------------------------------------
| Demo data sizes
|--------------------------------------------------------------------------
| Used only by the demo seeders (database/seeders/DemoDataSeeder.php).
| Change them here, or override with the environment variables in .env:
|
|   DEMO_TEACHERS=40
|   DEMO_STUDENTS_PER_SECTION=25
|
| Run with:   php artisan db:seed --class=DemoDataSeeder
| The seeders only ADD what is missing, so they are safe to run again.
*/

return [
    'teachers'             => (int) env('DEMO_TEACHERS', 40),
    'students_per_section' => (int) env('DEMO_STUDENTS_PER_SECTION', 25),
    'year'                 => env('DEMO_YEAR'),   // default: the current year
];

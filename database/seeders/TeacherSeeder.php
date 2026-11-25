<?php

namespace Database\Seeders;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\Concerns\GeneratesBangladeshiData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bulk teachers. Each one gets
 *   - a login account (role "teacher"), and
 *   - a public staff profile (the "Teachers & Staff" page of the website),
 * and a subject specialty that the SubjectTeacherSeeder uses to decide who teaches what.
 *
 * All new accounts share ONE random password, printed once at the end of the run.
 * Existing accounts are never changed. The number of teachers is config('demo.teachers').
 */
class TeacherSeeder extends Seeder
{
    use GeneratesBangladeshiData;

    /** how many teachers of each specialty a 40-teacher school has */
    private const SPECIALTIES = [
        'Bangla' => 4, 'English' => 4, 'Mathematics' => 4, 'Science' => 3, 'Bangladesh & Global Studies' => 2,
        'Religion' => 3, 'ICT' => 2, 'Physical Education' => 1, 'Arts & Crafts' => 1, 'Agricultural Studies' => 1,
        'Home Science' => 1, 'Work & Life Oriented Education' => 1, 'Physics' => 2, 'Chemistry' => 2, 'Biology' => 2,
        'Higher Mathematics' => 1, 'Accounting' => 2, 'Business Entrepreneurship' => 1, 'Finance & Banking' => 1,
        'Production Management & Marketing' => 1, 'Civics & Citizenship' => 1, 'Geography & Environment' => 1,
        'History of Bangladesh & World Civilization' => 1, 'Economics' => 1,
    ];

    private const QUALIFICATIONS = [
        'B.Sc. (Hons), M.Sc., B.Ed.', 'B.A. (Hons), M.A., B.Ed.', 'B.Com. (Hons), M.Com., B.Ed.',
        'B.Sc. in CSE, M.Ed.', 'B.S.S. (Hons), M.S.S., B.Ed.', 'B.A. (Hons), M.A.', 'B.Sc. (Hons), M.Sc.',
    ];

    private const DESIGNATIONS = ['Assistant Teacher', 'Assistant Teacher', 'Assistant Teacher', 'Senior Teacher', 'Senior Teacher', 'Assistant Headmaster'];

    public function run(): void
    {
        mt_srand(20260101);

        $count = max(1, (int) config('demo.teachers', 40));

        // spread specialties evenly: one of each subject first, then the second of each, and so on
        $queue = [];
        for ($round = 0; count($queue) < $count; $round++) {
            $progress = false;
            foreach (self::SPECIALTIES as $subject => $weight) {
                if ($weight > $round) {
                    $queue[] = $subject;
                    $progress = true;
                }
            }
            if (! $progress) {            // more teachers than the usual mix: start the mix again
                $round = -1;
            }
        }

        $password = Str::password(12, symbols: false);
        $hash     = Hash::make($password);
        $created  = 0;

        for ($i = 0; $i < $count; $i++) {
            $gender    = $this->chance(55) ? 'male' : 'female';
            $name      = $this->personName($gender);
            $full      = ($gender === 'male' && $this->chance(60) ? 'Md. ' : '') . $name['full'];
            $email     = $this->slug($name['first'] . '.' . $name['last']) . ($i + 1) . '@school.test';
            $specialty = $queue[$i];

            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $user = new User(['name' => $full, 'email' => $email, 'role' => User::ROLE_TEACHER, 'status' => User::STATUS_ACTIVE]);
                $user->password          = $hash;           // already hashed, so it is stored as it is
                $user->email_verified_at = now();
                $user->save();
                $created++;
            }

            Teacher::firstOrCreate(['email' => $email], [
                'name'               => $user->name,
                'designation'        => $this->pick(self::DESIGNATIONS),
                'department'         => $specialty,
                'qualification'      => $this->pick(self::QUALIFICATIONS),
                'experience'         => mt_rand(2, 25) . ' years',
                'bio'                => "Teaches {$specialty}.",
                'phone'              => $this->phone(),
                'display_on_website' => true,
                'status'             => 'active',
                'order'              => $i + 1,
            ]);
        }

        $this->command?->info("Teachers: {$created} new accounts, " . Teacher::count() . ' staff profiles in total.');

        if ($created > 0) {
            $this->command?->warn("Password for the {$created} new teacher accounts: {$password}   (sign in with their email, e.g. " . User::where('role', 'teacher')->where('email', 'like', '%@school.test')->orderByDesc('id')->value('email') . ')');
        }
    }
}

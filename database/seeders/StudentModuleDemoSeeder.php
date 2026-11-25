<?php

namespace Database\Seeders;

use App\Models\AdmissionFee;
use App\Models\AdmissionPayment;
use App\Models\OnlineApplication;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AdmissionPayments;
use App\Services\DuesService;
use App\Services\OnlineAdmissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The Student Module on top of the demo school. Roles and permissions come from migrations; this adds:
 *  - an Accountant account,
 *  - a Birth Registration Number for about two students in three (the rest stay without one, on purpose, so the
 *    "missing BRN" filter, statistics and duplicate rules have something to show),
 *  - admission fees per class and a handful of payments (some full, some part, most none),
 *  - two online applications waiting for a decision.
 *
 * Safe to run again: it only adds what is missing and never changes a BRN that exists.
 *
 *   php artisan db:seed --class=StudentModuleDemoSeeder
 */
class StudentModuleDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);

        $year = (string) (config('demo.year') ?: now()->year);

        $this->accountant();
        $brns = $this->brns($year);
        $paid = $this->fees($year);
        $apps = $this->applications($year);

        $this->command?->table(['Student module demo', 'Count'], [
            ['Students with a BRN', StudentProfile::whereNotNull('brn')->count()],
            ['Students without a BRN', StudentProfile::whereNull('brn')->count()],
            ['BRNs added now', $brns],
            ['Admission payments added now', $paid],
            ['Online applications added now', $apps],
        ]);
    }

    private function accountant(): void
    {
        if (User::where('email', 'accountant@school.test')->exists()) {
            return;
        }

        $password = Str::random(14);

        User::create([
            'name' => 'Demo Accountant', 'email' => 'accountant@school.test', 'password' => $password,
            'role' => 'accountant', 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now(),
        ]);

        $this->command?->warn("Accountant: accountant@school.test / {$password}");
    }

    /** a 17-digit BRN for two profiles in three that have none: year + 2 zeros + a running 11-digit number */
    private function brns(string $year): int
    {
        $given = 0;
        $next = 1 + (int) StudentProfile::whereNotNull('brn')->count();

        StudentProfile::query()->whereNull('brn')->orderBy('id')->each(function (StudentProfile $p) use ($year, &$given, &$next) {
            if ($p->id % 3 === 0) {
                return; // every third child stays without one
            }

            $brn = $year . '00' . str_pad((string) $next++, 11, '0', STR_PAD_LEFT);

            StudentProfile::inSource('system', function () use ($p, $brn) {
                $p->forceFill(['brn' => $brn])->save();
            });

            $given++;
        });

        return $given;
    }

    /** fees for every class name of the year, and a few receipts so the summary has figures */
    private function fees(string $year): int
    {
        $payments = app(AdmissionPayments::class);
        $names = SchoolClass::where('academic_year', $year)->distinct()->pluck('class_name');

        // only the classes that have no fee yet: a fee the school already set is never touched
        $have = AdmissionFee::where('academic_year', $year)->pluck('class_name')->all();
        $payments->setFees($year, $names->diff($have)->mapWithKeys(fn ($n) => [$n => 500 + 100 * (int) preg_replace('/\D+/', '', $n)])->all());

        if (AdmissionPayment::exists()) {
            return 0;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        if (! $admin) {
            return 0;
        }

        $dues = app(DuesService::class);
        $made = 0;
        $methods = ['cash', 'bkash', 'nagad', 'bank'];

        Student::where('academic_year', (int) $year)->where('status', 'active')->orderBy('id')->limit(12)->get()->each(function (Student $s, int $i) use ($payments, $dues, $admin, $methods, &$made) {
            if ($i % 4 === 3) {
                return; // some pay nothing
            }

            $due = max(0.0, $dues->admissionExpected($s) - $dues->admissionPaid($s));

            if ($due <= 0) {
                return;
            }

            $payments->record($s, $i % 4 === 2 ? round($due / 2, 2) : $due, $methods[$i % 4], $i % 4 === 0 ? null : 'DEMO' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), null, $admin);
            $made++;
        });

        return $made;
    }

    private function applications(string $year): int
    {
        if (OnlineApplication::exists()) {
            return 0;
        }

        $class = SchoolClass::where('academic_year', $year)->where('status', 'active')->orderBy('id')->value('class_name');

        if (! $class) {
            return 0;
        }

        $svc = app(OnlineAdmissionService::class);

        $svc->submit(['academic_year' => $year, 'class_name' => $class, 'name_en' => 'Tahmina Akter', 'name_bn' => 'তাহমিনা আক্তার', 'dob' => ((int) $year - 11) . '-03-14', 'gender' => 'Female',
            'father_name' => 'Md. Abdur Rahman', 'mother_name' => 'Rokeya Begum', 'guardian_mobile' => '01712345678', 'previous_school' => 'Uttara Model School'], '127.0.0.1');

        $svc->submit(['academic_year' => $year, 'class_name' => $class, 'name_en' => 'Imran Hossain', 'dob' => ((int) $year - 12) . '-08-02', 'gender' => 'Male',
            'father_name' => 'Md. Jahangir Alam', 'guardian_mobile' => '01898765432'], '127.0.0.1');

        return 2;
    }
}

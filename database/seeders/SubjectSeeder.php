<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * The master list of subjects taught in a Bangladeshi school, Class 1 to 10.
 * Adds only the missing ones, so it is safe to run again.
 */
class SubjectSeeder extends Seeder
{
    /** name => [code, short name] */
    public const SUBJECTS = [
        // core
        'Bangla'                              => ['BAN', 'Bangla'],
        'English'                             => ['ENG', 'English'],
        'Mathematics'                         => ['MAT', 'Math'],
        'General Mathematics'                 => ['GMA', 'Gen. Math'],
        'Science'                             => ['SCI', 'Science'],
        'Bangladesh & Global Studies'         => ['BGS', 'BGS'],
        'Religion'                            => ['REL', 'Religion'],
        'ICT'                                 => ['ICT', 'ICT'],
        'Physical Education'                  => ['PHE', 'PE'],
        'Arts & Crafts'                       => ['ART', 'Arts'],
        'Work & Life Oriented Education'      => ['WLE', 'Work & Life'],
        'Agricultural Studies'                => ['AGR', 'Agriculture'],
        'Home Science'                        => ['HSC', 'Home Sci.'],
        // science group
        'Physics'                             => ['PHY', 'Physics'],
        'Chemistry'                           => ['CHE', 'Chemistry'],
        'Biology'                             => ['BIO', 'Biology'],
        'Higher Mathematics'                  => ['HMA', 'Higher Math'],
        // commerce (business studies) group
        'Accounting'                          => ['ACC', 'Accounting'],
        'Business Entrepreneurship'           => ['BEN', 'Business'],
        'Finance & Banking'                   => ['FIB', 'Fin. & Bank.'],
        'Production Management & Marketing'   => ['PMM', 'Prod. & Mkt.'],
        // arts (humanities) group
        'Civics & Citizenship'                => ['CIV', 'Civics'],
        'Geography & Environment'             => ['GEO', 'Geography'],
        'History of Bangladesh & World Civilization' => ['HIS', 'History'],
        'Economics'                           => ['ECO', 'Economics'],
    ];

    public function run(): void
    {
        $order = (int) Subject::max('sort_order');
        $added = 0;

        foreach (self::SUBJECTS as $name => [$code, $short]) {
            $subject = Subject::query()->where('name', $name)->first();

            if (! $subject) {
                Subject::create([
                    'name'       => $name,
                    'code'       => Subject::where('code', $code)->exists() ? null : $code,
                    'short_name' => $short,
                    'sort_order' => ++$order,
                    'is_active'  => true,
                ]);
                $added++;
            }
        }

        $this->command?->info("Subjects: {$added} added, " . Subject::count() . ' in total.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\StudyGroup;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Curriculum templates for Class 1 to 10 (the subjects of each class level, plus the
 * extras of each study group in Classes 9 and 10). Needs the subjects and groups first.
 * Adds only missing lines, so it is safe to run again.
 */
class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SubjectSeeder::class, StudyGroupSeeder::class]);

        $plans = [];

        foreach ([1, 2] as $n) {
            $plans["Class {$n}"] = ['common' => [
                'Bangla', 'English', 'Mathematics', 'Religion', 'Arts & Crafts', 'Physical Education',
            ]];
        }

        foreach ([3, 4, 5] as $n) {
            $plans["Class {$n}"] = ['common' => [
                'Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh & Global Studies', 'Religion', 'Arts & Crafts', 'Physical Education',
            ]];
        }

        foreach ([6, 7, 8] as $n) {
            $plans["Class {$n}"] = [
                'common'   => ['Bangla', 'English', 'Mathematics', 'Science', 'Bangladesh & Global Studies', 'Religion', 'ICT', 'Work & Life Oriented Education', 'Arts & Crafts', 'Physical Education'],
                'optional' => ['Agricultural Studies', 'Home Science'],
            ];
        }

        foreach ([9, 10] as $n) {
            $plans["Class {$n}"] = [
                'common' => ['Bangla', 'English', 'General Mathematics', 'Bangladesh & Global Studies', 'Religion', 'ICT'],
                'groups' => [
                    'Science'  => ['compulsory' => ['Physics', 'Chemistry', 'Biology'],
                                   'optional'   => ['Higher Mathematics']],
                    'Commerce' => ['compulsory' => ['Accounting', 'Business Entrepreneurship', 'Finance & Banking'],
                                   'optional'   => ['Agricultural Studies', 'Production Management & Marketing']],
                    'Arts'     => ['compulsory' => ['Civics & Citizenship', 'Geography & Environment', 'History of Bangladesh & World Civilization', 'Economics'],
                                   'optional'   => ['Agricultural Studies', 'Home Science']],
                ],
            ];
        }

        $groupIds   = StudyGroup::pluck('id', 'name');
        $subjectIds = Subject::pluck('id', 'name');
        $added      = 0;

        foreach ($plans as $level => $plan) {
            $order = 0;

            $line = function (?int $groupId, string $subject, bool $optional) use ($level, &$order, $subjectIds, &$added) {
                $row = CurriculumSubject::firstOrCreate(
                    ['class_name' => $level, 'study_group_id' => $groupId, 'subject_id' => $subjectIds[$subject]],
                    ['is_optional' => $optional, 'sort_order' => ++$order]
                );
                $added += $row->wasRecentlyCreated ? 1 : 0;
            };

            foreach ($plan['common'] as $subject) {
                $line(null, $subject, false);
            }
            foreach ($plan['optional'] ?? [] as $subject) {
                $line(null, $subject, true);
            }
            foreach ($plan['groups'] ?? [] as $group => $subjects) {
                foreach ($subjects['compulsory'] as $subject) {
                    $line($groupIds[$group], $subject, false);
                }
                foreach ($subjects['optional'] as $subject) {
                    $line($groupIds[$group], $subject, true);
                }
            }
        }

        $this->command?->info("Curriculum: {$added} lines added for Class 1 to 10.");
    }
}

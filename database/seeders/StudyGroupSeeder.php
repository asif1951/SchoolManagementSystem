<?php

namespace Database\Seeders;

use App\Models\StudyGroup;
use Illuminate\Database\Seeder;

/** Science, Commerce and Arts — the groups of Classes 9 and 10. Safe to run again. */
class StudyGroupSeeder extends Seeder
{
    public function run(): void
    {
        $order = (int) StudyGroup::max('sort_order');

        foreach ([
            ['Science',  'SCI', 'Physics, Chemistry, Biology and Higher Mathematics'],
            ['Commerce', 'COM', 'Accounting, Business Entrepreneurship, Finance and Banking'],
            ['Arts',     'ART', 'Humanities: Civics, Geography, History and Economics'],
        ] as [$name, $code, $description]) {
            StudyGroup::firstOrCreate(['name' => $name], [
                'code' => $code, 'description' => $description, 'sort_order' => ++$order, 'is_active' => true,
            ]);
        }

        $this->command?->info('Study groups: ' . StudyGroup::count() . ' in total.');
    }
}

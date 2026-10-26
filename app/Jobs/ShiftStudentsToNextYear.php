<?php

namespace App\Jobs;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShiftStudentsToNextYear implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;
    public $tries   = 1;

    public function __construct(
        public array  $studentIds,
        public string $targetClass,
        public string $targetSection,
        public int    $fromYear,
        public int    $toYear,
        public int    $userId,
    ) {}

    public function handle(): void
    {
        $total     = count($this->studentIds);
        $processed = 0;
        $inserted  = 0;
        $skipped   = 0;
        $now       = now();

        // ⭐ Section empty হলে NULL হবে — source student এর section কখনো copy হবে না
        $sectionValue = ($this->targetSection === '' || $this->targetSection === null)
            ? null
            : $this->targetSection;

        // ⭐ Duplicate check — name + father + DOB combo
        $sourceIds = $this->studentIds;

        $existingKeys = Student::where('academic_year', $this->toYear)
            ->whereIn('student_name', function ($q) use ($sourceIds) {
                $q->select('student_name')
                  ->from('students')
                  ->whereIn('id', $sourceIds);
            })
            ->get(['student_name', 'father_name', 'date_of_birth'])
            ->map(function ($s) {
                return strtolower(trim($s->student_name ?? '')) . '|' .
                       strtolower(trim($s->father_name ?? '')) . '|' .
                       ($s->date_of_birth ?? '');
            })
            ->flip();

        $batch = [];

        Student::whereIn('id', $this->studentIds)
            ->orderBy('id')
            ->chunkById(500, function ($students) use (
                &$batch, &$inserted, &$skipped, &$processed,
                $existingKeys, $now, $total, $sectionValue
            ) {
                foreach ($students as $src) {
                    $processed++;

                    // ⭐ Same key for comparison
                    $srcKey = strtolower(trim($src->student_name ?? '')) . '|' .
                              strtolower(trim($src->father_name ?? '')) . '|' .
                              ($src->date_of_birth ?? '');

                    if ($existingKeys->has($srcKey)) {
                        $skipped++;
                        continue;
                    }

                    // ⭐ Year-suffixed student_id
                    $newStudentId = $this->buildShiftedStudentId($src->student_id, $this->toYear);

                    $batch[] = [
                        'student_name'      => $src->student_name,
                        'student_id'        => $newStudentId,
                        'date_of_birth'     => $src->date_of_birth,
                        'gender'            => $src->gender,
                        'blood_group'       => $src->blood_group,
                        'student_photo'     => $src->student_photo,
                        'admission_date'    => $src->admission_date,
                        'status'            => 'active',
                        'father_name'       => $src->father_name,
                        'father_phone'      => $src->father_phone,
                        'mother_name'       => $src->mother_name,
                        'mother_phone'      => $src->mother_phone,
                        'guardian_name'     => $src->guardian_name,
                        'guardian_phone'    => $src->guardian_phone,
                        'guardian_relation' => $src->guardian_relation,
                        'present_address'   => $src->present_address,
                        'permanent_address' => $src->permanent_address,

                        // Roll preserved
                        'roll_number'       => $src->roll_number,

                        // NEW class + section + year
                        'class'             => $this->targetClass,
                        // ⭐ Section: খালি থাকলে NULL, নাহলে selected section
                        'section'           => $sectionValue,
                        'academic_year'     => $this->toYear,

                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];

                    $inserted++;

                    if (count($batch) >= 500) {
                        Student::insert($batch);
                        $batch = [];
                    }
                }

                Cache::put(
                    'shift_progress_' . $this->userId,
                    $total > 0 ? round($processed / $total * 100) : 100,
                    now()->addMinutes(30)
                );
            });

        if (!empty($batch)) {
            Student::insert($batch);
        }

        Cache::put("shift_result_{$this->userId}", [
            'done'     => true,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'class'    => $this->targetClass,
            'section'  => $sectionValue,
            'year'     => $this->toYear,
            'at'       => now()->toDateTimeString(),
        ], now()->addHours(2));

        Cache::forget('shift_progress_' . $this->userId);

        Log::info('Student shift job complete', [
            'user_id'  => $this->userId,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'class'    => $this->targetClass,
            'section'  => $sectionValue,
            'year'     => $this->toYear,
        ]);
    }

    /**
     * ⭐ Build a new student_id with the target year suffix.
     */
    private function buildShiftedStudentId(string $oldId, int $newYear): string
    {
        if (preg_match('/^(.+)-(\d{4})$/', $oldId, $m)) {
            return $m[1] . '-' . $newYear;
        }

        return $oldId . '-' . $newYear;
    }
}
<?php

namespace App\Jobs;

use App\Models\SchoolClass;
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
        public int    $targetClassId,
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

        // ⭐ Target class is resolved once, by id — class/section text always comes from it
        $targetClass  = SchoolClass::findOrFail($this->targetClassId);
        $sectionValue = ($targetClass->section === '' || $targetClass->section === null)
            ? null
            : $targetClass->section;

        // ⭐ Already in the target year? The student_id is permanent, so it is an exact match.
        $existingIds = Student::where('academic_year', $this->toYear)
            ->whereIn('student_id', Student::whereIn('id', $this->studentIds)->select('student_id'))
            ->pluck('student_id')
            ->flip();

        $batch = [];

        Student::whereIn('id', $this->studentIds)
            ->orderBy('id')
            ->chunkById(500, function ($students) use (
                &$batch, &$inserted, &$skipped, &$processed,
                $existingIds, $now, $total, $sectionValue, $targetClass
            ) {
                foreach ($students as $src) {
                    $processed++;

                    if ($existingIds->has($src->student_id)) {
                        $skipped++;
                        continue;
                    }

                    $batch[] = [
                        'student_name'      => $src->student_name,
                        'student_id'        => $src->student_id,      // same ID for life
                        'promoted_from_id'  => $src->id,
                        'public_token'      => (string) \Illuminate\Support\Str::ulid(),
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
                        'class_id'          => $targetClass->id,
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
            'class'    => $targetClass->class_name,
            'section'  => $sectionValue,
            'year'     => $this->toYear,
            'at'       => now()->toDateTimeString(),
        ], now()->addHours(2));

        Cache::forget('shift_progress_' . $this->userId);

        Log::info('Student shift job complete', [
            'user_id'  => $this->userId,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'class'    => $targetClass->class_name,
            'section'  => $sectionValue,
            'year'     => $this->toYear,
        ]);
    }
}

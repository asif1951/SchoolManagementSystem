<?php

namespace App\Services;

use App\Exceptions\DuplicateStudentException;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Normalize;
use App\Support\Spreadsheet;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Turns an uploaded spreadsheet into checked student rows.
 *
 *   parse()  the file → one array per row, columns named by their canonical name
 *   check()  every row against the same rules as Student Entry (StudentRegistry::precheck) plus the rows
 *            of the file itself: a BRN, a roll, or a likely duplicate repeated further down the file
 *
 * Nothing is saved here. check() returns what would be imported and what would not, with the row number
 * and the reason, so the user can fix the file and try again before anything touches the students.
 */
class StudentImporter
{
    /** the columns of the template, in order */
    public const COLUMNS = [
        'name_en', 'name_bn', 'gender', 'dob', 'brn', 'religion', 'blood_group', 'father_name', 'mother_name',
        'guardian_name', 'guardian_mobile', 'guardian_relation', 'present_address', 'permanent_address',
        'previous_school', 'session', 'class', 'section', 'group', 'shift', 'medium', 'roll_number',
    ];

    /** other names a column may carry in a school's own sheet */
    private const ALIASES = [
        'name_en' => ['name', 'student_name', 'name_english', 'name_in_english', 'full_name'],
        'name_bn' => ['bangla_name', 'name_bangla', 'name_in_bangla'],
        'gender' => ['sex'],
        'dob' => ['date_of_birth', 'birth_date', 'birthday', 'date_birth'],
        'brn' => ['birth_registration_no', 'birth_registration_number', 'birth_reg_no', 'birth_certificate_no', 'birth_certificate_number'],
        'religion' => [],
        'blood_group' => ['blood'],
        'father_name' => ['father', 'fathers_name', 'father_s_name'],
        'mother_name' => ['mother', 'mothers_name', 'mother_s_name'],
        'guardian_name' => ['guardian'],
        'guardian_mobile' => ['mobile', 'guardian_phone', 'phone', 'mobile_no', 'guardian_mobile_no', 'contact'],
        'guardian_relation' => ['relation'],
        'present_address' => ['address', 'current_address'],
        'permanent_address' => [],
        'previous_school' => ['last_school', 'previous_institution'],
        'session' => ['year', 'academic_year'],
        'class' => ['class_name', 'grade'],
        'section' => [],
        'group' => ['study_group'],
        'shift' => [],
        'medium' => [],
        'roll_number' => ['roll', 'roll_no', 'class_roll'],
    ];

    public function __construct(private StudentRegistry $registry)
    {
    }

    /** header row + one example row, for the downloadable template */
    public function template(?SchoolClass $example = null): array
    {
        return [
            self::COLUMNS,
            [
                'Rahim Uddin', 'রহিম উদ্দিন', 'Male', '2014-05-02', '20141234567890123', 'Islam', 'O+', 'Karim Uddin', 'Rahima Begum',
                'Karim Uddin', '01712345678', 'Father', 'Village, Upazila, District', 'Village, Upazila, District',
                '', $example?->academic_year ?? date('Y'), $example?->class_name ?? 'Class 6', $example?->section ?? 'A', '', '', '', '1',
            ],
        ];
    }

    /**
     * @return array<int,array{row:int, raw:array<string,string>}>   row numbers are the spreadsheet's own (the header is row 1)
     *
     * @throws RuntimeException  when the file cannot be read or lacks a required column
     */
    public function parse(string $path): array
    {
        $table = Spreadsheet::read($path);

        if (count($table) < 2) {
            throw new RuntimeException('The file has no student rows. Use the template: the first row holds the column names.');
        }

        $map = $this->mapHeaders(array_shift($table));

        foreach (['name_en' => 'name', 'class' => 'class'] as $canonical => $label) {
            if (! in_array($canonical, $map, true)) {
                throw new RuntimeException("The column \"{$label}\" is missing. Download the template and keep its column names.");
            }
        }

        $rows = [];

        foreach ($table as $i => $cells) {
            $raw = [];

            foreach ($map as $col => $canonical) {
                if ($canonical !== null) {
                    $raw[$canonical] = trim((string) ($cells[$col] ?? ''));
                }
            }

            if (implode('', $raw) === '') {
                continue;                                         // a blank row
            }

            $rows[] = ['row' => $i + 2, 'raw' => $raw];
        }

        return $rows;
    }

    /**
     * Check every row.
     *
     * @param  array<int,array{row:int, raw:array<string,string>}>  $rows
     * @return array{valid: array<int,array{row:int,data:array}>, invalid: array<int,array{row:int,name:string,reason:string}>}
     */
    public function check(array $rows, User $by, string $defaultSession): array
    {
        $classes = $this->loadClasses($rows, $defaultSession);

        $valid = [];
        $invalid = [];

        $seenBrn = [];
        $seenIdentity = [];
        $seenRoll = [];

        foreach ($rows as $item) {
            $n = $item['row'];
            $raw = $item['raw'];
            $name = (string) ($raw['name_en'] ?? '');
            $problems = [];

            $data = $this->toData($raw, $defaultSession, $classes, $problems);

            if (! $problems) {
                // rows of this same file
                $brn = Normalize::brn($data['brn'] ?? null);
                $nameKey = Normalize::name($data['name_en']);
                $mobile = Normalize::mobile($data['guardian_mobile'] ?? null);
                $roll = isset($data['roll_number']) && $data['roll_number'] !== '' ? $data['class_id'] . '|' . $data['roll_number'] : null;

                if ($brn !== null && isset($seenBrn[$brn])) {
                    $problems[] = "The same Birth Registration Number is on row {$seenBrn[$brn]}.";
                }

                if ($brn === null) {
                    foreach (array_filter([$data['dob'] ?? null ? $nameKey . '|d|' . $data['dob'] : null, $mobile ? $nameKey . '|m|' . $mobile : null]) as $key) {
                        if (isset($seenIdentity[$key])) {
                            $problems[] = "Looks like the same child as row {$seenIdentity[$key]} (same name and birth date or mobile).";
                            break;
                        }
                    }
                }

                if ($roll !== null && isset($seenRoll[$roll])) {
                    $problems[] = "Roll {$data['roll_number']} is already used on row {$seenRoll[$roll]} for the same class.";
                }
            }

            if (! $problems) {
                try {
                    $this->registry->precheck($data, $by);
                } catch (ValidationException $e) {
                    $problems = array_merge($problems, collect($e->errors())->flatten()->all());
                } catch (DuplicateStudentException $e) {
                    $problems[] = $e->getMessage();
                }
            }

            if ($problems) {
                $invalid[] = ['row' => $n, 'name' => $name, 'reason' => implode(' ', array_unique($problems))];
                continue;
            }

            // only rows that will be imported can block later rows of the file
            if (($brn ?? null) !== null) {
                $seenBrn[$brn] = $n;
            } else {
                foreach (array_filter([$data['dob'] ?? null ? $nameKey . '|d|' . $data['dob'] : null, $mobile ? $nameKey . '|m|' . $mobile : null]) as $key) {
                    $seenIdentity[$key] = $n;
                }
            }

            if ($roll !== null) {
                $seenRoll[$roll] = $n;
            }

            $valid[] = ['row' => $n, 'data' => $data];
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    // ------------------------------------------------------------------

    /** @return array<int,?string>  column index → canonical column name (null for unknown columns) */
    private function mapHeaders(array $header): array
    {
        $lookup = [];
        foreach (self::COLUMNS as $canonical) {
            $lookup[$canonical] = $canonical;
            foreach (self::ALIASES[$canonical] ?? [] as $alias) {
                $lookup[$alias] = $canonical;
            }
        }

        $map = [];
        foreach ($header as $i => $cell) {
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $cell)), '_');
            $map[$i] = $lookup[$key] ?? null;
        }

        return $map;
    }

    /** the header row → column map; refuses a file without the required columns (streaming imports use this) */
    public function mapHeader(array $header): array
    {
        $map = $this->mapHeaders($header);

        foreach (['name_en' => 'name', 'class' => 'class'] as $canonical => $label) {
            if (! in_array($canonical, $map, true)) {
                throw new RuntimeException("The column \"{$label}\" is missing. Download the template and keep its column names.");
            }
        }

        return $map;
    }

    /** one row of cells → named, trimmed values */
    public function rawRow(array $cells, array $map): array
    {
        $raw = [];

        foreach ($map as $col => $canonical) {
            if ($canonical !== null) {
                $raw[$canonical] = trim((string) ($cells[$col] ?? ''));
            }
        }

        return $raw;
    }

    /** a raw row → the data StudentRegistry takes; the reasons it cannot be used go into $problems */
    public function convert(array $raw, string $defaultSession, array $classes, array &$problems): array
    {
        return $this->toData($raw, $defaultSession, $classes, $problems);
    }

    /** the active classes of these sessions, indexed by "session|class|section" */
    public function classIndexFor(array $sessions): array
    {
        $index = [];

        foreach (SchoolClass::query()->whereIn('academic_year', $sessions)->where('status', 'active')->get() as $c) {
            // the class name or the school's own title, the section or its title: "Podma" for Class 1, "Rose" for section A
            foreach (array_unique([$c->class_name, (string) $c->class_title]) as $cn) {
                foreach (array_unique([(string) $c->section, (string) $c->section_title]) as $sn) {
                    if ($cn === '') {
                        continue;
                    }

                    $key = $c->academic_year . '|' . mb_strtolower($cn) . '|' . mb_strtolower($sn);

                    if (! in_array($c, $index[$key] ?? [], true)) {
                        $index[$key][] = $c;
                    }
                }
            }
        }

        return $index;
    }

    /** one row of cells → the data StudentRegistry takes; reasons a row cannot be used go into $problems */
    private function toData(array $raw, string $defaultSession, array $classes, array &$problems): array
    {
        $data = [];

        foreach (['name_en', 'name_bn', 'brn', 'religion', 'blood_group', 'father_name', 'mother_name', 'guardian_name', 'guardian_mobile',
                  'guardian_relation', 'present_address', 'permanent_address', 'previous_school'] as $field) {
            $data[$field] = $raw[$field] ?? '';
        }

        foreach (['dob'] as $dateField) {
            $data[$dateField] = Spreadsheet::serialToDate((string) ($raw[$dateField] ?? ''));
        }

        $gender = $this->gender($raw['gender'] ?? '');
        if ($gender === false) {
            $problems[] = 'The gender "' . $raw['gender'] . '" is not recognised (use Male, Female or Other).';
        }
        $data['gender'] = $gender ?: null;

        $data['roll_number'] = $raw['roll_number'] ?? '';

        // the class: session + class name + section, narrowed by group / shift / medium when the school has several
        $session = ($raw['session'] ?? '') !== '' ? preg_replace('/\.0+$/', '', $raw['session']) : $defaultSession;
        $candidates = $classes[$session . '|' . mb_strtolower($raw['class'] ?? '') . '|' . mb_strtolower($raw['section'] ?? '')] ?? [];

        foreach (['group' => 'group_name', 'shift' => 'shift_name', 'medium' => 'medium_name'] as $column => $attribute) {
            if (($raw[$column] ?? '') !== '') {
                $candidates = array_values(array_filter($candidates, fn ($c) => mb_strtolower((string) $c->{$attribute}) === mb_strtolower($raw[$column])));
            }
        }

        if (count($candidates) === 0) {
            $problems[] = 'No class "' . ($raw['class'] ?? '') . (($raw['section'] ?? '') !== '' ? ' ' . $raw['section'] : '') . "\" in session {$session}.";
        } elseif (count($candidates) > 1) {
            $problems[] = 'More than one class matches; add the group, shift or medium column.';
        } else {
            $data['class_id'] = $candidates[0]->id;
        }

        return $data;
    }

    /** Male / Female / Other, null when blank, false when it cannot be understood */
    private function gender(string $value)
    {
        $v = mb_strtolower(trim($value));

        return match (true) {
            $v === '' => null,
            in_array($v, ['m', 'male', 'boy', 'ছেলে', 'পুরুষ'], true) => 'Male',
            in_array($v, ['f', 'female', 'girl', 'মেয়ে', 'নারী'], true) => 'Female',
            in_array($v, ['o', 'other', 'others', 'অন্যান্য'], true) => 'Other',
            default => false,
        };
    }

    /** all active classes of the sessions named in the file, indexed once (not one query per row) */
    private function loadClasses(array $rows, string $defaultSession): array
    {
        $sessions = collect($rows)->map(fn ($r) => ($r['raw']['session'] ?? '') !== '' ? preg_replace('/\.0+$/', '', $r['raw']['session']) : $defaultSession)
            ->push($defaultSession)->unique()->values()->all();

        return $this->classIndexFor($sessions);
    }
}

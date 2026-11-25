<?php

namespace App\Services;

use App\Exceptions\DuplicateStudentException;
use App\Models\User;
use App\Support\Normalize;
use App\Support\PersonId;
use App\Support\SheetStream;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The import for files of any size: a million rows take minutes, not hours, and a few tens of megabytes of memory.
 *
 *   - the file is streamed row by row (SheetStream), never loaded whole;
 *   - rows are handled in chunks of 2,000: every database check is ONE query per chunk (existing BRNs, likely
 *     duplicates, taken rolls), and a chunk is saved with a handful of bulk inserts in one transaction;
 *   - the rules are Student Entry's own: the same cleaning (StudentRegistry::normalizePersonal), the same BRN rule,
 *     the same roll rule. The one difference: a child without a BRN is matched on the *exact* normalized name plus
 *     date of birth or guardian mobile; the fuzzy 85% similarity is for the desk, where a person looks at the result.
 *   - if a chunk cannot be saved as a block (two people importing at once, say) it is saved row by row through
 *     StudentRegistry::admit(), so the failing rows are named and the rest still go in.
 *
 * run() with $commit = false only checks (the preview); with $commit = true it saves.
 */
class BulkStudentImport
{
    public const CHUNK = 2000;
    public const SAMPLE = 1000;

    /** @var array<string,array> classes indexed by "session|class|section" */
    private array $classIndex = [];
    /** @var array<string,true> */
    private array $loadedSessions = [];
    /** @var array<int,\App\Models\SchoolClass> */
    private array $classById = [];

    public function __construct(private StudentImporter $importer, private StudentRegistry $registry)
    {
    }

    /**
     * @param  callable(int):void|null  $tick  called after each chunk with the number of rows done so far
     * @return array{total:int, valid:int, invalid:int, created:int, sample:array<int,array{row:int,name:string,reason:string}>}
     *
     * @throws RuntimeException  a file that cannot be read, or has no student rows / no name or class column
     */
    public function run(string $path, User $by, string $session, bool $commit, string $errorsPath, ?callable $tick = null): array
    {
        $errors = fopen($errorsPath, 'wb');

        if (! $errors) {
            throw new RuntimeException('The report file could not be created.');
        }

        fputcsv($errors, ['Row', 'Name', 'Reason'], ',', '"', '');

        $map = null;
        $chunk = [];
        $r = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'created' => 0, 'sample' => []];
        $seen = ['brn' => [], 'identity' => [], 'roll' => []];

        try {
            foreach (SheetStream::rows($path) as $number => $cells) {
                if ($map === null) {
                    $map = $this->importer->mapHeader($cells);
                    continue;
                }

                $raw = $this->importer->rawRow($cells, $map);

                if (implode('', $raw) === '') {
                    continue;
                }

                $r['total']++;
                $chunk[] = ['row' => (int) $number, 'raw' => $raw];

                if (count($chunk) >= self::CHUNK) {
                    $this->chunk($chunk, $by, $session, $commit, $seen, $r, $errors);
                    $chunk = [];
                    $tick && $tick($r['total']);
                }
            }

            if ($chunk) {
                $this->chunk($chunk, $by, $session, $commit, $seen, $r, $errors);
                $tick && $tick($r['total']);
            }
        } finally {
            fclose($errors);
        }

        if ($r['total'] === 0) {
            throw new RuntimeException('The file has no student rows. Use the template: the first row holds the column names.');
        }

        return $r;
    }

    // ------------------------------------------------------------------

    private function chunk(array $rows, User $by, string $session, bool $commit, array &$seen, array &$r, $errors): void
    {
        $this->loadClasses($rows, $session);

        $fail = function (int $row, string $name, string $why) use (&$r, $errors) {
            $r['invalid']++;
            fputcsv($errors, [$row, Normalize::csvCell($name), Normalize::csvCell($why)], ',', '"', '');

            if (count($r['sample']) < self::SAMPLE) {
                $r['sample'][] = ['row' => $row, 'name' => $name, 'reason' => $why];
            }
        };

        // ---- 1. what a row can be checked for without the database -------------------------------------------------
        $local = [];
        $chunkSeen = ['brn' => [], 'identity' => [], 'roll' => []];

        foreach ($rows as $item) {
            $problems = [];
            $name = (string) ($item['raw']['name_en'] ?? '');
            $data = $this->importer->convert($item['raw'], $session, $this->classIndex, $problems);
            $personal = [];

            if (! $problems) {
                try {
                    $personal = $this->registry->normalizePersonal($data);
                } catch (ValidationException $e) {
                    $problems = collect($e->errors())->flatten()->all();
                }
            }

            if (! $problems) {
                $brn = Normalize::brn($personal['brn'] ?? null);
                $nameKey = Normalize::name($personal['name_en']);
                $mobile = Normalize::mobile($personal['guardian_mobile'] ?? null);
                $roll = trim((string) ($data['roll_number'] ?? ''));
                $rollKey = $roll !== '' ? self::h($data['class_id'] . '|' . $roll) : null;
                $ids = [];

                if ($brn === null) {
                    foreach (array_filter([($personal['dob'] ?? null) ? $nameKey . '|d|' . $personal['dob'] : null, $mobile ? $nameKey . '|m|' . $mobile : null]) as $k) {
                        $ids[] = self::h($k);
                    }
                }

                if ($brn !== null && ($line = $seen['brn'][(int) $brn] ?? $chunkSeen['brn'][(int) $brn] ?? null)) {
                    $problems[] = "The same Birth Registration Number is on row {$line}.";
                }

                foreach ($ids as $id) {
                    if ($line = $seen['identity'][$id] ?? $chunkSeen['identity'][$id] ?? null) {
                        $problems[] = "Looks like the same child as row {$line} (same name and birth date or mobile).";
                        break;
                    }
                }

                if ($rollKey !== null && ($line = $seen['roll'][$rollKey] ?? $chunkSeen['roll'][$rollKey] ?? null)) {
                    $problems[] = "Roll {$roll} is already used on row {$line} for the same class.";
                }
            }

            if ($problems) {
                $fail($item['row'], $name, implode(' ', array_unique($problems)));
                continue;
            }

            if ($brn !== null) {
                $chunkSeen['brn'][(int) $brn] = $item['row'];
            }

            foreach ($ids as $id) {
                $chunkSeen['identity'][$id] = $item['row'];
            }

            if ($rollKey !== null) {
                $chunkSeen['roll'][$rollKey] = $item['row'];
            }

            $local[] = ['row' => $item['row'], 'name' => $name, 'data' => $data, 'personal' => $personal, 'brn' => $brn, 'nameKey' => $nameKey, 'mobile' => $mobile, 'roll' => $roll];
        }

        // ---- 2. what only the database knows: one query per kind for the whole chunk -------------------------------
        $inDb = $this->existing($local);
        $good = [];

        foreach ($local as $x) {
            $why = null;

            if ($x['brn'] !== null && isset($inDb['brn'][$x['brn']])) {
                $why = 'This Birth Registration Number is already registered (student ' . $inDb['brn'][$x['brn']] . ').';
            } elseif ($x['brn'] === null && ($code = $this->likelyDuplicate($x, $inDb))) {
                $why = "A student with the same name and birth date or mobile already exists ({$code}).";
            } elseif ($x['roll'] !== '' && isset($inDb['rolls'][$x['data']['class_id'] . '|' . $x['roll']])) {
                $why = "Roll {$x['roll']} is already taken in this class.";
            }

            if ($why) {
                $fail($x['row'], $x['name'], $why);
            } else {
                $good[] = $x;
            }
        }

        // what passed counts for the later chunks of the file
        foreach ($good as $x) {
            if ($x['brn'] !== null) {
                $seen['brn'][(int) $x['brn']] = $x['row'];
            } else {
                foreach (array_filter([($x['personal']['dob'] ?? null) ? $x['nameKey'] . '|d|' . $x['personal']['dob'] : null, $x['mobile'] ? $x['nameKey'] . '|m|' . $x['mobile'] : null]) as $k) {
                    $seen['identity'][self::h($k)] = $x['row'];
                }
            }

            if ($x['roll'] !== '') {
                $seen['roll'][self::h($x['data']['class_id'] . '|' . $x['roll'])] = $x['row'];
            }
        }

        $r['valid'] += count($good);

        if (! $commit || ! $good) {
            return;
        }

        // ---- 3. save ---------------------------------------------------------------------------------------------
        try {
            DB::transaction(fn () => $this->insert($good, $by));
            $r['created'] += count($good);
        } catch (QueryException) {
            foreach ($good as $x) {                          // the block did not go in: row by row, naming what fails
                try {
                    $this->registry->admit($x['data'], $by, 'import');
                    $r['created']++;
                } catch (ValidationException $e) {
                    $r['valid']--;
                    $fail($x['row'], $x['name'], collect($e->errors())->flatten()->implode(' '));
                } catch (DuplicateStudentException $e) {
                    $r['valid']--;
                    $fail($x['row'], $x['name'], $e->getMessage());
                } catch (QueryException) {
                    $r['valid']--;
                    $fail($x['row'], $x['name'], 'This row could not be saved (it clashed with another record).');
                }
            }
        }
    }

    /**
     * What the database already holds that could clash with this chunk, in one query per kind.
     *
     * @return array{brn: array<string,string>, byDob: array<string,array>, byMobile: array<string,array>, rolls: array<string,true>}
     */
    private function existing(array $rows): array
    {
        $brns = $dobs = $mobiles = $letters = $classes = $rolls = [];

        foreach ($rows as $x) {
            if ($x['brn'] !== null) {
                $brns[] = $x['brn'];
            } else {
                // the same rule as Student Entry: the same date of birth or guardian mobile, then how alike the names are
                ($x['personal']['dob'] ?? null) && $dobs[$x['personal']['dob']] = true;
                $x['mobile'] && $mobiles[$x['mobile']] = true;
                $letters[mb_substr($x['nameKey'], 0, 1)] = true;
            }

            if ($x['roll'] !== '') {
                $classes[$x['data']['class_id']] = true;
                $rolls[$x['roll']] = true;
            }
        }

        $out = ['brn' => [], 'byDob' => [], 'byMobile' => [], 'rolls' => []];

        if ($brns) {
            // soft-deleted profiles keep their BRN: a plain table query includes them, as the unique index does
            foreach (DB::table('student_profiles')->whereIn('brn', $brns)->get(['brn', 'code']) as $p) {
                $out['brn'][$p->brn] = $p->code;
            }
        }

        if ($dobs || $mobiles) {
            // a date of birth is shared by many children in a big school, so those are narrowed by the first letter of the name;
            // a guardian mobile is nearly unique and is matched without that narrowing
            $q = DB::table('student_profiles')->whereNull('deleted_at')->where(function ($w) use ($dobs, $mobiles, $letters) {
                if ($dobs) {
                    $w->orWhere(fn ($d) => $d->whereIn('dob', array_keys($dobs))->whereIn(DB::raw('LEFT(name_normalized, 1)'), array_keys($letters)));
                }
                if ($mobiles) {
                    $w->orWhereIn('guardian_mobile_norm', array_keys($mobiles));
                }
            });

            foreach ($q->get(['name_normalized', 'dob', 'guardian_mobile_norm', 'code']) as $p) {
                $out['byDob'][substr((string) $p->dob, 0, 10)][] = $p;
                $p->guardian_mobile_norm && $out['byMobile'][$p->guardian_mobile_norm][] = $p;
            }
        }

        if ($rolls) {
            foreach (DB::table('students')->whereIn('class_id', array_keys($classes))->whereIn('roll_live', array_keys($rolls))->get(['class_id', 'roll_live']) as $s) {
                $out['rolls'][$s->class_id . '|' . $s->roll_live] = true;
            }
        }

        return $out;
    }

    /** the code of an existing student this row probably duplicates: same date of birth or mobile AND a similar name (Student Entry's rule) */
    private function likelyDuplicate(array $x, array $inDb): ?string
    {
        $threshold = (float) config('student.duplicate_threshold', 85);
        $candidates = array_merge($inDb['byDob'][$x['personal']['dob'] ?? ''] ?? [], $x['mobile'] ? ($inDb['byMobile'][$x['mobile']] ?? []) : []);

        foreach ($candidates as $p) {
            if (Normalize::similarity($x['nameKey'], (string) $p->name_normalized) >= $threshold) {
                return $p->code;
            }
        }

        return null;
    }

    /** the whole chunk in a few bulk statements: IDs, profiles, then the enrollments that point at them */
    private function insert(array $good, User $by): void
    {
        $now = now()->toDateTimeString();
        $today = now()->toDateString();
        $codes = PersonId::reserve(PersonId::STUDENT, count($good));

        $profiles = [];

        foreach ($good as $i => $x) {
            $p = $x['personal'];
            $profiles[] = [
                'code' => $codes[$i], 'name_en' => $p['name_en'], 'name_bn' => $p['name_bn'] ?? null, 'name_normalized' => $x['nameKey'], 'brn' => $x['brn'],
                'dob' => $p['dob'] ?? null, 'gender' => $p['gender'] ?? null, 'religion' => $p['religion'] ?? null, 'blood_group' => $p['blood_group'] ?? null,
                'father_name' => $p['father_name'] ?? null, 'mother_name' => $p['mother_name'] ?? null, 'guardian_name' => $p['guardian_name'] ?? null,
                'guardian_mobile' => $p['guardian_mobile'] ?? null, 'guardian_mobile_norm' => $x['mobile'], 'guardian_relation' => $p['guardian_relation'] ?? null,
                'present_address' => $p['present_address'] ?? null, 'permanent_address' => $p['permanent_address'] ?? null, 'previous_school' => $p['previous_school'] ?? null,
                'admission_date' => $p['admission_date'] ?? $today, 'created_by' => $by->id, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($profiles, 1000) as $part) {
            DB::table('student_profiles')->insert($part);
        }

        $ids = [];

        foreach (array_chunk($codes, 5000) as $part) {
            $ids += DB::table('student_profiles')->whereIn('code', $part)->pluck('id', 'code')->all();
        }

        $rows = [];

        foreach ($good as $i => $x) {
            $class = $this->classById[$x['data']['class_id']];
            $profile = $profiles[$i];

            $row = [
                'profile_id' => $ids[$codes[$i]], 'student_id' => $codes[$i], 'academic_year' => (int) $class->academic_year, 'class_id' => $class->id,
                'study_group_id' => $class->study_group_id, 'roll_number' => $x['roll'] !== '' ? $x['roll'] : null, 'status' => 'active', 'promoted_from_id' => null,
                'public_token' => (string) Str::ulid(), 'created_at' => $now, 'updated_at' => $now,
            ];

            // the synchronized copy of the personal data on the enrollment row
            foreach (\App\Models\StudentProfile::ENROLLMENT_COLUMN as $field => $column) {
                $row[$column] = $profile[$field] ?? null;
            }

            $row['admission_date'] = $profile['admission_date'];
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 1000) as $part) {
            DB::table('students')->insert($part);
        }
    }

    /** the classes of every session this chunk mentions, loaded once per session for the whole file */
    private function loadClasses(array $rows, string $session): void
    {
        $need = [];

        foreach ($rows as $item) {
            $s = ($item['raw']['session'] ?? '') !== '' ? preg_replace('/\.0+$/', '', $item['raw']['session']) : $session;

            if (! isset($this->loadedSessions[$s])) {
                $need[$s] = true;
            }
        }

        if (! $need) {
            return;
        }

        foreach ($this->importer->classIndexFor(array_keys($need)) as $key => $classes) {
            $this->classIndex[$key] = $classes;

            foreach ($classes as $c) {
                $this->classById[$c->id] = $c;
            }
        }

        foreach ($need as $s => $_) {
            $this->loadedSessions[$s] = true;
        }
    }

    /** a 60-bit integer from a string: an array of these costs a third of the memory of one keyed by the text */
    private static function h(string $s): int
    {
        return (int) hexdec(substr(md5($s), 0, 15));
    }
}

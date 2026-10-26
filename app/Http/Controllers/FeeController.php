<?php

namespace App\Http\Controllers;

use App\Models\FeeStructure;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class FeeController extends Controller
{
    public function index()
    {
        return Inertia::render('fees');
    }

    // ─────────────────────────────────────────────
    // CREATE — form page + fee structures list (filtered + paginated)
    // ─────────────────────────────────────────────
    public function create(Request $request)
    {
        // ─── Filter inputs ───
        $selectedYear = $request->input('year', now()->year);
        $search       = trim((string) $request->input('search', ''));

        // ─── All active classes (for Create Modal dropdown) ───
        $classes = SchoolClass::query()
            ->where('status', 'active')
            ->orderByDesc('academic_year')
            ->orderBy('class_name')
            ->orderBy('section')
            ->get()
            ->map(function ($class) {
                return [
                    'id'             => $class->id,
                    'class_name'     => $class->class_name,
                    'section'        => $class->section,
                    'academic_year'  => (string) $class->academic_year,
                    'class_teacher'  => $class->class_teacher,
                    'subjects'       => $class->subjects ?? [],
                    'max_students'   => $class->max_students,
                ];
            });

        // ─── Duplicate-check helper ───
        $existingFeeStructures = [];
        if (Schema::hasTable('fee_structures')) {
            $existingFeeStructures = DB::table('fee_structures')
                ->join('classes', 'fee_structures.class_id', '=', 'classes.id')
                ->select(
                    'classes.class_name',
                    'classes.section',
                    'fee_structures.academic_year'
                )
                ->distinct()
                ->get()
                ->map(fn ($row) => [
                    'class_name'    => $row->class_name,
                    'section'       => $row->section ?? 'N/A',
                    'academic_year' => (string) $row->academic_year,
                ])
                ->toArray();
        }

        // ─── Grouped class cards (by class_name + section) for selected year ───
        $classGroups = [];

        if (Schema::hasTable('fee_structures')) {
            $groupsQuery = DB::table('fee_structures')
                ->join('classes', 'fee_structures.class_id', '=', 'classes.id')
                ->where('fee_structures.academic_year', (string) $selectedYear)
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($sub) use ($search) {
                        $sub->where('classes.class_name', 'like', "%{$search}%")
                            ->orWhere('classes.section', 'like', "%{$search}%");
                    });
                })
                ->select(
                    'classes.class_name',
                    'classes.section',
                    'fee_structures.class_id'
                )
                ->groupBy('classes.class_name', 'classes.section', 'fee_structures.class_id')
                ->orderBy('classes.class_name')
                ->orderBy('classes.section');

            $paginated = $groupsQuery->paginate(9)->withQueryString();

            foreach ($paginated->items() as $groupRow) {
                $classIds = SchoolClass::where('class_name', $groupRow->class_name)
                    ->where('section', $groupRow->section)
                    ->where('academic_year', (string) $selectedYear)
                    ->pluck('id')
                    ->toArray();

                $fees = DB::table('fee_structures')
                    ->whereIn('class_id', $classIds)
                    ->where('academic_year', (string) $selectedYear)
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($f) => [
                        'id'        => $f->id,
                        'name'      => $f->fee_name,
                        'amount'    => (float) $f->amount,
                        'cycle'     => $f->cycle,
                        'is_active' => (bool) $f->is_active,
                    ])
                    ->toArray();

                $classGroups[] = [
                    'key'           => "{$groupRow->class_name}__" . ($groupRow->section ?: 'N/A'),
                    'class_name'    => $groupRow->class_name,
                    'section'       => $groupRow->section ?: 'N/A',
                    'class_id'      => $groupRow->class_id,
                    'academic_year' => (string) $selectedYear,
                    'fees'          => $fees,
                    'totalFees'     => count($fees),
                ];
            }

            $classGroupsPaginator = [
                'data'          => $classGroups,
                'current_page'  => $paginated->currentPage(),
                'last_page'     => $paginated->lastPage(),
                'per_page'      => $paginated->perPage(),
                'total'         => $paginated->total(),
                'from'          => $paginated->firstItem(),
                'to'            => $paginated->lastItem(),
                'links'         => $paginated->linkCollection()->toArray(),
            ];
        } else {
            $classGroupsPaginator = [
                'data'          => [],
                'current_page'  => 1,
                'last_page'     => 1,
                'per_page'      => 9,
                'total'         => 0,
                'from'          => 0,
                'to'            => 0,
                'links'         => [],
            ];
        }

        // ─── Available years (for year picker) ───
        $yearsFromClasses = SchoolClass::query()
            ->where('status', 'active')
            ->whereNotNull('academic_year')
            ->pluck('academic_year')
            ->map(fn ($y) => (string) $y)
            ->toArray();

        $yearsFromFees = [];
        if (Schema::hasTable('fee_structures')) {
            $yearsFromFees = DB::table('fee_structures')
                ->whereNotNull('academic_year')
                ->distinct()
                ->pluck('academic_year')
                ->map(fn ($y) => (string) $y)
                ->toArray();
        }

        $availableYears = collect()
            ->merge($yearsFromClasses)
            ->merge($yearsFromFees)
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->toArray();

        $currentYear = (string) now()->year;
        if (! in_array($currentYear, $availableYears, true)) {
            $availableYears[] = $currentYear;
            rsort($availableYears);
        }

        return Inertia::render('createfees', [
            'classes'                => $classes,
            'existingFeeStructures'  => $existingFeeStructures,
            'classGroups'            => $classGroupsPaginator,
            'availableYears'         => $availableYears,
            'filters'                => [
                'year'   => (string) $selectedYear,
                'search' => $search,
            ],
            'flash'                  => session()->only(['success', 'error']),
        ]);
    }

    // ─────────────────────────────────────────────
    // STORE — Smart save:
    //   • replace_fees = false  → MERGE (create mode)
    //   • replace_fees = true   → REPLACE (edit mode)
    // ─────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id'                 => 'required|integer|exists:classes,id',
            'academic_year'            => 'required|string|max:10',
            'fee_types'                => 'required|array|min:1',
            'fee_types.*.name'         => 'required|string|max:100',
            'fee_types.*.amount'       => 'required|numeric|min:0',
            'fee_types.*.cycle'        => 'required|string|max:50',
            'fee_types.*.is_active'    => 'required|boolean',
            'replace_fees'             => 'nullable|boolean',
        ]);

        if (! Schema::hasTable('fee_structures')) {
            return back()->with('error', '⚠️ `fee_structures` table not found. Run: php artisan migrate');
        }

        $activeFees = collect($validated['fee_types'])
            ->filter(fn ($f) => ! empty($f['is_active']))
            ->values()
            ->all();

        if (empty($activeFees)) {
            return back()->with('error', '⚠️ Please add at least one active fee type.');
        }

        $class       = SchoolClass::findOrFail($validated['class_id']);
        $user        = Auth::user();
        $year        = (string) $validated['academic_year'];
        $replaceFees = (bool) ($validated['replace_fees'] ?? false);

        try {
            DB::transaction(function () use ($class, $user, $year, $activeFees, $replaceFees) {
                $now      = now();
                $classIds = [$class->id];
                $rows     = [];

                if ($replaceFees) {
                    // ✅ EDIT MODE — full replace
                    DB::table('fee_structures')
                        ->whereIn('class_id', $classIds)
                        ->where('academic_year', $year)
                        ->delete();

                    foreach ($classIds as $cid) {
                        foreach ($activeFees as $feeType) {
                            $rows[] = [
                                'class_id'      => $cid,
                                'academic_year' => $year,
                                'fee_name'      => $feeType['name'],
                                'amount'        => $feeType['amount'],
                                'cycle'         => $feeType['cycle'],
                                'is_active'     => true,
                                'created_by'    => $user?->id,
                                'created_at'    => $now,
                                'updated_at'    => $now,
                            ];
                        }
                    }
                } else {
                    // ✅ CREATE MODE — merge by fee_name
                    foreach ($classIds as $cid) {
                        foreach ($activeFees as $feeType) {
                            $existing = DB::table('fee_structures')
                                ->where('class_id', $cid)
                                ->where('academic_year', $year)
                                ->where('fee_name', $feeType['name'])
                                ->first();

                            if ($existing) {
                                // Update existing fee
                                DB::table('fee_structures')
                                    ->where('id', $existing->id)
                                    ->update([
                                        'amount'     => $feeType['amount'],
                                        'cycle'      => $feeType['cycle'],
                                        'is_active'  => true,
                                        'updated_at' => $now,
                                    ]);
                            } else {
                                // Insert new fee
                                $rows[] = [
                                    'class_id'      => $cid,
                                    'academic_year' => $year,
                                    'fee_name'      => $feeType['name'],
                                    'amount'        => $feeType['amount'],
                                    'cycle'         => $feeType['cycle'],
                                    'is_active'     => true,
                                    'created_by'    => $user?->id,
                                    'created_at'    => $now,
                                    'updated_at'    => $now,
                                ];
                            }
                        }
                    }
                }

                if (! empty($rows)) {
                    DB::table('fee_structures')->insert($rows);
                }
            });

        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', '❌ Failed to save fee structure: ' . $e->getMessage());
        }

        $sectionLabel = $class->section ? " - Section {$class->section}" : '';
        $mode         = $replaceFees ? 'updated' : 'saved';

        return redirect()
            ->route('fees.create', $request->only(['year', 'search', 'page']))
            ->with('success', "✅ Fee structure {$mode} for {$class->class_name}{$sectionLabel} ({$year}) — " . count($activeFees) . ' fee type(s).');
    }

    // ─────────────────────────────────────────────
    // DELETE — only the specific section (class_id + year)
    // ─────────────────────────────────────────────
    public function destroyGroup(Request $request)
    {
        $validated = $request->validate([
            'class_id'      => 'required|integer|exists:classes,id',
            'academic_year' => 'required|string|max:10',
        ]);

        if (! Schema::hasTable('fee_structures')) {
            return back()->with('error', '⚠️ `fee_structures` table not found.');
        }

        $class = SchoolClass::findOrFail($validated['class_id']);

        try {
            DB::transaction(function () use ($validated, $class) {
                DB::table('fee_structures')
                    ->where('class_id', $class->id)
                    ->where('academic_year', $validated['academic_year'])
                    ->delete();
            });

        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', '❌ Failed to delete: ' . $e->getMessage());
        }

        $sectionLabel = $class->section ? " - Section {$class->section}" : '';

        return redirect()
            ->route('fees.create', $request->only(['year', 'search', 'page']))
            ->with('success', "🗑️ Fee structure deleted for {$class->class_name}{$sectionLabel} ({$validated['academic_year']}).");
    }
}
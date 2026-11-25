<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\TabulationBuilder;
use Illuminate\Http\Request;

/**
 * The printable tabulation sheet of a class: every student, every subject, totals, GPA,
 * position and attendance — generated from the marks and attendance already in the system.
 */
class TabulationController extends Controller
{
    public function show(Request $request, SchoolClass $class, TabulationBuilder $builder)
    {
        // same rule as the complete class result: the class teacher, the exam controller, the admin
        abort_unless($request->user()->canDo('results.compile', $class), 403, 'You may not open the tabulation of this class.');

        $examType = (string) $request->input('exam_type', 'final');
        $perSheet = (int) $request->input('per_sheet', TabulationBuilder::SUBJECTS_PER_SHEET);

        $t = $builder->build($class, $examType, $perSheet);

        return view('tabulation', [
            't'          => $t,
            'examTypes'  => TabulationBuilder::EXAM_TYPES,
            'perSheet'   => max(3, min(20, $perSheet)),
            'classId'    => $class->id,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //

    /**
     * Which study group the user means for a student's class: the group they picked, else the
     * student's own, else the group of the student's current class.
     */
    protected function studyGroupHint(\Illuminate\Http\Request $request, ?\App\Models\Student $student = null): ?int
    {
        $id = $request->input('studyGroup')
            ?: ($student?->study_group_id ?: $student?->schoolClass?->study_group_id);

        return $id ? (int) $id : null;
    }

    /**
     * Find the class a form means. The student's own shift and medium break a tie; when they
     * would rule every class out (the student is moving to a class that exists in one shift only)
     * the plain lookup is used instead.
     */
    protected function resolveClass(\Illuminate\Http\Request $request, ?\App\Models\Student $student, ?string $name, ?string $section, $year): ?\App\Models\SchoolClass
    {
        $group = $this->studyGroupHint($request, $student);

        $class = \App\Models\SchoolClass::resolveActive($name, $section, $year, $group, $this->shiftHint($request, $student), $this->mediumHint($request, $student));

        if (! $class && ! $request->filled('shiftId') && ! $request->filled('mediumId')) {
            $class = \App\Models\SchoolClass::resolveActive($name, $section, $year, $group);
        }

        return $class;
    }

    protected function resolveClassError(\Illuminate\Http\Request $request, ?\App\Models\Student $student, ?string $name, ?string $section, $year): string
    {
        return \App\Models\SchoolClass::resolutionError(
            $name, $section, $year, $this->studyGroupHint($request, $student),
            $request->filled('shiftId') ? (int) $request->input('shiftId') : null,
            $request->filled('mediumId') ? (int) $request->input('mediumId') : null,
        );
    }

    /** The shift the user picked on a form, else the student's own class's shift. */
    protected function shiftHint(\Illuminate\Http\Request $request, ?\App\Models\Student $student = null): ?int
    {
        $id = $request->input('shiftId') ?: $student?->schoolClass?->shift_id;

        return $id ? (int) $id : null;
    }

    /** The medium the user picked on a form, else the student's own class's medium. */
    protected function mediumHint(\Illuminate\Http\Request $request, ?\App\Models\Student $student = null): ?int
    {
        $id = $request->input('mediumId') ?: $student?->schoolClass?->medium_id;

        return $id ? (int) $id : null;
    }
}

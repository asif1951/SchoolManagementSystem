<?php

namespace App\Http\Controllers\Teaching;

use App\Services\Teaching\Access;
use App\Services\Teaching\Insights;
use App\Services\Teaching\Progress;
use App\Services\Teaching\Rows;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Progress and evaluation: planned against actual, delayed chapters, review comments. */
class ProgressController extends TeachingController
{
    public function index(Request $request, Rows $rows, Insights $insights, Progress $progress)
    {
        $user = $this->need($request);
        $years = $this->years();
        $year = $this->year($request, $years);
        $progress->ensureFresh($year);
        $only = Access::limitTo($user);
        $filters = ['teacher' => $only ?? ((int) $request->input('teacher') ?: null), 'health' => (string) $request->input('health') ?: null];
        $by = $request->input('by') === 'class' ? 'class' : 'subject';
        $page = $rows->plans($year, $filters)->orderBy('p.gap')->orderBy('p.id')->simplePaginate(25)->withQueryString();

        return Inertia::render('Teaching/Progress', [
            'years' => $years, 'session' => $year, 'filters' => $filters, 'by' => $by,
            'summary' => $insights->summary($year, $only ?? $filters['teacher']),
            'groups' => $insights->completion($year, $by, $only ?? $filters['teacher']),
            'plans' => ['data' => $rows->present($page->items()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl(), 'page' => $page->currentPage()],
            'delayed' => $insights->delayedTop($year, $filters),
            'seesAll' => Access::seesAll($user), 'teachers' => Access::seesAll($user) ? $this->teachers() : [],
            'canReview' => $user->canDo('teaching.review') || $user->canDo('teaching.approve'),
            'flash' => $this->flash(),
        ]);
    }
}

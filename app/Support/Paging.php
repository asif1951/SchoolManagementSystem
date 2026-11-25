<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\Paginator;

/**
 * Pages for lists of any size. A page is read with "limit n+1" (it knows whether a next page exists without counting
 * anything); the heading count comes separately, capped, from the caller. The shape matches what the pages already
 * render: data, links (previous / current / next) and total.
 */
final class Paging
{
    /**
     * @param  array{n:int, capped:bool}  $count
     */
    public static function shape(Paginator $page, array $count): array
    {
        $links = [];

        if ($page->previousPageUrl() || $page->nextPageUrl()) {
            $links = [
                ['url' => $page->previousPageUrl(), 'label' => '&laquo; Previous', 'active' => false],
                ['url' => null, 'label' => 'Page ' . $page->currentPage(), 'active' => true],
                ['url' => $page->nextPageUrl(), 'label' => 'Next &raquo;', 'active' => false],
            ];
        }

        $n = count($page->items());
        $from = $n ? ($page->currentPage() - 1) * $page->perPage() + 1 : 0;

        return [
            'from' => $from, 'to' => $n ? $from + $n - 1 : 0,
            'data' => $page->items(), 'links' => $links, 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $count['n'], 'capped' => $count['capped'],
        ];
    }
}

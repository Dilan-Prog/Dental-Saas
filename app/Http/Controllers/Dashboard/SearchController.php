<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\SearchRequest;
use App\Services\Dashboard\SearchService;

class SearchController extends Controller
{
    public function index(SearchRequest $request)
    {
        $term = (string) ($request->validated('q') ?? '');
        $groups = (new SearchService($request->user()))->search($term);

        if ($request->wantsJsonResult()) {
            return response()->json([
                'q' => $term,
                'min' => SearchService::MIN_LENGTH,
                'groups' => $groups,
            ]);
        }

        $total = collect($groups)->sum(fn ($g) => count($g['items']));

        return view('dashboard.search', [
            'term' => $term,
            'groups' => $groups,
            'total' => $total,
            'tooShort' => $term !== '' && mb_strlen($term) < SearchService::MIN_LENGTH,
        ]);
    }
}

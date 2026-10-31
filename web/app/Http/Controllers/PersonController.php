<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Services\GraphSearch;
use Throwable;

class PersonController extends Controller
{
    /** Halaman satu orang (aktor, sutradara, penulis, atau komposer) beserta semua filmnya. */
    public function show(int $id, GraphSearch $search)
    {
        try {
            $person = $search->person($id);
        } catch (Throwable $e) {
            report($e);
            abort(503, 'Server data semantik (Fuseki) tidak dapat dihubungi.');
        }
        abort_if($person === null, 404, 'Orang ini tidak ada di dataset RDF.');

        $slugs = array_column($person['films'], 'slug');
        $ratings = $slugs === []
            ? collect()
            : Movie::whereIn('slug', $slugs)->get(['slug', 'avg_rating', 'reviews_count'])->keyBy('slug');

        return view('person', [
            'person'   => $person,
            'ratings'  => $ratings,
            'cinNs'    => rtrim(config('cinelog.base_uri'), '/') . '/ontology#',
            'pageUrl'  => route('person.show', ['id' => $id]),
        ]);
    }
}
<?php

return [
    // Alamat dataset Fuseki. Dari DALAM container 'app', Fuseki dijangkau lewat nama layanan 'fuseki'.
    'fuseki_url' => env('FUSEKI_URL', 'http://fuseki:3030/cinelog'),

    // Awalan IRI resource RDF. HARUS sama dengan BASE_URI yang dipakai pipeline/04_build_rdf.py.
    'base_uri' => env('CINELOG_BASE_URI', 'http://localhost:8000/'),

    // Basis URL gambar TMDB (poster, foto pemeran).
    'tmdb_image_base' => 'https://image.tmdb.org/t/p/',
];

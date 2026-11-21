<?php

namespace App\Http\Controllers;

class ReviewController extends Controller
{
    public static function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,10'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:10000'],
            'contains_spoiler' => ['sometimes', 'boolean'],
        ];
    }
}

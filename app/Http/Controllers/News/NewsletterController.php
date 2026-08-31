<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Services\News\NewsletterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function store(Request $request, NewsletterService $newsletter): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'consent' => ['accepted'],
            'source' => ['nullable', 'string', 'max:80'],
        ]);

        $newsletter->subscribe(
            $validated['email'],
            true,
            $validated['source'] ?? 'public',
        );

        return ApiResponse::success('If you consented, the office has recorded the address for a future newsletter. Nothing is sent automatically.');
    }
}

<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsImageController extends Controller
{
    public function show(string $path): StreamedResponse
    {
        $relative = 'news/'.str_replace('\\', '/', $path);

        abort_if(str_contains($relative, '..'), 404);
        abort_unless(Storage::disk('public')->exists($relative), 404);

        $mime = (string) (Storage::disk('public')->mimeType($relative) ?: '');
        abort_unless(str_starts_with($mime, 'image/'), 404);

        return Storage::disk('public')->response($relative);
    }
}

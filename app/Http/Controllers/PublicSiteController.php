<?php

namespace App\Http\Controllers;

use App\Support\FrontendPage;
use Illuminate\Http\Response;

class PublicSiteController extends Controller
{
    public function __construct(private readonly FrontendPage $frontend) {}

    public function home(): Response
    {
        return $this->frontend->response('public/index.html', area: 'public');
    }

    public function page(string $page): Response
    {
        $mapped = str_ends_with($page, '.html') ? substr($page, 0, -5) : $page;

        return $this->frontend->response('public/'.$mapped.'.html', area: 'public');
    }
}

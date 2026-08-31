<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\FrontendPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminPortalController extends Controller
{
    public function __construct(private readonly FrontendPage $frontend) {}

    public function page(Request $request, string $page): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9_\-]+\.html$/', $page) === 1, 404);

        return $this->frontend->response('admin/'.$page, [
            '<head>' => '<head>'."\n".'  <base href="/site/portal/">',
        ]);
    }
}

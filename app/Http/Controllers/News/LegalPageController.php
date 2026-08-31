<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LegalPageController extends Controller
{
    public function privacy(): View
    {
        return view('site.legal.privacy', ['indexable' => true]);
    }

    public function terms(): View
    {
        return view('site.legal.terms', ['indexable' => true]);
    }
}

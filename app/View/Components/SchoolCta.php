<?php

namespace App\View\Components;

use App\Models\Post;
use App\Services\News\SchoolCtaResolver;
use Illuminate\View\Component;
use Illuminate\View\View;

class SchoolCta extends Component
{
    public function __construct(
        public ?string $type = null,
        public ?string $strength = null,
        public ?Post $article = null,
    ) {}

    public function render(): View|string
    {
        $resolved = app(SchoolCtaResolver::class)->for($this->article, $this->type, $this->strength);

        if ($resolved === null) {
            return '';
        }

        return view('components.school-cta', [
            'cta' => $resolved,
            'article' => $this->article,
        ]);
    }
}

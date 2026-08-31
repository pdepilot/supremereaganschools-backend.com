<?php

namespace App\Services\News;

use App\Enums\PostContentType;
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class HomepageJournalService
{
    public function __construct(private readonly ResourceHubService $hubs) {}

    /**
     * Compact crawlable HTML for the public homepage. Empty when there is nothing useful to show.
     */
    public function html(): string
    {
        if (! Schema::hasTable('posts')) {
            return '';
        }

        $latest = $this->latest(3);
        $featured = $this->featured();
        $parents = $this->hubs->parentResources(3);
        $admissions = $this->admissionNotes(2);

        if ($latest->isEmpty() && $featured === null && $parents->isEmpty() && $admissions->isEmpty()) {
            return '';
        }

        $parts = ['<section class="home-journal" aria-label="From the house journal">'];
        $parts[] = '<div class="home-journal-inner">';
        $parts[] = '<p class="home-journal-kicker">News &amp; Insights</p>';
        $parts[] = '<h2>From the education desk</h2>';
        $parts[] = '<p class="home-journal-lead">Useful notes for families — then the school, if you wish to know us.</p>';

        if ($featured) {
            $parts[] = $this->card($featured, 'Featured', true);
        }

        if ($latest->isNotEmpty()) {
            $parts[] = '<div class="home-journal-grid">';
            $parts[] = '<article><h3>Latest news</h3><ul>';
            foreach ($latest as $post) {
                $parts[] = '<li><a href="'.e($post->publicUrl()).'">'.e($post->title).'</a></li>';
            }
            $parts[] = '</ul><p><a href="/news">All News &amp; Insights</a></p></article>';

            if ($parents->isNotEmpty()) {
                $parts[] = '<article><h3>Parent resources</h3><ul>';
                foreach ($parents as $post) {
                    $parts[] = '<li><a href="'.e($post->publicUrl()).'">'.e($post->title).'</a></li>';
                }
                $parts[] = '</ul><p><a href="/resources/parenting">Parent resource hub</a></p></article>';
            }

            if ($admissions->isNotEmpty()) {
                $parts[] = '<article><h3>Admissions</h3><ul>';
                foreach ($admissions as $post) {
                    $parts[] = '<li><a href="'.e($post->publicUrl()).'">'.e($post->title).'</a></li>';
                }
                $parts[] = '</ul><p><a href="/admissions">Admissions at the house</a></p></article>';
            }

            $parts[] = '</div>';
        }

        $parts[] = '<p class="home-journal-cta"><a href="/resources">Education &amp; parent resources</a> · <a href="/admissions">Admissions</a> · <a href="/contact">Contact</a></p>';
        $parts[] = '</div></section>';

        return implode('', $parts);
    }

    /**
     * @return Collection<int, Post>
     */
    public function latest(int $limit = 3): Collection
    {
        return Post::query()
            ->publiclyVisible()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    public function featured(): ?Post
    {
        return Post::query()
            ->publiclyVisible()
            ->where('is_featured', true)
            ->orderByDesc('published_at')
            ->first();
    }

    /**
     * @return Collection<int, Post>
     */
    public function admissionNotes(int $limit = 3): Collection
    {
        return Post::query()
            ->publiclyVisible()
            ->where(function ($q) {
                $q->where('content_type', PostContentType::AdmissionUpdate)
                    ->orWhereHas('category', fn ($c) => $c->where('slug', 'admissions'));
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    private function card(Post $post, string $label, bool $feature = false): string
    {
        $class = $feature ? 'home-journal-feature' : 'home-journal-card';

        return '<article class="'.$class.'"><p class="home-journal-label">'.e($label).'</p><h3><a href="'.e($post->publicUrl()).'">'.e($post->title).'</a></h3><p>'.e((string) $post->excerpt).'</p></article>';
    }
}

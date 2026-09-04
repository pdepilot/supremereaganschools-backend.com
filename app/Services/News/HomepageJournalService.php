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
     *
     * The homepage always spotlights the single newest published article. Publishing a newer
     * article replaces that slot; older articles remain on /news.
     */
    public function html(): string
    {
        if (! Schema::hasTable('posts')) {
            return '';
        }

        $current = $this->latest(1)->first();
        $parents = $this->hubs->parentResources(3);
        $admissions = $this->admissionNotes(2);

        if ($current === null && $parents->isEmpty() && $admissions->isEmpty()) {
            return '';
        }

        $parts = ['<section class="home-journal" aria-label="From the house journal">'];
        $parts[] = '<div class="home-journal-inner">';
        $parts[] = '<div class="school-section-heading text-center">';
        $parts[] = '<span class="section-label">News &amp; Insights</span>';
        $parts[] = '<h2>From the education desk</h2>';
        $parts[] = '<p>Useful notes for families — then the school, if you wish to know us.</p>';
        $parts[] = '</div>';

        if ($current) {
            $parts[] = $this->card($current, 'Latest news', true);
        }

        if ($parents->isNotEmpty() || $admissions->isNotEmpty()) {
            $parts[] = '<div class="home-journal-grid">';

            if ($parents->isNotEmpty()) {
                $parts[] = '<article class="home-journal-panel"><h3>Parent resources</h3><ul>';
                foreach ($parents as $post) {
                    $parts[] = '<li><a href="'.e($post->publicUrl()).'">'.e($post->title).'</a></li>';
                }
                $parts[] = '</ul><p class="home-journal-more"><a href="/resources/parenting">Parent resource hub</a></p></article>';
            }

            if ($admissions->isNotEmpty()) {
                $parts[] = '<article class="home-journal-panel"><h3>Admissions</h3><ul>';
                foreach ($admissions as $post) {
                    $parts[] = '<li><a href="'.e($post->publicUrl()).'">'.e($post->title).'</a></li>';
                }
                $parts[] = '</ul><p class="home-journal-more"><a href="/admissions">Admissions at the house</a></p></article>';
            }

            $parts[] = '</div>';
        }

        $parts[] = '<p class="home-journal-cta"><a href="/news">All News &amp; Insights</a><span aria-hidden="true">·</span><a href="/resources">Education &amp; parent resources</a><span aria-hidden="true">·</span><a href="/admissions">Admissions</a><span aria-hidden="true">·</span><a href="/contact">Contact</a></p>';
        $parts[] = '</div></section>';

        return implode('', $parts);
    }

    /**
     * @return Collection<int, Post>
     */
    public function latest(int $limit = 1): Collection
    {
        return Post::query()
            ->publiclyVisible()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function featured(): ?Post
    {
        return $this->latest(1)->first();
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


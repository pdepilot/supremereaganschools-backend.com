<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EventsPageService
{
    /**
     * @return array{featured: string, timeline: string}
     */
    public function pageFragments(): array
    {
        if (! Schema::hasTable('events')) {
            return [
                'featured' => $this->emptyFeatured(),
                'timeline' => $this->emptyTimeline(),
            ];
        }

        $upcoming = $this->upcoming(50);
        $featured = $upcoming->firstWhere('is_featured', true) ?? $upcoming->first();

        return [
            'featured' => $featured ? $this->featuredHtml($featured) : $this->emptyFeatured(),
            'timeline' => $upcoming->isNotEmpty()
                ? $this->timelineHtml($upcoming)
                : $this->emptyTimeline(),
        ];
    }

    public function homeHtml(): string
    {
        if (! Schema::hasTable('events')) {
            return $this->homeEmpty();
        }

        $upcoming = $this->upcoming(3);

        if ($upcoming->isEmpty()) {
            return $this->homeEmpty();
        }

        $parts = ['<section class="home-events" aria-label="Upcoming events">'];
        $parts[] = '<div class="home-events-inner">';
        $parts[] = '<div class="home-events-intro">';
        $parts[] = '<span class="section-label">UPCOMING</span>';
        $parts[] = '<h2>The house calendar</h2>';
        $parts[] = '<p>Days of gathering, sport, culture and ceremony — marked for families. See what is coming next on campus.</p>';
        $parts[] = '<a class="home-events-all" href="/events">View all events</a>';
        $parts[] = '</div>';
        $parts[] = '<ol class="home-events-list">';

        foreach ($upcoming as $event) {
            $parts[] = $this->homeItem($event);
        }

        $parts[] = '</ol></div></section>';

        return implode('', $parts);
    }

    /**
     * @return Collection<int, Event>
     */
    public function upcoming(int $limit = 20): Collection
    {
        return Event::query()
            ->published()
            ->upcoming()
            ->limit($limit)
            ->get();
    }

    public function hasUpcomingPublished(): bool
    {
        if (! Schema::hasTable('events')) {
            return false;
        }

        return Event::query()->published()->upcoming()->exists();
    }

    private function featuredHtml(Event $event): string
    {
        $image = $event->coverImageUrl() ?: '/site/Image/school_view2.jpg';
        $alt = e($event->cover_image_alt ?: $event->title);
        $when = e($this->longDate($event));
        $iso = e($event->starts_at?->toDateString() ?? '');

        return <<<HTML
  <section class="events-featured" aria-label="Next gathering">
    <div class="events-featured-inner">
      <div class="events-featured-copy">
        <p class="section-label">NEXT ON THE ROLL</p>
        <p class="events-date-line"><time datetime="{$iso}">{$when}</time></p>
        <h2>{$this->e($event->title)}</h2>
        <p>{$this->e($event->summary)}</p>
        <a class="events-text-link" href="/contact">Plan a visit</a>
      </div>
      <figure class="events-featured-media">
        <img src="{$this->e($image)}" alt="{$alt}">
      </figure>
    </div>
  </section>
HTML;
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function timelineHtml(Collection $events): string
    {
        $items = $events->map(fn (Event $event) => $this->timelineItem($event))->implode('');

        return <<<HTML
  <section class="events-roll" id="events-roll" aria-label="Upcoming events list">
    <div class="events-roll-inner">
      <div class="school-section-heading text-center">
        <span class="section-label">THE SEASON AHEAD</span>
        <h2>Upcoming events</h2>
        <p>From sports and culture to careers and ceremony — the life of the house, dated for your diary.</p>
      </div>
      <ol class="events-timeline">{$items}</ol>
    </div>
  </section>
HTML;
    }

    private function timelineItem(Event $event): string
    {
        $day = e($event->starts_at?->format('d') ?? '');
        $mon = e($event->starts_at?->format('M') ?? '');
        $iso = e($event->starts_at?->toDateString() ?? '');
        $tag = e($event->category ?: 'House');
        $meta = e($this->metaLine($event));

        return <<<HTML
        <li>
          <time datetime="{$iso}">
            <span>{$day}</span>
            <small>{$mon}</small>
          </time>
          <div>
            <p class="events-tag">{$tag}</p>
            <h3>{$this->e($event->title)}</h3>
            <p>{$this->e($event->summary)}</p>
            <span>{$meta}</span>
          </div>
        </li>
HTML;
    }

    private function homeItem(Event $event): string
    {
        $day = e($event->starts_at?->format('d') ?? '');
        $mon = e($event->starts_at?->format('M') ?? '');
        $iso = e($event->starts_at?->toDateString() ?? '');
        $tag = e($event->category ?: 'House');
        $meta = e($this->homeMeta($event));

        return <<<HTML
      <li>
        <time datetime="{$iso}"><span>{$day}</span><small>{$mon}</small></time>
        <div>
          <p>{$tag}</p>
          <h3>{$this->e($event->title)}</h3>
          <span>{$meta}</span>
        </div>
      </li>
HTML;
    }

    private function emptyFeatured(): string
    {
        return <<<'HTML'
  <section class="events-featured" aria-label="Next gathering">
    <div class="events-featured-inner">
      <div class="events-featured-copy">
        <p class="section-label">NEXT ON THE ROLL</p>
        <h2>Calendar coming soon</h2>
        <p>The office is preparing the next gatherings of the house. Check back shortly, or speak with admissions.</p>
        <a class="events-text-link" href="/contact">Speak with admissions</a>
      </div>
      <figure class="events-featured-media">
        <img src="/site/Image/school_view2.jpg" alt="Supreme Reagan Schools campus grounds">
      </figure>
    </div>
  </section>
HTML;
    }

    private function emptyTimeline(): string
    {
        return <<<'HTML'
  <section class="events-roll" id="events-roll" aria-label="Upcoming events list">
    <div class="events-roll-inner">
      <div class="school-section-heading text-center">
        <span class="section-label">THE SEASON AHEAD</span>
        <h2>Upcoming events</h2>
        <p>New dates will appear here as the office publishes them.</p>
      </div>
      <p class="events-empty">No upcoming events are published yet.</p>
    </div>
  </section>
HTML;
    }

    private function homeEmpty(): string
    {
        return <<<'HTML'
<section class="home-events" aria-label="Upcoming events">
  <div class="home-events-inner">
    <div class="home-events-intro">
      <span class="section-label">UPCOMING</span>
      <h2>The house calendar</h2>
      <p>New gatherings will be listed here as the office publishes them.</p>
      <a class="home-events-all" href="/events">View all events</a>
    </div>
    <p class="home-events-empty">Calendar coming soon.</p>
  </div>
</section>
HTML;
    }

    private function longDate(Event $event): string
    {
        $starts = $event->starts_at;
        if ($starts === null) {
            return '';
        }

        return $starts->timezone(config('app.timezone'))->format('l · j F Y');
    }

    private function metaLine(Event $event): string
    {
        $parts = [];
        if ($event->starts_at) {
            $parts[] = $event->starts_at->timezone(config('app.timezone'))->format('g:i a');
        }
        if (filled($event->location)) {
            $parts[] = $event->location;
        }

        return implode(' · ', $parts) ?: 'On campus';
    }

    private function homeMeta(Event $event): string
    {
        $parts = [];
        if ($event->starts_at) {
            $parts[] = $event->starts_at->timezone(config('app.timezone'))->format('l · g:i a');
        }
        if (filled($event->location)) {
            $parts[] = $event->location;
        }

        return implode(' · ', $parts) ?: 'On campus';
    }

    private function e(?string $value): string
    {
        return e((string) $value);
    }
}

<?php

namespace App\Services\News;

use App\Enums\ContentIntent;
use App\Enums\CtaDestination;
use App\Enums\CtaStrength;
use App\Enums\PostContentType;
use App\Models\Post;

class SchoolCtaResolver
{
    /**
     * @return array{type: CtaDestination, strength: CtaStrength, heading: string, body: string, links: list<array{href: string, label: string}>}|null
     */
    public function for(?Post $post = null, ?string $type = null, ?string $strength = null): ?array
    {
        $destination = CtaDestination::tryFrom((string) ($type ?: $post?->cta_type?->value ?? ''))
            ?? $this->autoDestination($post);

        $level = CtaStrength::tryFrom((string) ($strength ?: $post?->cta_strength?->value ?? 'standard'))
            ?? CtaStrength::Standard;

        if ($destination === CtaDestination::None || $level === CtaStrength::None) {
            return null;
        }

        return [
            'type' => $destination,
            'strength' => $level,
            'heading' => $this->heading($destination, $level, $post),
            'body' => $this->body($destination, $level, $post),
            'links' => $this->links($destination, $level),
        ];
    }

    public function autoDestination(?Post $post): CtaDestination
    {
        if ($post === null) {
            return CtaDestination::About;
        }

        if ($post->cta_type instanceof CtaDestination && $post->cta_type !== CtaDestination::None) {
            return $post->cta_type;
        }

        if ($post->content_type === PostContentType::AdmissionUpdate || $post->intent === ContentIntent::Admissions) {
            return CtaDestination::Admissions;
        }

        $slug = $post->category?->slug;

        return match ($slug) {
            'parenting' => CtaDestination::ParentResources,
            'admissions' => CtaDestination::Admissions,
            'education', 'academic-resources', 'examinations' => CtaDestination::Academics,
            'student-life', 'events', 'student-development' => CtaDestination::StudentLife,
            'school-news', 'school-community' => CtaDestination::About,
            default => CtaDestination::About,
        };
    }

    private function heading(CtaDestination $type, CtaStrength $strength, ?Post $post): string
    {
        $soft = $strength === CtaStrength::Soft;

        return match ($type) {
            CtaDestination::Admissions => $soft
                ? 'If you are exploring a school for your child'
                : 'Learn about admissions at Supreme Reagan Schools',
            CtaDestination::Contact => $soft
                ? 'A question for the office'
                : 'Contact Supreme Reagan Schools',
            CtaDestination::Academics => $soft
                ? 'See how the house teaches'
                : 'Explore academics at Supreme Reagan Schools',
            CtaDestination::StudentLife => $soft
                ? 'Life beyond the lesson'
                : 'Student life at Supreme Reagan Schools',
            CtaDestination::ParentResources => $soft
                ? 'More guidance for families'
                : 'Parent resources from the house',
            CtaDestination::About, CtaDestination::None => $soft
                ? 'Explore Supreme Reagan Schools'
                : 'Who Supreme Reagan Schools is',
        };
    }

    private function body(CtaDestination $type, CtaStrength $strength, ?Post $post): string
    {
        $category = $post?->category?->name;

        return match ($type) {
            CtaDestination::Admissions => $strength === CtaStrength::Strong
                ? 'If this guidance is useful, the next honest step is to see how a child joins the house — entry, documents, and what the office will ask.'
                : 'When a family is ready, admissions at Supreme Reagan Schools is the place to read entry guidance and write to the office.',
            CtaDestination::Contact => 'The office receives letters from families. Write with the child’s intended level so the right desk can answer.',
            CtaDestination::Academics => $category
                ? 'This note sits beside the rooms we keep. Nursery, primary, and secondary pages describe how the house actually teaches.'
                : 'Nursery, primary, and secondary pages describe the rooms, not a slogan.',
            CtaDestination::StudentLife => 'Clubs, sport, and the ordinary life of the house sit on the campus and student-life pages — not as decoration, as the work after the lesson.',
            CtaDestination::ParentResources => 'Other notes for parents — study, examinations, and school decisions — live in the parent resource hub.',
            CtaDestination::About, CtaDestination::None => 'Supreme Reagan Schools is a house in Amakohia-Akwakuma, Owerri. Read who we are, then the rooms, then admissions — in that order, if it helps.',
        };
    }

    /**
     * @return list<array{href: string, label: string}>
     */
    private function links(CtaDestination $type, CtaStrength $strength): array
    {
        $primary = match ($type) {
            CtaDestination::Admissions => [['href' => '/admissions', 'label' => 'View admissions'], ['href' => '/contact', 'label' => 'Contact the school']],
            CtaDestination::Contact => [['href' => '/contact', 'label' => 'Write to the office'], ['href' => '/admissions', 'label' => 'Admissions']],
            CtaDestination::Academics => [['href' => '/secondary', 'label' => 'Secondary'], ['href' => '/primary', 'label' => 'Primary'], ['href' => '/nursery', 'label' => 'Nursery']],
            CtaDestination::StudentLife => [['href' => '/about', 'label' => 'About the school'], ['href' => '/branches', 'label' => 'Campus']],
            CtaDestination::ParentResources => [['href' => '/resources/parenting', 'label' => 'Parent resources'], ['href' => '/resources', 'label' => 'Education hub']],
            CtaDestination::About, CtaDestination::None => [['href' => '/about', 'label' => 'About the school'], ['href' => '/admissions', 'label' => 'Admissions']],
        };

        if ($strength === CtaStrength::Strong && $type !== CtaDestination::Admissions) {
            $primary[] = ['href' => '/admissions', 'label' => 'View admissions'];
        }

        if ($strength === CtaStrength::Soft) {
            return array_slice($primary, 0, 2);
        }

        $primary[] = ['href' => '/contact', 'label' => 'Contact'];

        $unique = [];
        foreach ($primary as $link) {
            $unique[$link['href']] = $link;
        }

        return array_values($unique);
    }
}

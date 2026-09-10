<?php

namespace App\Support;

/**
 * SEO metadata for public marketing HTML pages served via FrontendPage.
 *
 * Descriptions and titles describe only content already on those pages.
 * No invented awards, fees, rankings, or statistics.
 */
class MarketingSeo
{
    /**
     * @return array{title: string, description: string, path: string, image: string}|null
     */
    public static function forPublicFile(string $relativePath): ?array
    {
        $key = match ($relativePath) {
            'public/index.html' => 'home',
            'public/about.html' => 'about',
            'public/admissions.html' => 'admissions',
            'public/contact.html' => 'contact',
            'public/nursery.html' => 'nursery',
            'public/primary.html' => 'primary',
            'public/secondary.html' => 'secondary',
            'public/branches.html' => 'branches',
            'public/alumni.html' => 'alumni',
            default => null,
        };

        return $key === null ? null : static::page($key);
    }

    /**
     * @return array{title: string, description: string, path: string, image: string}
     */
    public static function page(string $key): array
    {
        $pages = [
            'home' => [
                'title' => 'Supreme Reagan Schools | Knowledge · Character · Excellence',
                'description' => 'Supreme Reagan Schools in Amakohia-Akwakuma, Owerri — Nursery through Secondary. Knowledge · Character · Excellence. Visit admissions to begin enrolment.',
                'path' => '/',
                'image' => '/site/Image/logo_main.png',
            ],
            'about' => [
                'title' => 'About Us | Supreme Reagan Schools',
                'description' => 'Founded on 13 September 2010 by Dr. Anthony Ibeaja and Mrs. Ezinne Ibeaja. Learn our mission, vision, GROW values, and the house at Amakohia-Akwakuma, Owerri.',
                'path' => '/about',
                'image' => '/site/Image/logo_main.png',
            ],
            'admissions' => [
                'title' => 'Admissions | Supreme Reagan Schools',
                'description' => 'Apply for Nursery, Primary or Secondary at Supreme Reagan Schools. See required documents, contact admissions, and complete the application form online.',
                'path' => '/admissions',
                'image' => '/site/Image/logo_main.png',
            ],
            'contact' => [
                'title' => 'Contact | Supreme Reagan Schools',
                'description' => 'Visit, call or write to Supreme Reagan Schools at 15 Spibat Road, Amakohia-Akwakuma, Owerri. Office hours Monday–Friday, 8:00 a.m.–4:00 p.m.',
                'path' => '/contact',
                'image' => '/site/Image/logo_main.png',
            ],
            'nursery' => [
                'title' => 'Nursery School | Supreme Reagan Schools',
                'description' => 'Early years at Supreme Reagan Schools in Owerri — a caring Nursery where children begin language, curiosity and character in the first rooms of the house.',
                'path' => '/nursery',
                'image' => '/site/Image/logo_main.png',
            ],
            'primary' => [
                'title' => 'Primary School | Supreme Reagan Schools',
                'description' => 'Primary School at Supreme Reagan Schools — literacy, numeracy and character, with a blend of Nigerian and British curriculum on our Owerri campus.',
                'path' => '/primary',
                'image' => '/site/Image/logo_main.png',
            ],
            'secondary' => [
                'title' => 'Secondary School | Supreme Reagan Schools',
                'description' => 'Secondary School at Supreme Reagan Schools — rigorous academics, coding and robotics, arts and music, and preparation for further education.',
                'path' => '/secondary',
                'image' => '/site/Image/logo_main.png',
            ],
            'branches' => [
                'title' => 'Our Campus | Supreme Reagan Schools',
                'description' => 'Explore Supreme Reagan Schools on Spibat Road, Amakohia-Akwakuma, Owerri — Nursery, Primary and Secondary under one house.',
                'path' => '/branches',
                'image' => '/site/Image/logo_main.png',
            ],
            'alumni' => [
                'title' => 'Alumni | Supreme Reagan Schools',
                'description' => 'A door left open for old boys and old girls of Supreme Reagan Schools — stay in touch with the house in Amakohia-Akwakuma, Owerri.',
                'path' => '/alumni',
                'image' => '/site/Image/logo_main.png',
            ],
        ];

        return $pages[$key];
    }

    /**
     * @param  array{title: string, description: string, path: string, image: string}  $page
     */
    public static function headTags(array $page): string
    {
        $title = $page['title'];
        $description = $page['description'];
        $canonical = url($page['path']);
        $image = str_starts_with($page['image'], 'http')
            ? $page['image']
            : url($page['image']);
        $site = SchoolIdentity::name();

        $lines = [
            '<title>'.e($title).'</title>',
            '<meta name="description" content="'.e($description).'">',
            '<link rel="canonical" href="'.e($canonical).'">',
            '<meta property="og:site_name" content="'.e($site).'">',
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="'.e($title).'">',
            '<meta property="og:description" content="'.e($description).'">',
            '<meta property="og:url" content="'.e($canonical).'">',
            '<meta property="og:image" content="'.e($image).'">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="'.e($title).'">',
            '<meta name="twitter:description" content="'.e($description).'">',
            '<meta name="twitter:image" content="'.e($image).'">',
        ];

        return implode("\n  ", $lines);
    }

    public static function organizationJsonLd(): string
    {
        $school = [
            '@context' => 'https://schema.org',
            '@type' => 'EducationalOrganization',
            'name' => SchoolIdentity::name(),
            'url' => url('/'),
            'logo' => SchoolIdentity::logoUrl(),
            'email' => SchoolIdentity::email(),
            'telephone' => SchoolIdentity::phone(),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => SchoolIdentity::addressText(),
                'addressCountry' => 'NG',
            ],
            'motto' => SchoolIdentity::motto(),
        ];

        return '<script type="application/ld+json">'.json_encode(
            $school,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ).'</script>';
    }
}

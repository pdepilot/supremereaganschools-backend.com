<?php

namespace App\Http\Controllers;

use App\Services\News\HomepageJournalService;
use App\Support\FrontendPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class FrontendController extends Controller
{
    public function __construct(private readonly FrontendPage $frontend) {}

    public function home(): Response
    {
        $journal = app(HomepageJournalService::class)->html();

        return $this->frontend->response('public/index.html', [
            '<!--HOME_JOURNAL-->' => $journal,
        ], 'public');
    }

    public function publicPage(string $page): Response
    {
        return $this->frontend->response('public/'.$page.'.html', area: 'public');
    }

    public function portalPage(string $page = 'dashboard'): Response|RedirectResponse
    {
        $slug = $this->normalize($page);

        if (in_array($slug, ['grade', 'marks'], true)) {
            return redirect('/portal/grades');
        }

        return $this->frontend->response('admin/'.$this->fileName($page), area: 'admin');
    }

    public function staffPage(string $page = 'staff'): Response|RedirectResponse
    {
        $slug = $this->normalize($page);

        if ($slug === 'home') {
            $slug = 'staff';
        }

        if (in_array($slug, ['grade', 'marks'], true)) {
            return redirect('/staff/grades');
        }

        return $this->frontend->response('staff/'.$slug.'.html', area: 'staff');
    }

    public function parentPage(string $page = 'dashboard'): Response|RedirectResponse
    {
        $slug = $this->normalize($page);

        if ($slug === 'home') {
            $slug = 'dashboard';
        }

        if ($slug === 'children') {
            return redirect('/parent');
        }

        if (in_array($slug, ['grades', 'grade', 'marks', 'results'], true)) {
            return redirect('/parent/academics');
        }

        return $this->frontend->response('parent_student/'.$slug.'.html', area: 'parent');
    }

    public function studentPage(string $page = 'student_dashboard'): Response|RedirectResponse
    {
        $slug = $this->normalize($page);

        if ($slug === 'children') {
            return redirect('/student');
        }

        if (in_array($slug, ['grades', 'grade', 'marks', 'results'], true)) {
            return redirect('/student/academics');
        }

        $file = match ($slug) {
            'home', 'dashboard', 'student_dashboard' => 'student_dashboard',
            'profile' => 'student_profile',
            'academics' => 'student_academics',
            'assignments' => 'student_assignments',
            'attendance' => 'student_attendance',
            'fees' => 'student_fees',
            'timetable' => 'student_timetable',
            'materials' => 'student_materials',
            'messages' => 'student_messages',
            'announcements' => 'student_announcements',
            'settings' => 'student_settings',
            default => $slug,
        };

        return $this->frontend->response('parent_student/'.$file.'.html', area: 'student');
    }

    public function legacy(string $path): RedirectResponse
    {
        $target = $this->legacyTarget($path);

        abort_if($target === null, 404);

        return redirect($target, 301);
    }

    private function fileName(string $page): string
    {
        $slug = $this->normalize($page);

        if ($slug === 'home') {
            $slug = 'dashboard';
        }

        if (in_array($slug, ['nursery', 'primary', 'secondary'], true)) {
            return 'wing.html';
        }

        return $slug.'.html';
    }

    private function normalize(string $page): string
    {
        return str_replace('-', '_', $page);
    }

    private function legacyTarget(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return match (true) {
            $path === 'index.html' => '/',
            $path === 'about.html' => '/about',
            $path === 'admissions.html' => '/admissions',
            $path === 'contact.html' => '/contact',
            $path === 'nursery.html' => '/nursery',
            $path === 'primary.html' => '/primary',
            $path === 'secondary.html' => '/secondary',
            $path === 'branches.html' => '/branches',
            $path === 'pta.html' => '/pta',
            $path === 'alumni.html' => '/alumni',
            $path === 'news.html' => '/news',
            $path === 'resources.html' => '/resources',
            $path === 'privacy.html' => '/privacy',
            $path === 'terms.html' => '/terms',
            $path === 'staffLogin.html' => '/staff/login',
            $path === 'Parent_studentlogin.html' => '/parent/login',
            $path === 'parent_studentPage.html' => '/student/login',
            $path === 'superAdminLogin.html', $path === 'adminLogin.html' => '/portal/login',
            str_starts_with($path, 'portal/') => $this->prefixed('/portal', $this->legacySlug(substr($path, 7))),
            str_starts_with($path, 'admin/') => $this->prefixed('/portal', $this->legacySlug(substr($path, 6))),
            str_starts_with($path, 'staff/') => $this->prefixed('/staff', $this->legacySlug(substr($path, 6), 'staff')),
            $path === 'parent_student/student_dashboard.html' => '/student',
            str_starts_with($path, 'parent_student/student_') => $this->prefixed('/student', $this->legacySlug(substr(basename($path), strlen('student_')))),
            str_starts_with($path, 'parent_student/') => $this->prefixed('/parent', $this->legacySlug(substr($path, strlen('parent_student/')), 'parent')),
            default => null,
        };
    }

    private function legacySlug(string $file, ?string $home = null): string
    {
        $name = basename($file, '.html');

        if ($home === 'staff' && $name === 'staff') {
            return '';
        }

        if ($home === 'parent' && $name === 'dashboard') {
            return '';
        }

        if ($name === 'dashboard' && $home === null) {
            return 'dashboard';
        }

        return str_replace('_', '-', $name);
    }

    private function prefixed(string $root, string $slug): string
    {
        return $slug === '' ? $root : $root.'/'.$slug;
    }
}

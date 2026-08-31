<?php

namespace App\Support;

class FrontendLinker
{
    /**
     * @param  'public'|'admin'|'staff'|'parent'|'student'|'auth'  $area
     */
    public function rewrite(string $html, string $area): string
    {
        $html = $this->rewriteAssets($html);
        $html = $this->rewritePages($html, $area);

        return $this->ensureSessionScript($html, $area);
    }

    private function rewriteAssets(string $html): string
    {
        $rewritten = preg_replace(
            '#((?:src|href|poster|data-src)=["\']|url\(["\']?)(?:\./|\.\./)?(CSS|JS|Image)/#i',
            '$1/site/$2/',
            $html,
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * @param  'public'|'admin'|'staff'|'parent'|'student'|'auth'  $area
     */
    private function rewritePages(string $html, string $area): string
    {
        $rewritten = preg_replace_callback(
            '/((?:href|action)=)([\'"])([^\'"]+\.html(?:#[^\'"]*)?)\2/i',
            function (array $match) use ($area) {
                return $match[1].$match[2].$this->mapHref($match[3], $area).$match[2];
            },
            $html,
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * @param  'public'|'admin'|'staff'|'parent'|'student'|'auth'  $area
     */
    private function mapHref(string $href, string $area): string
    {
        $fragment = '';
        $path = $href;

        if (str_contains($href, '#')) {
            [$path, $hash] = explode('#', $href, 2);
            $fragment = '#'.$hash;
        }

        $file = basename($path);
        $mapped = $this->routes($area)[$file] ?? $this->routes('public')[$file] ?? null;

        return $mapped !== null ? $mapped.$fragment : $href;
    }

    /**
     * @return array<string, string>
     */
    private function routes(string $area): array
    {
        $public = [
            'index.html' => '/',
            'about.html' => '/about',
            'admissions.html' => '/admissions',
            'contact.html' => '/contact',
            'nursery.html' => '/nursery',
            'primary.html' => '/primary',
            'secondary.html' => '/secondary',
            'branches.html' => '/branches',
            'pta.html' => '/pta',
            'alumni.html' => '/alumni',
            'news.html' => '/news',
            'resources.html' => '/resources',
            'privacy.html' => '/privacy',
            'terms.html' => '/terms',
            'staffLogin.html' => '/staff/login',
            'Parent_studentlogin.html' => '/parent/login',
            'parent_studentPage.html' => '/student/login',
            'superAdminLogin.html' => '/portal/login',
            'adminLogin.html' => '/portal/login',
        ];

        $pages = [
            'public' => $public,
            'auth' => $public,
            'admin' => [
                'dashboard.html' => '/portal/dashboard',
                'students.html' => '/portal/students',
                'teachers.html' => '/portal/teachers',
                'classes.html' => '/portal/classes',
                'academic_sessions.html' => '/portal/academic-sessions',
                'timetable.html' => '/portal/timetable',
                'fees.html' => '/portal/fees',
                'grades.html' => '/portal/grades',
                'announcements.html' => '/portal/announcements',
                'news.html' => '/portal/news',
                'email.html' => '/portal/email',
                'contact.html' => '/portal/contact',
                'messages.html' => '/portal/messages',
                'reports.html' => '/portal/reports',
                'settings.html' => '/portal/settings',
                'nursery.html' => '/portal/nursery',
                'primary.html' => '/portal/primary',
                'secondary.html' => '/portal/secondary',
                'superAdminLogin.html' => '/portal/login',
            ] + $public,
            'staff' => [
                'staff.html' => '/staff',
                'students.html' => '/staff/students',
                'attendance.html' => '/staff/attendance',
                'assignments.html' => '/staff/assignments',
                'grades.html' => '/staff/grades',
                'reports.html' => '/staff/reports',
                'timetable.html' => '/staff/timetable',
                'materials.html' => '/staff/materials',
                'messages.html' => '/staff/messages',
                'announcements.html' => '/staff/announcements',
                'profile.html' => '/staff/profile',
                'settings.html' => '/staff/settings',
                'staffLogin.html' => '/staff/login',
            ] + $public,
            'parent' => [
                'dashboard.html' => '/parent',
                'student_dashboard.html' => '/student',
                'children.html' => '/parent/children',
                'academics.html' => '/parent/academics',
                'assignments.html' => '/parent/assignments',
                'attendance.html' => '/parent/attendance',
                'fees.html' => '/parent/fees',
                'timetable.html' => '/parent/timetable',
                'materials.html' => '/parent/materials',
                'messages.html' => '/parent/messages',
                'announcements.html' => '/parent/announcements',
                'profile.html' => '/parent/profile',
                'settings.html' => '/parent/settings',
                'Parent_studentPage.html' => '/parent/login',
                'Parent_studentlogin.html' => '/parent/login',
            ] + $public,
            'student' => [
                'dashboard.html' => '/student',
                'student_dashboard.html' => '/student',
                'student_profile.html' => '/student/profile',
                'student_academics.html' => '/student/academics',
                'student_assignments.html' => '/student/assignments',
                'student_attendance.html' => '/student/attendance',
                'student_fees.html' => '/student/fees',
                'student_timetable.html' => '/student/timetable',
                'student_materials.html' => '/student/materials',
                'student_messages.html' => '/student/messages',
                'student_announcements.html' => '/student/announcements',
                'student_settings.html' => '/student/settings',
                'children.html' => '/student',
                'academics.html' => '/student/academics',
                'assignments.html' => '/student/assignments',
                'attendance.html' => '/student/attendance',
                'fees.html' => '/student/fees',
                'timetable.html' => '/student/timetable',
                'materials.html' => '/student/materials',
                'messages.html' => '/student/messages',
                'announcements.html' => '/student/announcements',
                'profile.html' => '/student/profile',
                'settings.html' => '/student/settings',
                'Parent_studentPage.html' => '/student/login',
            ] + $public,
        ];

        return $pages[$area] ?? $public;
    }

    /**
     * @param  'public'|'admin'|'staff'|'parent'|'student'|'auth'  $area
     */
    private function ensureSessionScript(string $html, string $area): string
    {
        if ($area === 'public' || $area === 'auth') {
            if (str_contains($html, 'site-analytics.js') || ! str_contains($html, '</body>')) {
                return $html;
            }

            $tag = '<script src="/site/JS/site-analytics.js"></script>';

            return (string) preg_replace('/<\/body>/i', '  '.$tag."\n</body>", $html, 1);
        }

        if (str_contains($html, 'portal-session.js') || str_contains($html, 'admin-command.js')) {
            return $html;
        }

        if (! str_contains($html, 'data-logout') && ! str_contains($html, 'logout-link') && ! str_contains($html, 'ps-logout')) {
            return $html;
        }

        $tag = '<script src="/site/JS/portal-session.js"></script>';

        if (preg_match('/<script\b/i', $html) === 1) {
            $updated = preg_replace('/<script\b/i', $tag."\n<script", $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        if (str_contains($html, '</body>')) {
            return (string) preg_replace('/<\/body>/i', '  '.$tag."\n</body>", $html, 1);
        }

        return $html.$tag;
    }
}

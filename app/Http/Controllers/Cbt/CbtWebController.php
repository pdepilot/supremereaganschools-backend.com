<?php

namespace App\Http\Controllers\Cbt;

use App\Http\Controllers\Controller;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Services\Cbt\CbtAccessService;
use App\Support\FrontendPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CbtWebController extends Controller
{
    public function __construct(
        private readonly FrontendPage $frontend,
        private readonly CbtAccessService $access,
    ) {}

    public function exams(Request $request): Response
    {
        abort_unless($this->access->canEnter($request->user()), 403);

        return $this->shell('cbt/desk.html');
    }

    public function exam(Request $request, CbtExam $exam): Response
    {
        $this->authorize('view', $exam);

        return $this->shell('cbt/exam.html');
    }

    public function attempt(Request $request, CbtAttempt $attempt): Response
    {
        if (! $request->user()->can('view', $attempt)) {
            abort(404);
        }

        return $this->shell('cbt/attempt.html');
    }

    public function results(Request $request): Response
    {
        abort_unless($this->access->isStudentTaker($request->user()) || $this->access->canMark($request->user()), 403);

        return $this->shell('cbt/results.html');
    }

    public function adminQuestions(Request $request): Response
    {
        abort_unless($this->access->canManage($request->user()), 403);

        return $this->shell('cbt/admin-questions.html');
    }

    public function adminExams(Request $request): Response
    {
        abort_unless($this->access->canManage($request->user()) || $this->access->canMark($request->user()), 403);

        return $this->shell('cbt/admin-exams.html');
    }

    public function adminExam(Request $request, CbtExam $exam): Response
    {
        abort_unless($this->access->canManage($request->user()) || $this->access->canMark($request->user()), 403);

        return $this->shell('cbt/admin-exam.html');
    }

    public function adminPreview(Request $request, CbtExam $exam): Response
    {
        abort_unless($this->access->canManage($request->user()), 403);

        return $this->shell('cbt/admin-preview.html');
    }

    public function adminResults(Request $request): Response
    {
        abort_unless($this->access->canManage($request->user()) || $this->access->canMark($request->user()), 403);

        return $this->shell('cbt/admin-results.html');
    }

    public function adminPage(Request $request, string $page): Response
    {
        $user = $request->user();
        abort_unless(
            $this->access->canManage($user) || $this->access->canMark($user) || $this->access->canProctor($user),
            403,
        );

        return $this->shell('cbt/admin.html');
    }

    private function shell(string $file): Response
    {
        return $this->frontend->response($file, [
            '{{CSRF_TOKEN}}' => csrf_token(),
        ], 'auth');
    }
}

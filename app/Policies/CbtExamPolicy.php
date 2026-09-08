<?php

namespace App\Policies;

use App\Models\CbtExam;
use App\Models\User;
use App\Services\Cbt\CbtAccessService;
use App\Services\Cbt\CbtExamAssignmentService;

class CbtExamPolicy
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly CbtExamAssignmentService $assignments,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canEnter($user);
    }

    public function create(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function update(User $user, CbtExam $exam): bool
    {
        return $this->access->canManage($user);
    }

    public function view(User $user, CbtExam $exam): bool
    {
        if ($this->access->canManage($user) || $this->access->canProctor($user) || $this->access->canMark($user)) {
            return true;
        }

        if (! $this->access->isStudentTaker($user)) {
            return false;
        }

        return $this->assignments->isStudentEligible($exam, $this->access->requireStudentProfile($user));
    }

    public function take(User $user, CbtExam $exam): bool
    {
        return $this->view($user, $exam) && $this->access->isStudentTaker($user);
    }

    public function manage(User $user): bool
    {
        return $this->access->canManage($user);
    }

    public function publish(User $user, CbtExam $exam): bool
    {
        return $this->access->canManage($user);
    }

    public function assign(User $user, CbtExam $exam): bool
    {
        return $this->access->canManage($user);
    }

    public function preview(User $user, CbtExam $exam): bool
    {
        return $this->access->canManage($user);
    }
}

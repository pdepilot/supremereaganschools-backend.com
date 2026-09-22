<?php

namespace App\Console\Commands;

use App\Models\AcademicSession;
use App\Services\AcademicSessionService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DeleteAcademicSessionCommand extends Command
{
    protected $signature = 'academic-session:delete
        {name? : Session name, e.g. 2025/2026}
        {--all : Delete every academic session on the books}
        {--inspect : Show what still sits on the year without deleting}
        {--force : Skip the confirmation prompt when using --all}';

    protected $description = 'Inspect or delete academic session(s), clearing enrollments, invoices, fee-book rows, forms, and CBT exams for that year.';

    public function handle(AcademicSessionService $sessions): int
    {
        if ($this->option('all')) {
            return $this->deleteAll($sessions);
        }

        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('Provide a session name, or pass --all to clear the books.');

            return self::FAILURE;
        }

        $session = AcademicSession::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($session === null) {
            $this->warn("No academic session named \"{$name}\" was found.");

            return self::SUCCESS;
        }

        return $this->deleteOne($sessions, $session, (bool) $this->option('inspect'));
    }

    private function deleteAll(AcademicSessionService $sessions): int
    {
        $rows = AcademicSession::query()->orderBy('starts_on')->get();

        if ($rows->isEmpty()) {
            $this->info('The books are already empty — no academic sessions to delete.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Status'],
            $rows->map(fn (AcademicSession $session) => [
                $session->id,
                $session->name,
                $session->status?->value,
            ])->all(),
        );

        if ($this->option('inspect')) {
            $this->info('Inspection only — nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete every academic session listed above?', false)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        foreach ($rows as $session) {
            $result = $this->deleteOne($sessions, $session, false);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->info('On the books is now empty.');

        return self::SUCCESS;
    }

    private function deleteOne(AcademicSessionService $sessions, AcademicSession $session, bool $inspect): int
    {
        $name = $session->name;
        $footprint = $sessions->footprint($session);
        $this->table(
            ['Item', 'Count'],
            collect($footprint)->map(fn ($count, $key) => [$key, $count])->values()->all(),
        );

        if ($inspect) {
            $this->info("Inspection only — \"{$name}\" was not deleted.");

            return self::SUCCESS;
        }

        try {
            $sessions->delete($session);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
            $this->error((string) $message);

            return self::FAILURE;
        }

        $this->info("Deleted academic session \"{$name}\" (id {$session->id}).");

        return self::SUCCESS;
    }
}

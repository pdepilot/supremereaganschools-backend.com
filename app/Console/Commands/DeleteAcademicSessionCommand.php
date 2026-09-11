<?php

namespace App\Console\Commands;

use App\Models\AcademicSession;
use App\Services\AcademicSessionService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DeleteAcademicSessionCommand extends Command
{
    protected $signature = 'academic-session:delete
        {name : Session name, e.g. 2025/2026}
        {--inspect : Show what still sits on the year without deleting}';

    protected $description = 'Inspect or delete an academic session, clearing enrollments, invoices, fee-book rows, and forms for that year.';

    public function handle(AcademicSessionService $sessions): int
    {
        $name = trim((string) $this->argument('name'));
        $session = AcademicSession::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($session === null) {
            $this->warn("No academic session named \"{$name}\" was found.");

            return self::SUCCESS;
        }

        $footprint = $sessions->footprint($session);
        $this->table(
            ['Item', 'Count'],
            collect($footprint)->map(fn ($count, $key) => [$key, $count])->values()->all(),
        );

        if ($this->option('inspect')) {
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

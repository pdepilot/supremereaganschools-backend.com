<?php

namespace App\Console\Commands;

use App\Models\AcademicSession;
use App\Services\AcademicSessionService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class DeleteAcademicSessionCommand extends Command
{
    protected $signature = 'academic-session:delete {name : Session name, e.g. 2025/2026}';

    protected $description = 'Delete an academic session, clearing empty forms, fee-book rows, invoices, and receipts for that year.';

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

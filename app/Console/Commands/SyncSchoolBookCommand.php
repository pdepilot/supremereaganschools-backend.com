<?php

namespace App\Console\Commands;

use Database\Seeders\ClassSectionOfferingSeeder;
use Database\Seeders\LevelSeeder;
use Database\Seeders\SchoolClassSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Console\Command;

class SyncSchoolBookCommand extends Command
{
    protected $signature = 'school-book:sync';

    protected $description = 'Sync levels, subjects, school-book classes/forms, and session offerings; retire forms not on the book.';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => LevelSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => SubjectSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => SchoolClassSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => ClassSectionOfferingSeeder::class, '--force' => true]);

        $this->info('School book synced. Portal/classes will show only active book forms.');

        return self::SUCCESS;
    }
}

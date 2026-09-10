<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correct ALL-CAPS public article titles only. Slugs are unchanged.
     */
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        $fixes = [
            'how-to-help-your-child-prepare-for-a-new-school-term' => 'How to Help Your Child Prepare for a New School Term',
            'how-parents-can-help-children-develop-better-study-habits' => 'How Parents Can Help Children Develop Better Study Habits',
        ];

        foreach ($fixes as $slug => $title) {
            $row = DB::table('posts')->where('slug', $slug)->first();
            if ($row === null) {
                continue;
            }

            $payload = ['title' => $title, 'updated_at' => now()];

            if (isset($row->meta_title) && is_string($row->meta_title) && strtoupper(trim($row->meta_title)) === strtoupper(trim((string) $row->title))) {
                $payload['meta_title'] = $title;
            }

            if (isset($row->og_title) && is_string($row->og_title) && strtoupper(trim($row->og_title)) === strtoupper(trim((string) $row->title))) {
                $payload['og_title'] = $title;
            }

            DB::table('posts')->where('id', $row->id)->update($payload);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        $reversals = [
            'how-to-help-your-child-prepare-for-a-new-school-term' => 'HOW TO HELP YOUR CHILD PREPARE FOR A NEW SCHOOL TERM',
            'how-parents-can-help-children-develop-better-study-habits' => 'HOW PARENTS CAN HELP CHILDREN DEVELOP BETTER STUDY HABITS',
        ];

        foreach ($reversals as $slug => $title) {
            DB::table('posts')->where('slug', $slug)->update([
                'title' => $title,
                'updated_at' => now(),
            ]);
        }
    }
};

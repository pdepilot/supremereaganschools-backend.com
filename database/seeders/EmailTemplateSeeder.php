<?php

namespace Database\Seeders;

use App\Enums\EmailAudience;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->letters() as $letter) {
            EmailTemplate::query()->updateOrCreate(
                ['slug' => $letter['slug']],
                $letter,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function letters(): array
    {
        return [
            [
                'slug' => 'general',
                'name' => 'General circular',
                'audience' => EmailAudience::WholeSchool->value,
                'subject' => 'A word from {{school}}',
                'preheader' => 'A circular from the school office.',
                'body' => "I write with a short word from the office.\n\n**Please read this circular with care**, and keep it for the house.\n\nIf anything is unclear, call the office on {{phone}}.",
                'is_system' => true,
            ],
            [
                'slug' => 'fee-reminder',
                'name' => 'Fee reminder',
                'audience' => EmailAudience::Parents->value,
                'subject' => 'School fees · {{term}}',
                'preheader' => 'A reminder on the fee book for this term.',
                'body' => "This is a courteous reminder that school fees for **{{term}}** ({{session}}) remain on the book.\n\nKindly settle outstanding balances at the office, or write to us if a plan is needed. Houses that have already paid should ignore this note.\n\nThe bursary is open on school days. Telephone {{phone}}.",
                'is_system' => true,
            ],
            [
                'slug' => 'term-opening',
                'name' => 'Term opening',
                'audience' => EmailAudience::Parents->value,
                'subject' => '{{term}} opens · {{school}}',
                'preheader' => 'Dates, dress, and the first morning of term.',
                'body' => "We look forward to receiving the children for **{{term}}**, {{session}}.\n\nKindly have pupils in full school dress on the opening morning, with books labelled. The gate opens with the office at the usual hour.\n\nWe remain, with every good wish for the weeks ahead.",
                'is_system' => true,
            ],
            [
                'slug' => 'exam-notice',
                'name' => 'Examination notice',
                'audience' => EmailAudience::WholeSchool->value,
                'subject' => 'Examinations · {{term}}',
                'preheader' => 'The examination week, from the office.',
                'body' => "Examinations for **{{term}}** will be held as previously advised.\n\nPupils should arrive in good time, with the correct materials, and in full school dress. Please keep the days quiet at home so the children may sit with a clear head.\n\nAny clash of papers should be brought to the office at once.",
                'is_system' => true,
            ],
            [
                'slug' => 'event',
                'name' => 'School event',
                'audience' => EmailAudience::Parents->value,
                'subject' => 'You are invited · {{school}}',
                'preheader' => 'An invitation from the school office.',
                'body' => "The school will host an event on the date given below, and the presence of the house would honour us.\n\n**Please arrive a little before the hour**, so the children may be seated in good order.\n\nFurther detail will stand on the notice board. We look forward to receiving you at {{address}}.",
                'is_system' => true,
            ],
            [
                'slug' => 'urgent',
                'name' => 'Urgent notice',
                'audience' => EmailAudience::WholeSchool->value,
                'subject' => 'Urgent · {{school}}',
                'preheader' => 'An urgent word from the office. Please read today.',
                'body' => "This circular requires **prompt attention**.\n\nPlease act on the instruction below today, and telephone the office on {{phone}} if the house cannot comply.\n\nWe thank you for your immediate care.",
                'is_system' => true,
            ],
            [
                'slug' => 'welcome',
                'name' => 'Welcome to the school',
                'audience' => EmailAudience::Parents->value,
                'subject' => 'Welcome to {{school}}',
                'preheader' => 'A first letter from the office to a new house.',
                'body' => "It is our pleasure to welcome you to **{{school}}**.\n\n{{motto}}. The office is at {{address}}, and we are glad the child now belongs to this house.\n\nShould anything be unclear in the first weeks, write or call {{phone}}. We will see the family settled.",
                'is_system' => true,
            ],
            [
                'slug' => 'closure',
                'name' => 'School closure',
                'audience' => EmailAudience::WholeSchool->value,
                'subject' => 'School closed · {{school}}',
                'preheader' => 'The school will not sit on the date below.',
                'body' => "Please note that the school will **not sit** on the date given in this circular.\n\nLessons resume on the following school day unless the office writes again. Keep children at home, and watch this mailbox for any further word.\n\nWe regret any inconvenience to the house.",
                'is_system' => true,
            ],
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\EnquiryStatus;
use App\Models\ContactEnquiry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EnquiryService
{
    public function __construct(private readonly EmailCenterService $mail) {}

    /**
     * Subjects that land in the urgent admissions/fees lane.
     *
     * @var list<string>
     */
    public const URGENT_SUBJECTS = [
        'Admission enquiry',
        'Request a campus visit',
        'Fees and administration',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function submit(array $attributes): ContactEnquiry
    {
        return ContactEnquiry::query()->create([
            'name' => $attributes['name'],
            'phone' => $attributes['phone'],
            'email' => $attributes['email'],
            'subject' => $attributes['subject'],
            'message' => $attributes['message'],
            'intended_level' => $attributes['intended_level'] ?? null,
            'enquiry_type' => $attributes['enquiry_type'] ?? null,
            'source_url' => $attributes['source_url'] ?? null,
            'source_post_id' => $attributes['source_post_id'] ?? null,
            'status' => $this->initialStatus((string) $attributes['subject']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ContactEnquiry $enquiry, array $attributes, User $actor): ContactEnquiry
    {
        $status = EnquiryStatus::from($attributes['status']);

        $enquiry->update([
            'status' => $status,
            'assigned_to' => $actor->id,
        ]);

        return $enquiry->fresh(['assignee', 'replies.author']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function reply(ContactEnquiry $enquiry, array $attributes, User $actor): ContactEnquiry
    {
        $subject = trim((string) ($attributes['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Re: '.$enquiry->subject;
        }

        $this->mail->sendPersonal(
            $actor,
            $enquiry->email,
            $enquiry->name,
            $subject,
            (string) $attributes['body'],
        );

        $enquiry->replies()->create([
            'user_id' => $actor->id,
            'subject' => $subject,
            'body' => $attributes['body'],
            'sent_at' => now(),
        ]);

        $enquiry->update([
            'status' => EnquiryStatus::Cleared,
            'assigned_to' => $actor->id,
        ]);

        return $enquiry->fresh(['assignee', 'replies.author']);
    }

    public function destroy(ContactEnquiry $enquiry): void
    {
        $enquiry->delete();
    }

    public function markOpened(ContactEnquiry $enquiry, User $actor): ContactEnquiry
    {
        if ($enquiry->status === EnquiryStatus::Cleared) {
            return $enquiry;
        }

        if ($enquiry->status === EnquiryStatus::Unread) {
            $enquiry->update([
                'status' => EnquiryStatus::Read,
                'assigned_to' => $actor->id,
            ]);
        }

        return $enquiry->fresh(['assignee', 'replies.author']);
    }

    public function clearUrgent(User $actor): int
    {
        return ContactEnquiry::query()
            ->where('status', EnquiryStatus::Urgent)
            ->update([
                'status' => EnquiryStatus::Cleared->value,
                'assigned_to' => $actor->id,
                'updated_at' => now(),
            ]);
    }

    public function initialStatus(string $subject): EnquiryStatus
    {
        return in_array($subject, self::URGENT_SUBJECTS, true)
            ? EnquiryStatus::Urgent
            : EnquiryStatus::Unread;
    }

    /**
     * @return Collection<int, ContactEnquiry>
     */
    public function clearedToday(): Collection
    {
        return ContactEnquiry::query()
            ->where('status', EnquiryStatus::Cleared)
            ->whereDate('updated_at', Carbon::now('Africa/Lagos')->toDateString())
            ->get();
    }
}

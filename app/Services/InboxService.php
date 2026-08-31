<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EnquiryStatus;
use App\Models\AdmissionApplication;
use App\Models\ContactEnquiry;
use Illuminate\Support\Carbon;

class InboxService
{
    /**
     * @return array<string, mixed>
     */
    public function chute(): array
    {
        $enquiries = ContactEnquiry::query()->orderByDesc('created_at')->get();
        $applications = AdmissionApplication::query()->orderByDesc('created_at')->get();

        $urgent = collect();
        $watch = collect();
        $cleared = collect();

        foreach ($enquiries as $enquiry) {
            $item = $this->enquiryItem($enquiry);
            if ($enquiry->status === EnquiryStatus::Cleared) {
                $cleared->push($item);
            } elseif ($this->enquiryIsUrgent($enquiry)) {
                $urgent->push($item);
            } else {
                $watch->push($item);
            }
        }

        foreach ($applications as $application) {
            $item = $this->applicationItem($application);
            if (in_array($application->status, [
                ApplicationStatus::Admitted,
                ApplicationStatus::Rejected,
                ApplicationStatus::Withdrawn,
            ], true)) {
                $cleared->push($item);
            } else {
                $urgent->push($item);
            }
        }

        $sort = fn ($items) => $items->sortByDesc('created_at')->values()->all();

        $unread = $enquiries->where('status', EnquiryStatus::Unread)->count()
            + $applications->where('status', ApplicationStatus::Submitted)->count();

        $clearedToday = $enquiries
            ->where('status', EnquiryStatus::Cleared)
            ->filter(fn (ContactEnquiry $row) => $row->updated_at?->timezone('Africa/Lagos')?->toDateString() === Carbon::now('Africa/Lagos')->toDateString())
            ->count()
            + $applications
                ->filter(fn (AdmissionApplication $row) => in_array($row->status, [
                    ApplicationStatus::Admitted,
                    ApplicationStatus::Rejected,
                    ApplicationStatus::Withdrawn,
                ], true) && $row->updated_at?->timezone('Africa/Lagos')?->toDateString() === Carbon::now('Africa/Lagos')->toDateString())
                ->count();

        return [
            'urgent' => $sort($urgent),
            'watch' => $sort($watch),
            'cleared' => $sort($cleared),
            'summary' => [
                'unread' => $unread,
                'urgent' => $urgent->count(),
                'watch' => $watch->count(),
                'cleared_today' => $clearedToday,
            ],
        ];
    }

    private function enquiryIsUrgent(ContactEnquiry $enquiry): bool
    {
        return $enquiry->status === EnquiryStatus::Urgent
            || in_array($enquiry->subject, EnquiryService::URGENT_SUBJECTS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function enquiryItem(ContactEnquiry $enquiry): array
    {
        return [
            'kind' => 'enquiry',
            'id' => $enquiry->id,
            'name' => $enquiry->name,
            'preview' => $enquiry->message,
            'subject' => $enquiry->subject,
            'status' => $enquiry->status?->value,
            'email' => $enquiry->email,
            'phone' => $enquiry->phone,
            'created_at' => $enquiry->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationItem(AdmissionApplication $application): array
    {
        return [
            'kind' => 'application',
            'id' => $application->id,
            'name' => $application->parent_name,
            'preview' => $application->fullName().' — '.$application->class_applied.' ('.$application->reference.')',
            'subject' => 'Admission application',
            'status' => $application->status?->value,
            'email' => $application->parent_email,
            'phone' => $application->parent_phone,
            'reference' => $application->reference,
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }
}

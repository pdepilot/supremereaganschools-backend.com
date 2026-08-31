<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdmissionApplication;
use App\Models\ContactEnquiry;
use App\Models\Document;
use App\Services\ApplicationService;
use App\Services\DocumentService;
use App\Services\EnquiryService;
use App\Services\InboxService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InboxController extends Controller
{
    public function __construct(
        private readonly InboxService $inbox,
        private readonly EnquiryService $enquiries,
        private readonly ApplicationService $applications,
        private readonly DocumentService $documents,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ContactEnquiry::class);

        return ApiResponse::success('Inbox retrieved.', $this->inbox->chute());
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ContactEnquiry::class);

        $validated = $request->validate([
            'kind' => ['required', 'in:enquiry,application'],
            'id' => ['required', 'integer'],
        ]);

        if ($validated['kind'] === 'enquiry') {
            $enquiry = ContactEnquiry::query()->findOrFail($validated['id']);
            $this->authorize('update', $enquiry);
            $this->enquiries->markOpened($enquiry, $request->user());
        } else {
            $application = AdmissionApplication::query()->findOrFail($validated['id']);
            $this->authorize('update', $application);
            $this->applications->markOpened($application);
        }

        return ApiResponse::success('Inbox item opened.', $this->inbox->chute());
    }

    public function clearUrgent(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ContactEnquiry::class);
        $this->enquiries->clearUrgent($request->user());

        return ApiResponse::success('Urgent enquiries cleared.', $this->inbox->chute());
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return $this->documents->download($document);
    }
}

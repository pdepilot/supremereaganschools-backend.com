<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\ReplyContactEnquiryRequest;
use App\Http\Requests\Admissions\StoreContactEnquiryRequest;
use App\Http\Requests\Admissions\UpdateContactEnquiryRequest;
use App\Http\Resources\Admissions\ContactEnquiryResource;
use App\Models\ContactEnquiry;
use App\Services\EnquiryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactEnquiryController extends Controller
{
    public function __construct(private readonly EnquiryService $enquiries) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ContactEnquiry::class);

        $rows = ContactEnquiry::query()
            ->with(['assignee', 'replies.author'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success('Enquiries retrieved.', ContactEnquiryResource::collection($rows)->resolve());
    }

    public function store(StoreContactEnquiryRequest $request): JsonResponse
    {
        $enquiry = $this->enquiries->submit($request->validated());

        return ApiResponse::success('Enquiry received.', (new ContactEnquiryResource($enquiry))->resolve(), 201);
    }

    public function show(Request $request, ContactEnquiry $contactEnquiry): JsonResponse
    {
        $this->authorize('view', $contactEnquiry);

        $enquiry = $this->enquiries->markOpened($contactEnquiry, $request->user());

        return ApiResponse::success(
            'Enquiry retrieved.',
            (new ContactEnquiryResource($enquiry->load(['assignee', 'replies.author'])))->resolve(),
        );
    }

    public function update(UpdateContactEnquiryRequest $request, ContactEnquiry $contactEnquiry): JsonResponse
    {
        $enquiry = $this->enquiries->update($contactEnquiry, $request->validated(), $request->user());

        return ApiResponse::success('Enquiry updated.', (new ContactEnquiryResource($enquiry))->resolve());
    }

    public function reply(ReplyContactEnquiryRequest $request, ContactEnquiry $contactEnquiry): JsonResponse
    {
        $enquiry = $this->enquiries->reply($contactEnquiry, $request->validated(), $request->user());

        return ApiResponse::success(
            'Reply dispatched through the school mailbox.',
            (new ContactEnquiryResource($enquiry))->resolve(),
        );
    }

    public function destroy(ContactEnquiry $contactEnquiry): JsonResponse
    {
        $this->authorize('delete', $contactEnquiry);

        $this->enquiries->destroy($contactEnquiry);

        return ApiResponse::success('Enquiry removed.');
    }
}

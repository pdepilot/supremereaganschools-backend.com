<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SendSchoolMailRequest;
use App\Http\Resources\Mail\EmailTemplateResource;
use App\Http\Resources\Mail\OutboundMailResource;
use App\Models\EmailTemplate;
use App\Models\OutboundMail;
use App\Services\EmailCenterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailCenterController extends Controller
{
    public function __construct(private readonly EmailCenterService $post) {}

    public function desk(): JsonResponse
    {
        $this->authorize('viewAny', EmailTemplate::class);

        return ApiResponse::success('Email centre retrieved.', $this->post->desk());
    }

    public function templates(): JsonResponse
    {
        $this->authorize('viewAny', EmailTemplate::class);

        $rows = EmailTemplate::query()->orderBy('name')->get();

        return ApiResponse::success('Templates retrieved.', EmailTemplateResource::collection($rows)->resolve());
    }

    public function people(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmailTemplate::class);

        return ApiResponse::success('People retrieved.', $this->post->people($request->user()));
    }

    public function outbox(): JsonResponse
    {
        $this->authorize('viewAny', OutboundMail::class);

        $rows = OutboundMail::query()
            ->with(['template', 'sender'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        return ApiResponse::success('Outbox retrieved.', OutboundMailResource::collection($rows)->resolve());
    }

    public function preview(SendSchoolMailRequest $request): JsonResponse
    {
        $preview = $this->post->preview($request->validated(), $request->user());

        return ApiResponse::success('Preview prepared.', $preview);
    }

    public function send(SendSchoolMailRequest $request): JsonResponse
    {
        $mail = $this->post->send($request->validated(), $request->user());

        return ApiResponse::success(
            'Circular dispatched through the school mailbox.',
            (new OutboundMailResource($mail))->resolve(),
            201,
        );
    }
}

<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
        ], $status);
    }

    public static function paginated(string $message, mixed $data, mixed $paginator, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'current_page' => method_exists($paginator, 'currentPage') ? $paginator->currentPage() : 1,
                'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : 1,
                'per_page' => method_exists($paginator, 'perPage') ? $paginator->perPage() : count($data),
                'total' => method_exists($paginator, 'total') ? $paginator->total() : count($data),
            ],
        ], $status);
    }

    public static function error(string $message, ?array $errors = null, int $status = 400, mixed $data = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'data' => $data,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}

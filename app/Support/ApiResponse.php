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

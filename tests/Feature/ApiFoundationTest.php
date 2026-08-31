<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_api_health_returns_the_standard_json_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'API is available.',
                'data' => [
                    'version' => 'v1',
                    'name' => 'Supreme Reagan Schools',
                ],
            ]);
    }

    public function test_api_success_helper_uses_an_object_when_data_is_empty(): void
    {
        $response = ApiResponse::success('Ready.');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['success' => true, 'message' => 'Ready.', 'data' => []],
            json_decode($response->getContent(), true)
        );
        $this->assertIsObject(json_decode($response->getContent())->data);
    }

    public function test_unknown_api_route_returns_the_standard_error_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'The requested resource was not found.',
                'data' => null,
            ])
            ->assertJsonMissingPath('errors');
    }

    public function test_api_validation_errors_return_the_standard_error_envelope(): void
    {
        Route::middleware('api')->post('/api/v1/foundation-validation', function (Request $request) {
            $request->validate([
                'email' => ['required', 'email'],
            ]);
        });

        $this->postJson('/api/v1/foundation-validation', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'errors' => ['email'],
            ]);
    }
}

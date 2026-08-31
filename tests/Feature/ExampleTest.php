<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Supreme Reagan Schools', false)
            ->assertSee('/site/CSS/index.css', false)
            ->assertSee('/site/Image/logo_main.png', false)
            ->assertSee('/site/Image/home.jpg', false)
            ->assertSee('rel="icon"', false);
    }

    public function test_the_public_index_html_path_redirects_home(): void
    {
        $this->get('/site/index.html')->assertRedirect('/');
    }
}

<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A fresh install (no users) sends the root to the web installer.
     */
    public function test_fresh_app_redirects_to_installer(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/install');
    }
}

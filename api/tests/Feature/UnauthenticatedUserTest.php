<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnauthenticatedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_401_when_requesting_json(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Não autorizado',
            ])
            ->assertHeaderMissing('Location');
    }

    public function test_guest_receives_401_when_requesting_html(): void
    {
        $this->get('/api/user', [
            'Accept' => 'text/html',
        ])
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Não autorizado',
            ])
            ->assertHeaderMissing('Location');
    }
}

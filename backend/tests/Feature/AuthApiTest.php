<?php

namespace Tests\Feature;


class AuthApiTest extends ApiContractTest
{
    public function test_unauthenticated_api_request_is_401_unauthorized(): void
    {
        $this->getJson('/api/v1/executions')
            ->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Access denied.',
                ],
            ]);
    }

    public function test_demo_user_can_login(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo@overview.local',
            'password' => 'demo',
        ]);

        $response
            ->assertStatus(200)
            // §4.2: login payload is bare { user, workspace, role }, no data envelope.
            ->assertJsonStructure([
                'user' => ['id', 'email', 'name'],
                'workspace' => ['id', 'name', 'slug'],
                'role',
            ])
            ->assertJsonPath('user.email', 'demo@overview.local')
            ->assertJsonPath('workspace.slug', 'demo-workspace')
            ->assertJsonPath('role', 'owner');
    }

    public function test_login_with_wrong_password_is_401_unauthorized(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo@overview.local',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }
}

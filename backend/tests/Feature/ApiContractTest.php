<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins the Phase-1 API contract (ARCHITECTURE.md §4.1 error shape,
 * §4.2 route table, §4.3 seed data) so consumer-visible drift fails CI.
 *
 * Self-contained: in-memory SQLite + seeder, no running server needed.
 * Authenticated tests use actingAs because phpunit.xml sets the array
 * session driver, which does not rehydrate session state between requests.
 */
abstract class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $demoUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('overview');
        $this->seed();
        $this->demoUser = User::where('email', 'demo@overview.local')->firstOrFail();
    }
}

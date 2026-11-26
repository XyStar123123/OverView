<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable(); // plain column: FK to workspaces would be circular (see docs/deviations.md)
            $table->string('name');
            $table->string('type', 32); // mock, openai_compatible, litellm, omni_route, custom
            $table->string('base_url');
            $table->string('api_key_ref'); // secret reference, never plaintext (LLM-012)
            $table->string('api_format', 32)->default('chat');
            $table->string('status', 32)->default('unknown');
            $table->json('models')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id']);
        });

        Schema::create('runtimes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('name');
            $table->string('adapter', 32); // mock, openhands, langgraph, custom
            $table->string('status', 32)->default('unknown');
            $table->json('capabilities')->nullable();
            $table->timestamps();

            $table->index(['workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtimes');
        Schema::dropIfExists('gateways');
    }
};

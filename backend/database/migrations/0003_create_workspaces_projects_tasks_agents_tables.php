<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            // FK to gateways/runtimes would be circular (those tables carry workspace_id);
            // application-level consistency is enforced by the API layer instead.
            $table->unsignedBigInteger('default_gateway_id')->nullable();
            $table->string('default_model')->nullable();
            $table->unsignedBigInteger('default_runtime_id')->nullable();
            $table->json('memory_policy')->nullable(); // default { "require_review_for_shared_scopes": true }
            $table->timestamps();
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32); // owner, admin, member, viewer
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('objectives')->nullable(); // array of string
            $table->string('status', 32)->default('planning');
            $table->string('priority', 32)->default('medium');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('feature');
            $table->string('type_custom')->nullable();
            $table->string('status', 32)->default('todo');
            $table->string('priority', 32)->default('medium');
            $table->string('assignee_type', 16)->nullable(); // user, agent
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->string('creator_type', 16)->default('user');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->date('due_date')->nullable();
            $table->json('labels')->nullable(); // array of string
            $table->boolean('override_agent_model')->default(false);
            $table->unsignedBigInteger('override_gateway_id')->nullable();
            $table->string('override_model')->nullable();
            $table->timestamps();

            $table->foreign('parent_id', 'tasks_parent_id_fk')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('override_gateway_id', 'tasks_override_gateway_id_fk')->references('id')->on('gateways')->nullOnDelete();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('avatar')->nullable();
            $table->text('description')->nullable();
            $table->string('role');
            $table->text('instructions')->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 32)->default('available');
            $table->foreignId('gateway_id')->constrained()->nullOnDelete();
            $table->string('default_model');
            $table->foreignId('runtime_id')->constrained()->nullOnDelete();
            $table->json('tool_allowlist')->nullable();
            $table->boolean('memory_enabled')->default(true);
            $table->json('allowed_memory_scopes')->nullable();
            $table->json('memory_retrieval')->nullable();
            $table->json('execution_budget')->nullable();
            $table->unsignedInteger('concurrency_limit')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('project_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unique(['project_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_agents');
        Schema::dropIfExists('agents');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign('tasks_parent_id_fk');
            $table->dropForeign('tasks_override_gateway_id_fk');
        });
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};

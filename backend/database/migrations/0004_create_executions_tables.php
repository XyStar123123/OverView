<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('runtime_id')->constrained()->nullOnDelete();
            $table->foreignId('gateway_id')->constrained()->nullOnDelete();
            $table->string('model'); // effective model (result of model resolution)
            $table->json('model_resolution')->nullable(); // { gateway, model, source: workspace|agent|task }
            $table->string('status', 32)->default('queued'); // queued, running, waiting, completed, failed, cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('usage')->nullable(); // { input_tokens, output_tokens, context_tokens, tool_invocations, retrieved_memory_count, estimated_cost, duration_ms }
            $table->string('failure_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->index(['task_id', 'status']);
        });

        Schema::create('execution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->integer('sequence');
            $table->string('type', 64); // ExecutionEventType values (PRD 6.12)
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['execution_id', 'sequence']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 16); // user, agent
            $table->unsignedBigInteger('actor_id');
            $table->text('body');
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('execution_events');
        Schema::dropIfExists('executions');
    }
};

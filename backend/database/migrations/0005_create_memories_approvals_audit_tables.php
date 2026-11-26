<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('name');
            $table->string('artifact_type', 32)->default('other'); // code, document, report, image, data, other
            $table->string('file_path'); // relative to the "overview" local disk (storage/app/overview/)
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->integer('version')->default(1);
            $table->string('status', 32)->default('submitted'); // submitted, reviewed, accepted, rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'artifact_type']);
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16); // workspace, project, agent, task (MAC-03)
            $table->string('type', 16); // working, episodic, semantic, procedural
            $table->unsignedBigInteger('project_id')->nullable(); // required when scope=project
            $table->unsignedBigInteger('agent_id')->nullable(); // required when scope=agent
            $table->unsignedBigInteger('task_id')->nullable(); // required when scope=task
            $table->unsignedBigInteger('execution_id')->nullable(); // provenance
            $table->string('title');
            $table->text('content');
            $table->string('status', 32)->default('candidate'); // 8 lifecycle states
            $table->integer('version')->default(1);
            $table->decimal('confidence', 3, 2)->default(0.50);
            $table->string('trust', 8)->default('medium'); // MEM-051
            $table->json('source')->nullable(); // { type, ref_id, actor_type, actor_id, summary }
            $table->string('created_by_type', 16)->default('agent'); // user, agent, system
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable(); // user id
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'scope']);
            $table->index(['scope', 'status']);
            $table->foreign('project_id', 'memories_project_id_fk')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('agent_id', 'memories_agent_id_fk')->references('id')->on('agents')->nullOnDelete();
            $table->foreign('task_id', 'memories_task_id_fk')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('execution_id', 'memories_execution_id_fk')->references('id')->on('executions')->nullOnDelete();
        });

        Schema::create('memory_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->text('content');
            $table->string('changed_by_type', 16); // user, agent, system
            $table->unsignedBigInteger('changed_by_id');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['memory_id', 'version']);
        });

        Schema::create('memory_retrievals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->integer('rank');
            $table->float('score');
            $table->timestamp('retrieved_at')->useCurrent();
            $table->index(['execution_id']);
            $table->index(['memory_id']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('request_type', 32); // task_review, memory_review
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('memory_id')->nullable();
            $table->unsignedBigInteger('execution_id')->nullable();
            $table->string('status', 16)->default('pending'); // pending, approved, rejected, expired
            $table->string('requested_by_type', 16); // agent, user, system
            $table->unsignedBigInteger('requested_by_id');
            $table->unsignedBigInteger('decision_user_id')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'request_type']);
            $table->foreign('task_id', 'approvals_task_id_fk')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('memory_id', 'approvals_memory_id_fk')->references('id')->on('memories')->nullOnDelete();
            $table->foreign('execution_id', 'approvals_execution_id_fk')->references('id')->on('executions')->nullOnDelete();
            $table->foreign('decision_user_id', 'approvals_decision_user_id_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 16); // user, agent, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedBigInteger('execution_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
            $table->foreign('execution_id', 'audit_logs_execution_id_fk')->references('id')->on('executions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('memory_retrievals');
        Schema::dropIfExists('memory_versions');
        Schema::dropIfExists('memories');
        Schema::dropIfExists('artifacts');
    }
};

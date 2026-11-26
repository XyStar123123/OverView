<?php

use App\Http\Controllers\Api\V1\AgentsController;
use App\Http\Controllers\Api\V1\ApprovalsController;
use App\Http\Controllers\Api\V1\ArtifactsController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExecutionsController;
use App\Http\Controllers\Api\V1\GatewaysController;
use App\Http\Controllers\Api\V1\MemoriesController;
use App\Http\Controllers\Api\V1\ProjectsController;
use App\Http\Controllers\Api\V1\RuntimesController;
use App\Http\Controllers\Api\V1\TasksController;
use App\Http\Controllers\Api\V1\WorkspacesController;
use Illuminate\Support\Facades\Route;

/*
| OverView Phase-1 API (ARCHITECTURE.md §4.2).
| Registered under the "api/v1" prefix via bootstrap/app.php; session-cookie
| auth (web group), CORS via HandleCors. Every response follows §4.1 shapes.
*/
Route::prefix('auth')->middleware(['web'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth');
});

Route::middleware(['web', 'auth'])->group(function () {
    // Workspaces
    Route::get('/workspaces', [WorkspacesController::class, 'index']);
    Route::get('/workspaces/{id}', [WorkspacesController::class, 'show']);
    Route::patch('/workspaces/{id}', [WorkspacesController::class, 'update']);
    Route::get('/workspaces/{id}/members', [WorkspacesController::class, 'members']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Projects
    Route::get('/projects', [ProjectsController::class, 'index']);
    Route::post('/projects', [ProjectsController::class, 'store']);
    Route::get('/projects/{id}', [ProjectsController::class, 'show']);
    Route::patch('/projects/{id}', [ProjectsController::class, 'update']);
    Route::delete('/projects/{id}', [ProjectsController::class, 'destroy']);
    Route::get('/projects/{id}/agents', [ProjectsController::class, 'agents']);
    Route::post('/projects/{id}/agents', [ProjectsController::class, 'assignAgent']);
    Route::get('/projects/{id}/memory', [ProjectsController::class, 'memory']);
    Route::get('/projects/{id}/tasks', [ProjectsController::class, 'tasks']);
    Route::post('/projects/{id}/tasks', [ProjectsController::class, 'createTask']);

    // Tasks
    Route::get('/tasks/{id}', [TasksController::class, 'show']);
    Route::patch('/tasks/{id}', [TasksController::class, 'update']);
    Route::post('/tasks/{id}/assign', [TasksController::class, 'assign']);
    Route::post('/tasks/{id}/comments', [TasksController::class, 'addComment']);
    Route::get('/tasks/{id}/comments', [TasksController::class, 'comments']);
    Route::post('/tasks/{id}/execute', [TasksController::class, 'execute']);
    Route::post('/tasks/{id}/review', [TasksController::class, 'review']);
    Route::patch('/tasks/{id}/model-override', [TasksController::class, 'modelOverride']);
    Route::get('/tasks/{id}/artifacts', [TasksController::class, 'artifacts']);
    Route::get('/tasks/{id}/memory', [TasksController::class, 'memory']);
    Route::get('/tasks/{id}/executions', [TasksController::class, 'executions']);

    // Agents
    Route::get('/agents', [AgentsController::class, 'index']);
    Route::post('/agents', [AgentsController::class, 'store']);
    Route::get('/agents/{id}', [AgentsController::class, 'show']);
    Route::patch('/agents/{id}', [AgentsController::class, 'update']);
    Route::delete('/agents/{id}', [AgentsController::class, 'destroy']);
    Route::post('/agents/{id}/activate', [AgentsController::class, 'activate']);
    Route::post('/agents/{id}/deactivate', [AgentsController::class, 'deactivate']);
    Route::get('/agents/{id}/executions', [AgentsController::class, 'executions']);
    Route::get('/agents/{id}/memory', [AgentsController::class, 'memory']);
    Route::patch('/agents/{id}/memory-config', [AgentsController::class, 'memoryConfig']);

    // Gateways & Runtimes
    Route::get('/gateways', [GatewaysController::class, 'index']);
    Route::post('/gateways', [GatewaysController::class, 'store']);
    Route::patch('/gateways/{id}', [GatewaysController::class, 'update']);
    Route::post('/gateways/{id}/test', [GatewaysController::class, 'test']);
    Route::get('/gateways/{id}/models', [GatewaysController::class, 'models']);
    Route::get('/runtimes', [RuntimesController::class, 'index']);
    Route::post('/runtimes', [RuntimesController::class, 'store']);
    Route::post('/runtimes/{id}/health', [RuntimesController::class, 'health']);

    // Executions
    Route::get('/executions', [ExecutionsController::class, 'index']);
    Route::get('/executions/{id}', [ExecutionsController::class, 'show']);
    Route::get('/executions/{id}/events', [ExecutionsController::class, 'events']);
    Route::get('/executions/{id}/artifacts', [ExecutionsController::class, 'artifacts']);
    Route::get('/executions/{id}/memories', [ExecutionsController::class, 'memories']);
    Route::get('/executions/{id}/model-resolution', [ExecutionsController::class, 'modelResolution']);
    Route::post('/executions/{id}/cancel', [ExecutionsController::class, 'cancel']);

    // Artifacts
    Route::get('/artifacts/{id}', [ArtifactsController::class, 'show']);
    Route::get('/artifacts/{id}/download', [ArtifactsController::class, 'download']);
    Route::post('/artifacts/{id}/review', [ArtifactsController::class, 'review']);

    // Memories ("/memories/search" must be registered before "/memories/{id}").
    Route::get('/memories', [MemoriesController::class, 'index']);
    Route::post('/memories', [MemoriesController::class, 'store']);
    Route::post('/memories/search', [MemoriesController::class, 'search']);
    Route::get('/memories/{id}', [MemoriesController::class, 'show']);
    Route::patch('/memories/{id}', [MemoriesController::class, 'update']);
    Route::delete('/memories/{id}', [MemoriesController::class, 'destroy']);
    Route::post('/memories/{id}/approve', [MemoriesController::class, 'approve']);
    Route::post('/memories/{id}/reject', [MemoriesController::class, 'reject']);
    Route::post('/memories/{id}/archive', [MemoriesController::class, 'archive']);
    Route::post('/memories/{id}/supersede', [MemoriesController::class, 'supersede']);
    Route::get('/memories/{id}/versions', [MemoriesController::class, 'versions']);
    Route::get('/memories/{id}/sources', [MemoriesController::class, 'sources']);
    Route::get('/memories/{id}/history', [MemoriesController::class, 'history']);

    // Approvals & Audit
    Route::get('/approvals', [ApprovalsController::class, 'index']);
    Route::get('/approvals/{id}', [ApprovalsController::class, 'show']);
    Route::post('/approvals/{id}/approve', [ApprovalsController::class, 'approve']);
    Route::post('/approvals/{id}/reject', [ApprovalsController::class, 'reject']);
    Route::get('/audit-logs', [AuditController::class, 'index']);
});

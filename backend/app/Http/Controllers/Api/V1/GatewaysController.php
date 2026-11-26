<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\Gateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewaysController extends ApiV1Controller
{
    /** GET /gateways — §4.2. */
    public function index(Request $request)
    {
        $workspace = $this->workspaceFor($request);

        $gateways = Gateway::where('workspace_id', $workspace->id)->orderBy('id')->get();

        return $this->data($gateways->map(fn (Gateway $g) => $this->transformGateway($g))->all());
    }

    /** POST /gateways — 201 { data: Gateway }. */
    public function store(Request $request)
    {
        $workspace = $this->workspaceFor($request);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:mock,openai_compatible,litellm,omni_route,custom',
            'baseUrl' => 'required|string|max:255',
            'apiKeyRef' => 'required|string|max:255',
            'apiFormat' => 'sometimes|string|max:32',
            'models' => 'sometimes|nullable|array',
            'models.*' => 'string',
        ]);

        $gateway = Gateway::create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'base_url' => $validated['baseUrl'],
            'api_key_ref' => $validated['apiKeyRef'],
            'api_format' => $validated['apiFormat'] ?? 'chat',
            'status' => 'unknown',
            'models' => $validated['models'] ?? [],
        ]);

        return $this->data($this->transformGateway($gateway), 201);
    }

    /** PATCH /gateways/{id} — subset fields. */
    public function update(Request $request, int $id)
    {
        $gateway = $this->gatewayFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|in:mock,openai_compatible,litellm,omni_route,custom',
            'baseUrl' => 'sometimes|string|max:255',
            'apiKeyRef' => 'sometimes|string|max:255',
            'apiFormat' => 'sometimes|string|max:32',
            'status' => 'sometimes|string|in:unknown,connected,error',
            'models' => 'sometimes|nullable|array',
            'models.*' => 'string',
        ]);

        $gateway->update([
            'name' => $validated['name'] ?? $gateway->name,
            'type' => $validated['type'] ?? $gateway->type,
            'base_url' => $validated['baseUrl'] ?? $gateway->base_url,
            'api_key_ref' => $validated['apiKeyRef'] ?? $gateway->api_key_ref,
            'api_format' => $validated['apiFormat'] ?? $gateway->api_format,
            'status' => $validated['status'] ?? $gateway->status,
            'models' => array_key_exists('models', $validated) ? ($validated['models'] ?? []) : $gateway->models,
        ]);

        return $this->data($this->transformGateway($gateway->fresh()));
    }

    /**
     * POST /gateways/{id}/test — mock connection test (§4.2 AC-03): always
     * succeeds, never echoes the secret, records last_checked_at.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $gateway = $this->gatewayFor($request, $id);

        $latencyMs = 8 + $gateway->id * 4;

        $gateway->update(['status' => 'connected', 'last_checked_at' => now()]);

        return response()->json([
            'ok' => true,
            'message' => 'mock connection ok',
            'latencyMs' => $latencyMs,
            'models' => $gateway->models ?? [],
        ]);
    }

    /** GET /gateways/{id}/models — configured model list (§4.2). */
    public function models(Request $request, int $id)
    {
        $gateway = $this->gatewayFor($request, $id);

        return $this->data(['models' => $gateway->models ?? [], 'discovered' => false]);
    }

    private function gatewayFor(Request $request, int $id): Gateway
    {
        $gateway = Gateway::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($gateway === null) {
            throw ApiException::notFound('Gateway not found.');
        }

        return $gateway;
    }
}

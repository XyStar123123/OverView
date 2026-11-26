<?php

namespace App\Http;

use App\Http\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders every /api exception into the binding ApiError shape
 * (ARCHITECTURE.md §4.1): { error: { code, message, details? } }.
 */
class ApiErrorFormatter
{
    /**
     * @return Response<string> JSON body when the request is an API request,
     *                         null otherwise (Laravel default rendering).
     */
    public function render(Throwable $e, Request $request): ?Response
    {
        if (!str_starts_with($request->path(), 'api/')) {
            return null;
        }

        [$code, $message, $status, $details] = $this->map($e);

        $body = ['error' => ['code' => $code, 'message' => $message]];

        if ($details !== []) {
            $body['error']['details'] = $details;
        }

        return response()->json($body, $status);
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: array<string, mixed>}
     */
    private function map(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return [$e->errorCode, $e->getMessage(), $e->status, $e->details];
        }

        if ($e instanceof ValidationException) {
            $bag = $e->errors();

            return [
                'validation_failed',
                'Validation failed.',
                422,
                ['fields' => $bag],
            ];
        }

        if ($e instanceof ModelNotFoundException) {
            return ['not_found', 'Resource not found.', 404, []];
        }

        if ($e instanceof AuthenticationException || $e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            $status = $e instanceof AuthorizationException ? 403 : 401;

            return [
                $status === 403 ? 'forbidden' : 'unauthorized',
                'Access denied.',
                $status,
                [],
            ];
        }

        if ($e instanceof HttpException) {
            $code = match ($e->getStatusCode()) {
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                default => 'invalid_state',
            };

            return [$code, $e->getMessage() ?: 'Request rejected.', $e->getStatusCode(), []];
        }

        return ['internal', 'Internal server error.', 500, []];
    }
}

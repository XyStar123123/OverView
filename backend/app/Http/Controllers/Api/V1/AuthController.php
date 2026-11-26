<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class AuthController extends ApiV1Controller
{
    /**
     * POST /auth/login — bare { user, workspace } 200 (§4.2); the frontend
     * client.ts reads the payload bare, not from a data envelope.
     */
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $user = User::where('email', (string) $request->input('email'))->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password_hash)) {
            // §4.2: failed login is 401 unauthorized — the frontend keys its
            // session teardown off code "unauthorized" (client.ts), not 422.
            throw ApiException::unauthorized('Invalid credentials.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        $workspace = $user->workspaces()->first();
        $role = WorkspaceMember::where('workspace_id', $workspace?->id)
            ->where('user_id', $user->id)
            ->value('role') ?? 'member';

        return response()->json([
            'user' => $this->transformUser($user),
            'workspace' => $workspace !== null ? $this->transformWorkspace($workspace) : null,
            'role' => $role,
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Session::flush();

        return $this->noContent();
    }

    /** GET /auth/me — bare { user, role } (§4.2). */
    public function me(Request $request)
    {
        $user = $request->user();
        $workspace = $user->workspaces()->first();

        $role = $workspace !== null
            ? (WorkspaceMember::where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->value('role') ?? 'member')
            : 'member';

        return response()->json([
            'user' => $this->transformUser($user),
            'role' => $role,
        ]);
    }
}

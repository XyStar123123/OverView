// Thin fetch wrapper for the OverView API (ARCHITECTURE.md §4/§7).
// - Base: "/api/v1" by default (Vite dev proxy forwards /api to the backend),
//   overridable via the VITE_API_BASE_URL env var for non-proxied setups.
// - Session-cookie auth: credentials "include".
// - Every 4xx/5xx body is normalized to ApiError (§4.1 error shape).
import type { User, Workspace } from "./types";

export const API_BASE: string =
  (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? "/api/v1";

export interface ApiErrorBody {
  code:
    | "unauthorized"
    | "forbidden"
    | "not_found"
    | "validation_failed"
    | "conflict"
    | "invalid_state"
    | "internal";
  message: string;
  details?: Record<string, unknown>;
}

export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly details: Record<string, unknown> | undefined;

  constructor(status: number, code: string, message: string, details?: Record<string, unknown>) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(init.body ? { "Content-Type": "application/json" } : {}),
      ...(init.headers ?? {}),
    },
  });
  if (res.status === 204) {
    return undefined as T;
  }
  const text = await res.text();
  const body: unknown = text ? JSON.parse(text) : undefined;
  if (!res.ok) {
    const err = body as { error?: { code?: string; message?: string; details?: Record<string, unknown> } } | undefined;
    throw new ApiError(
      res.status,
      err?.error?.code ?? "internal",
      err?.error?.message ?? `Request failed with status ${res.status}`,
      err?.error?.details,
    );
  }
  return body as T;
}

function jsonBody(data: unknown): string {
  return JSON.stringify(data);
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: "POST", body: data === undefined ? undefined : jsonBody(data) }),
  patch: <T>(path: string, data: unknown) =>
    request<T>(path, { method: "PATCH", body: jsonBody(data) }),
  del: <T = undefined>(path: string) => request<T>(path, { method: "DELETE" }),
};

export interface MeResponse {
  user: User;
  role: string;
}

export interface AuthContext {
  user: User;
  workspace: Workspace;
}

let authCache: AuthContext | null = null;

/**
 * Loads session identity. Returns null (not a 401 ApiError) when there is no
 * session, so callers can fall back to mock data or the login screen.
 */
export async function loadAuthContext(): Promise<AuthContext | null> {
  if (authCache) return authCache;
  try {
    const me = await api.get<MeResponse>("/auth/me");
    // /auth/me does not carry the workspace; fetch it separately.
    const ws = await api.get<{ data: Workspace[] }>("/workspaces");
    if (!ws.data.length) return null;
    authCache = { user: me.user, workspace: ws.data[0] };
    return authCache;
  } catch (e) {
    if (e instanceof ApiError && e.code === "unauthorized") return null;
    throw e;
  }
}

export function clearAuthCache(): void {
  authCache = null;
}

export async function logout(): Promise<void> {
  await api.post("/auth/logout");
  clearAuthCache();
}

export async function login(email: string, password: string): Promise<AuthContext> {
  const res = await api.post<{ user: User; workspace: Workspace }>("/auth/login", { email, password });
  authCache = res;
  return res;
}

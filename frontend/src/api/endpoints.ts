// One function per API route (ARCHITECTURE.md §4.2).
import { api } from "./client";
import type {
  Agent,
  Approval,
  Artifact,
  DashboardStats,
  AuditLogEntry,
  Execution,
  ExecutionEvent,
  Gateway,
  GatewayTestResult,
  Memory,
  MemoryDetail,
  MemoryHistoryEntry,
  MemorySourceDetail,
  MemoryVersion,
  Project,
  Runtime,
  SearchResults,
  Task,
  TaskComment,
  TaskModelOverride,
  Workspace,
} from "./types";

type DataResponse<T> = { data: T };

// --- Auth (session login handled by client.ts) ---

// --- Workspaces ---
export function getWorkspaces() {
  return api.get<DataResponse<Workspace[]>>("/workspaces");
}
export function getWorkspace(id: number) {
  return api.get<DataResponse<Workspace>>(`/workspaces/${id}`);
}
export function patchWorkspace(id: number, body: Partial<Omit<Workspace, "id" | "slug" | "memoryPolicy">> & { memoryPolicy?: Workspace["memoryPolicy"] }) {
  return api.patch<DataResponse<Workspace>>(`/workspaces/${id}`, body);
}
export function getWorkspaceMembers(id: number) {
  return api.get<DataResponse<{ user: { id: number; name: string; email: string }; role: string }[]>>(`/workspaces/${id}/members`);
}

// --- Dashboard ---
export function getDashboardStats() {
  return api.get<DashboardStats>("/dashboard/stats");
}

// --- Projects ---
export function getProjects(status?: string) {
  return api.get<DataResponse<Project[]>>(`/projects${status ? `?status=${status}` : ""}`);
}
export function createProject(body: {
  name: string;
  slug?: string;
  description?: string;
  objectives?: string[];
  status?: string;
  priority?: string;
  agentIds?: number[];
}) {
  return api.post<DataResponse<Project>>("/projects", body);
}
export function getProject(id: number) {
  return api.get<DataResponse<Project>>(`/projects/${id}`);
}
export function patchProject(id: number, body: Record<string, unknown>) {
  return api.patch<DataResponse<Project>>(`/projects/${id}`, body);
}
export function getProjectAgents(id: number) {
  return api.get<DataResponse<Agent[]>>(`/projects/${id}/agents`);
}
export function getProjectMemory(id: number, query?: { type?: string; status?: string }) {
  const params = new URLSearchParams();
  if (query?.type) params.set("type", query.type);
  if (query?.status) params.set("status", query.status);
  const qs = params.toString();
  return api.get<DataResponse<Memory[]>>(`/projects/${id}/memory${qs ? `?${qs}` : ""}`);
}
export function getProjectTasks(id: number, query?: { status?: string; type?: string }) {
  const params = new URLSearchParams();
  if (query?.status) params.set("status", query.status);
  if (query?.type) params.set("type", query.type);
  const qs = params.toString();
  return api.get<DataResponse<Task[]>>(`/projects/${id}/tasks${qs ? `?${qs}` : ""}`);
}
export function createTask(id: number, body: {
  title: string;
  description?: string;
  type?: string;
  priority?: string;
  assignee?: { type: "user" | "agent"; id: number };
  dueDate?: string | null;
  labels?: string[];
  overrideAgentModel?: boolean;
  overrideGatewayId?: number | null;
  overrideModel?: string | null;
}) {
  return api.post<DataResponse<Task>>(`/projects/${id}/tasks`, body);
}

// --- Tasks ---
export function getTask(id: number) {
  return api.get<DataResponse<Task>>(`/tasks/${id}`);
}
export function patchTask(id: number, body: Record<string, unknown>) {
  return api.patch<DataResponse<Task>>(`/tasks/${id}`, body);
}
export function assignTask(id: number, assignee: { type: "user" | "agent"; id: number }) {
  return api.post<DataResponse<Task>>(`/tasks/${id}/assign`, { assignee });
}
export function getTaskComments(id: number) {
  return api.get<DataResponse<TaskComment[]>>(`/tasks/${id}/comments`);
}
export function createTaskComment(id: number, body: { body: string }) {
  return api.post<DataResponse<TaskComment>>(`/tasks/${id}/comments`, body);
}
export function executeTask(id: number, memorySearchQuery?: string) {
  return api.post<DataResponse<Execution> & { message: string }>(
    `/tasks/${id}/execute`,
    memorySearchQuery ? { memorySearchQuery } : {},
  );
}
export function reviewTask(id: number, decision: "approve" | "reject", reason?: string) {
  return api.post<{ data: Approval; task: Task }>(`/tasks/${id}/review`, { decision, reason });
}
export function patchTaskModelOverride(id: number, body: { enabled: boolean; gatewayId?: number | null; model?: string | null }) {
  return api.patch<DataResponse<TaskModelOverride>>(`/tasks/${id}/model-override`, body);
}
export function getTaskArtifacts(id: number) {
  return api.get<DataResponse<Artifact[]>>(`/tasks/${id}/artifacts`);
}
export function getTaskMemory(id: number) {
  return api.get<DataResponse<{ working: Memory[]; proposed: Memory[] }>>(`/tasks/${id}/memory`);
}
export function getTaskExecutions(id: number) {
  return api.get<DataResponse<Execution[]>>(`/tasks/${id}/executions`);
}

// --- Agents ---
export function getAgents(status?: string) {
  return api.get<DataResponse<Agent[]>>(`/agents${status ? `?status=${status}` : ""}`);
}
export function getAgent(id: number) {
  return api.get<DataResponse<Agent>>(`/agents/${id}`);
}
export function patchAgent(id: number, body: Record<string, unknown>) {
  return api.patch<DataResponse<Agent>>(`/agents/${id}`, body);
}
export function activateAgent(id: number) {
  return api.post<DataResponse<Agent>>(`/agents/${id}/activate`);
}
export function deactivateAgent(id: number) {
  return api.post<DataResponse<Agent>>(`/agents/${id}/deactivate`);
}
export function getAgentExecutions(id: number, status?: string) {
  return api.get<DataResponse<Execution[]>>(`/agents/${id}/executions${status ? `?status=${status}` : ""}`);
}
export function getAgentMemory(id: number) {
  return api.get<DataResponse<Memory[]>>(`/agents/${id}/memory`);
}
export function patchAgentMemoryConfig(
  id: number,
  body: {
    enabled?: boolean;
    allowedScopes?: Agent["memory"]["allowedScopes"];
    retrieval?: Agent["memory"]["retrieval"];
  },
) {
  return api.patch<DataResponse<Agent["memory"]>>(`/agents/${id}/memory-config`, body);
}

// --- Gateways & Runtimes ---
export function getGateways() {
  return api.get<DataResponse<Gateway[]>>("/gateways");
}
export function createGateway(body: {
  name: string;
  type: string;
  baseUrl: string;
  apiKeyRef: string;
  apiFormat?: string;
  models?: string[];
}) {
  return api.post<DataResponse<Gateway>>("/gateways", body);
}
export function patchGateway(id: number, body: Record<string, unknown>) {
  return api.patch<DataResponse<Gateway>>(`/gateways/${id}`, body);
}
export function testGateway(id: number) {
  return api.post<GatewayTestResult>(`/gateways/${id}/test`);
}
export function getGatewayModels(id: number) {
  return api.get<{ data: { models: string[]; discovered: boolean } }>(`/gateways/${id}/models`);
}
export function getRuntimes() {
  return api.get<DataResponse<Runtime[]>>("/runtimes");
}
export function runRuntimeHealth(id: number) {
  return api.post<{ healthy: boolean; status: string }>(`/runtimes/${id}/health`);
}

// --- Executions ---
export function getExecutions(query?: { agentId?: number; projectId?: number; status?: string }) {
  const params = new URLSearchParams();
  if (query?.agentId) params.set("agentId", String(query.agentId));
  if (query?.projectId) params.set("projectId", String(query.projectId));
  if (query?.status) params.set("status", query.status);
  const qs = params.toString();
  return api.get<DataResponse<Execution[]>>(`/executions${qs ? `?${qs}` : ""}`);
}
export function getExecution(id: number) {
  return api.get<DataResponse<Execution>>(`/executions/${id}`);
}
export function getExecutionEvents(id: number, afterSequence?: number) {
  return api.get<DataResponse<ExecutionEvent[]>>(
    `/executions/${id}/events${afterSequence ? `?afterSequence=${afterSequence}` : ""}`,
  );
}
export function getExecutionArtifacts(id: number) {
  return api.get<DataResponse<Artifact[]>>(`/executions/${id}/artifacts`);
}
export function getExecutionMemories(id: number) {
  return api.get<DataResponse<{ retrieved: (Memory & { rank: number; score: number })[]; proposed: Memory[] }>>(
    `/executions/${id}/memories`,
  );
}
export function cancelExecution(id: number) {
  return api.post<DataResponse<Execution>>(`/executions/${id}/cancel`);
}

// --- Artifacts ---
export function getArtifact(id: number) {
  return api.get<DataResponse<Artifact>>(`/artifacts/${id}`);
}
export function reviewArtifact(id: number, decision: "accept" | "reject", note?: string) {
  return api.post<DataResponse<Artifact>>(`/artifacts/${id}/review`, { decision, note });
}
export function artifactDownloadUrl(id: number): string {
  // Binary download; uses the session cookie via browser navigation.
  return `${(import.meta.env.VITE_API_BASE_URL as string | undefined) ?? "/api/v1"}/artifacts/${id}/download`;
}

// --- Memories ---
export function getMemories(query?: {
  scope?: string;
  type?: string;
  status?: string;
  projectId?: number;
  agentId?: number;
  q?: string;
}) {
  const params = new URLSearchParams();
  if (query?.scope) params.set("scope", query.scope);
  if (query?.type) params.set("type", query.type);
  if (query?.status) params.set("status", query.status);
  if (query?.projectId) params.set("projectId", String(query.projectId));
  if (query?.agentId) params.set("agentId", String(query.agentId));
  if (query?.q) params.set("q", query.q);
  const qs = params.toString();
  return api.get<DataResponse<Memory[]>>(`/memories${qs ? `?${qs}` : ""}`);
}
export function createMemory(body: {
  scope: string;
  type: string;
  title: string;
  content: string;
  projectId?: number | null;
  agentId?: number | null;
  taskId?: number | null;
  confidence?: number;
  trust?: string;
}) {
  return api.post<DataResponse<Memory>>("/memories", body);
}
export function getMemory(id: number) {
  return api.get<DataResponse<MemoryDetail & { memory: Memory; versions: MemoryVersion[]; retrievals: MemoryDetail["retrievals"] }>>(`/memories/${id}`);
}
export function patchMemory(id: number, body: { content?: string; title?: string; confidence?: number; expiresAt?: string | null }) {
  return api.patch<DataResponse<Memory>>(`/memories/${id}`, body);
}
export function deleteMemory(id: number) {
  return api.del(`/memories/${id}`);
}
export function searchMemories(body: {
  query: string;
  scope?: string;
  type?: string;
  status?: string;
  projectId?: number;
  agentId?: number;
  limit?: number;
}) {
  return api.post<DataResponse<SearchResults>>("/memories/search", body);
}
export function approveMemory(id: number) {
  return api.post<{ data: Memory; approval: Approval }>(`/memories/${id}/approve`);
}
export function rejectMemory(id: number, reason?: string) {
  return api.post<{ data: Memory; approval: Approval }>(`/memories/${id}/reject`, reason ? { reason } : {});
}
export function archiveMemory(id: number) {
  return api.post<DataResponse<Memory>>(`/memories/${id}/archive`);
}
export function supersedeMemory(id: number, content: string, reason?: string) {
  return api.post<DataResponse<Memory>>(`/memories/${id}/supersede`, { content, reason });
}
export function getMemoryVersions(id: number) {
  return api.get<DataResponse<MemoryVersion[]>>(`/memories/${id}/versions`);
}
export function getMemorySources(id: number) {
  return api.get<DataResponse<MemorySourceDetail>>(`/memories/${id}/sources`);
}
export function getMemoryHistory(id: number) {
  return api.get<DataResponse<MemoryHistoryEntry[]>>(`/memories/${id}/history`);
}

// --- Approvals ---
export function getApprovals(query?: { status?: string; requestType?: string }) {
  const params = new URLSearchParams();
  if (query?.status) params.set("status", query.status);
  if (query?.requestType) params.set("requestType", query.requestType);
  const qs = params.toString();
  return api.get<DataResponse<Approval[]>>(`/approvals${qs ? `?${qs}` : ""}`);
}
export function getApproval(id: number) {
  return api.get<DataResponse<Approval>>(`/approvals/${id}`);
}
export function approveApproval(id: number) {
  return api.post<DataResponse<Approval>>(`/approvals/${id}/approve`);
}
export function rejectApproval(id: number, reason?: string) {
  return api.post<DataResponse<Approval>>(`/approvals/${id}/reject`, reason ? { reason } : {});
}

// --- Audit ---
export function getAuditLogs(query?: { resourceType?: string; resourceId?: number; limit?: number }) {
  const params = new URLSearchParams();
  if (query?.resourceType) params.set("resourceType", query.resourceType);
  if (query?.resourceId) params.set("resourceId", String(query.resourceId));
  if (query?.limit) params.set("limit", String(query.limit));
  const qs = params.toString();
  return api.get<DataResponse<AuditLogEntry[]>>(`/audit-logs${qs ? `?${qs}` : ""}`);
}

// Shared types for the OverView API. Copied from ARCHITECTURE.md §4.1.

export type Role = "owner" | "admin" | "member" | "viewer";
export type TaskType =
  | "feature"
  | "bug"
  | "fix"
  | "research"
  | "documentation"
  | "maintenance"
  | "investigation"
  | "custom";
export type TaskStatus =
  | "todo"
  | "in_progress"
  | "pending"
  | "blocked"
  | "in_review"
  | "done"
  | "cancelled";
export type ProjectStatus =
  | "planning"
  | "active"
  | "paused"
  | "completed"
  | "archived";
export type AgentStatus =
  | "draft"
  | "available"
  | "working"
  | "blocked"
  | "waiting_for_approval"
  | "offline"
  | "disabled"
  | "error";
export type ExecutionStatus =
  | "queued"
  | "running"
  | "waiting"
  | "completed"
  | "failed"
  | "cancelled";
export type MemoryScope = "workspace" | "project" | "agent" | "task";
export type MemoryType = "working" | "episodic" | "semantic" | "procedural";
export type MemoryStatus =
  | "candidate"
  | "pending_review"
  | "approved"
  | "active"
  | "superseded"
  | "archived"
  | "rejected"
  | "expired";
export type ApprovalStatus = "pending" | "approved" | "rejected" | "expired";
export type Priority = "low" | "medium" | "high";

export interface User {
  id: number;
  name: string;
  email: string;
}

export interface Workspace {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  defaultGatewayId: number | null;
  defaultModel: string | null;
  defaultRuntimeId: number | null;
  memoryPolicy: { requireReviewForSharedScopes: boolean };
}

export interface Project {
  id: number;
  workspaceId: number;
  slug: string;
  name: string;
  description: string;
  objectives: string[];
  status: ProjectStatus;
  priority: Priority;
  startDate: string | null;
  dueDate: string | null;
  ownerUserId: number;
  agentCount: number;
  taskCount: number;
}

export interface TaskModelOverride {
  enabled: boolean;
  gatewayId: number | null;
  model: string | null;
  resolvedSource: "task" | "agent" | "workspace";
}

export interface Task {
  id: number;
  projectId: number;
  parentTaskId: number | null;
  title: string;
  description: string;
  type: TaskType;
  customType: string | null;
  status: TaskStatus;
  priority: Priority;
  assignee: { type: "user" | "agent"; id: number } | null;
  createdBy: { type: "user" | "agent"; id: number };
  dueDate: string | null;
  labels: string[];
  modelOverride: TaskModelOverride;
  hasRunningExecution: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface TaskComment {
  id: number;
  taskId: number;
  actor: { type: "user" | "agent"; id: number };
  body: string;
  executionId: number | null;
  createdAt: string;
}

export interface Agent {
  id: number;
  name: string;
  slug: string;
  avatar: string | null;
  description: string;
  role: string;
  instructions: string | null;
  version: number;
  status: AgentStatus;
  gatewayId: number;
  defaultModel: string;
  runtimeId: number;
  toolAllowlist: string[];
  memory: {
    enabled: boolean;
    allowedScopes: MemoryScope[];
    retrieval: { enabled: boolean; maxMemories: number; contextBudgetTokens: number };
  };
  executionBudget: { maxExecutionsPerTask: number } | null;
  concurrencyLimit: number;
  createdAt: string;
  updatedAt: string;
}

export interface Gateway {
  id: number;
  name: string;
  type: string;
  baseUrl: string;
  apiKeyRef: string;
  apiFormat: string;
  status: "unknown" | "connected" | "error";
  models: string[];
  lastCheckedAt: string | null;
}

export interface Runtime {
  id: number;
  name: string;
  adapter: string;
  status: string;
  capabilities: string[];
}

export interface Execution {
  id: number;
  taskId: number;
  projectId: number;
  agentId: number;
  runtimeId: number;
  gatewayId: number;
  model: string;
  modelResolution: { gateway: string; model: string; source: "workspace" | "agent" | "task" };
  status: ExecutionStatus;
  startedAt: string | null;
  finishedAt: string | null;
  usage: {
    inputTokens: number;
    outputTokens: number;
    contextTokens: number;
    toolInvocations: number;
    retrievedMemoryCount: number;
    estimatedCost: number;
    durationMs: number;
  };
  failureReason: string | null;
  createdAt: string;
}

export type ExecutionEventType =
  | "task_received"
  | "agent_initialized"
  | "context_assembled"
  | "memory_retrieval"
  | "memory_stored"
  | "memory_rejected"
  | "llm_request"
  | "llm_response"
  | "tool_requested"
  | "tool_executed"
  | "tool_rejected"
  | "approval_requested"
  | "artifact_submitted"
  | "task_status_updated"
  | "execution_completed"
  | "execution_failed"
  | "error";

export interface ExecutionEvent {
  id: number;
  executionId: number;
  sequence: number;
  type: ExecutionEventType;
  payload: Record<string, unknown>;
  createdAt: string;
}

export interface Artifact {
  id: number;
  taskId: number;
  executionId: number;
  agentId: number;
  name: string;
  artifactType: string;
  contentType: string;
  sizeBytes: number;
  version: number;
  status: "submitted" | "reviewed" | "accepted" | "rejected";
  reviewedBy: number | null;
  reviewNote: string | null;
  createdAt: string;
}

export interface MemorySource {
  type: "execution" | "task" | "user" | "document" | "artifact";
  refId: number | null;
  actorType: "agent" | "user";
  actorId: number;
  summary: string;
}

export interface Memory {
  id: number;
  scope: MemoryScope;
  type: MemoryType;
  projectId: number | null;
  agentId: number | null;
  taskId: number | null;
  executionId: number | null;
  title: string;
  content: string;
  status: MemoryStatus;
  version: number;
  confidence: number;
  trust: "high" | "medium" | "low";
  source: MemorySource;
  createdBy: { type: "user" | "agent" | "system"; id: number };
  approvedBy: number | null;
  approvedAt: string | null;
  expiresAt: string | null;
  lastAccessedAt: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface MemoryVersion {
  id: number;
  memoryId: number;
  version: number;
  content: string;
  changedBy: { type: string; id: number };
  reason: string | null;
  createdAt: string;
}

export interface Approval {
  id: number;
  requestType: "task_review" | "memory_review";
  taskId: number | null;
  memoryId: number | null;
  executionId: number | null;
  status: ApprovalStatus;
  requestedBy: { type: "agent" | "user" | "system"; id: number };
  decisionUserId: number | null;
  decisionReason: string | null;
  decidedAt: string | null;
  expiresAt: string | null;
  createdAt: string;
}

export interface DashboardStats {
  totals: {
    projects: number;
    activeProjects: number;
    agents: number;
    activeAgents: number;
    memories: number;
    pendingMemoryReviews: number;
  };
  executions: {
    running: number;
    completedLast7Days: number;
    failedLast7Days: number;
  };
  tasks: {
    blocked: number;
    inReview: number;
    completedLast7Days: number;
  };
  pendingApprovals: number;
  recentActivity: {
    id: number;
    actor: { type: "user" | "agent" | "system"; id: number };
    action: string;
    resourceType: string;
    resourceId: number;
    at: string;
  }[];
}

export interface AuditLogEntry {
  id: number;
  actorType: string;
  actorId: number;
  resourceType: string;
  resourceId: number;
  action: string;
  before: unknown;
  after: unknown;
  executionId: number | null;
  at: string;
}

export interface MemoryDetail {
  memory: Memory;
  versions: MemoryVersion[];
  retrievals: { executionId: number; at: string; rank: number }[];
}

export interface MemorySourceDetail {
  source: MemorySource;
  execution?: Execution;
  task?: Task;
}

export interface MemoryHistoryEntry {
  event: string;
  at: string;
  actor: { type: string; id: number };
}

export interface GatewayTestResult {
  ok: boolean;
  message: string;
  latencyMs: number;
  models: string[];
}

export interface SearchResults {
  results: (Memory & { score: number; rank: number })[];
  total: number;
}

// Data source layer: every call tries the real API first (session cookies via
// the Vite /api proxy) and falls back to an in-memory mock of the exact
// §4.3 seed shapes when the backend is unreachable or the session is missing.
// The mock state is module-level, so the full execute -> review -> memory
// vertical slice works end to end with the backend down.
import { login as realLogin, loadAuthContext as realLoadAuthContext, logout as realLogout, AuthContext } from "./client";
import * as ep from "./endpoints";
import {
  SEED_AGENTS,
  SEED_APPROVALS,
  SEED_ARTIFACTS,
  SEED_AUDIT,
  SEED_DASHBOARD,
  SEED_EXECUTION_EVENTS,
  SEED_EXECUTIONS,
  SEED_GATEWAYS,
  SEED_MEMORIES,
  SEED_MEMORY_HISTORY,
  SEED_MEMORY_RETRIEVALS,
  SEED_MEMORY_VERSIONS,
  SEED_PROJECTS,
  SEED_RUNTIMES,
  SEED_TASKS,
  SEED_TASK_COMMENTS,
  SEED_USER,
  SEED_WORKSPACE,
} from "./mockData";
import type {
  Agent,
  Approval,
  Artifact,
  Execution,
  ExecutionEvent,
  Gateway,
  Memory,
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

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function ok<T>(data: T): { data: T } {
  return { data };
}

async function orMock<T>(real: () => Promise<T>, mock: () => T): Promise<T> {
  try {
    return await real();
  } catch {
    return mock();
  }
}

function clone<T>(v: T): T {
  return JSON.parse(JSON.stringify(v)) as T;
}

const nowIso = () => new Date().toISOString();

// ---------------------------------------------------------------------------
// mock state (mirrors §4.3 seed; mutated by mock mutations)
// ---------------------------------------------------------------------------

let seqExecution = 2; // e1 exists in seed
let seqMemory = 7; // m1..m6
let seqApproval = 3; // ap1, ap2
let seqArtifact = 2;
let seqComment = 2;
let seqAudit = 10;

interface MockAuditRow {
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

interface MockState {
  projects: Project[];
  tasks: Task[];
  comments: TaskComment[];
  agents: Agent[];
  executions: Execution[];
  events: ExecutionEvent[];
  artifacts: Artifact[];
  memories: Memory[];
  memoryVersions: MemoryVersion[];
  approvals: Approval[];
  audit: MockAuditRow[];
  gateways: Gateway[];
  runtimes: Runtime[];
  runningUntil: Record<number, number>; // executionId -> deadline ms
}

const S: MockState = {
  projects: clone(SEED_PROJECTS),
  tasks: clone(SEED_TASKS),
  comments: clone(SEED_TASK_COMMENTS),
  agents: clone(SEED_AGENTS),
  executions: clone(SEED_EXECUTIONS),
  events: clone(SEED_EXECUTION_EVENTS),
  artifacts: clone(SEED_ARTIFACTS),
  memories: clone(SEED_MEMORIES),
  memoryVersions: clone(SEED_MEMORY_VERSIONS),
  approvals: clone(SEED_APPROVALS),
  audit: clone(SEED_AUDIT),
  gateways: clone(SEED_GATEWAYS),
  runtimes: clone(SEED_RUNTIMES),
  runningUntil: {},
};

function mockAuth(): AuthContext {
  return { user: SEED_USER, workspace: SEED_WORKSPACE };
}

// event builder for a mock execution (mirrors §5.3 runner sequence)
function buildEvents(exec: Execution, task: Task, agentId: number, completed: boolean): ExecutionEvent[] {
  const base = Date.now();
  const t = (ms: number) => new Date(base + ms).toISOString();
  const all: ExecutionEvent[] = [
    { id: 0, executionId: exec.id, sequence: 1, type: "task_received", payload: { taskId: task.id, title: task.title }, createdAt: t(0) },
    { id: 0, executionId: exec.id, sequence: 2, type: "agent_initialized", payload: { runtime: 1, agent: agentId, status: "working" }, createdAt: t(100) },
    { id: 0, executionId: exec.id, sequence: 3, type: "memory_retrieval", payload: { query: task.title, retrieved: [{ memoryId: 2, score: 0.58 }, { memoryId: 4, score: 0.44 }], budgetTokens: 2000, truncated: false }, createdAt: t(400) },
    { id: 0, executionId: exec.id, sequence: 4, type: "context_assembled", payload: { sections: ["project", "task", "agent_instructions", "memory(2)"], promptTokens: 438 }, createdAt: t(600) },
    { id: 0, executionId: exec.id, sequence: 5, type: "llm_request", payload: { model: exec.model, inputTokens: 438 }, createdAt: t(800) },
    { id: 0, executionId: exec.id, sequence: 6, type: "llm_response", payload: { model: exec.model, outputTokens: 176, text: `[mock-llm] Task "${task.title}": analyzed project context and 2 retrieved memories. Steps performed: drafted deliverable. Deliverable: ${exec.id}.md` }, createdAt: t(1800) },
    { id: 0, executionId: exec.id, sequence: 7, type: "tool_requested", payload: { tool: "artifact.submit" }, createdAt: t(1900) },
    { id: 0, executionId: exec.id, sequence: 8, type: "tool_executed", payload: { tool: "artifact.submit", artifactId: seqArtifact }, createdAt: t(2100) },
    { id: 0, executionId: exec.id, sequence: 9, type: "artifact_submitted", payload: { artifactId: seqArtifact, name: `${exec.id}.md` }, createdAt: t(2200) },
    { id: 0, executionId: exec.id, sequence: 10, type: "memory_stored", payload: { memoryId: seqMemory, scope: "task", status: "active" }, createdAt: t(2300) },
    { id: 0, executionId: exec.id, sequence: 11, type: "approval_requested", payload: { approvalType: "memory_review", memoryId: seqMemory + 1 }, createdAt: t(2400) },
    { id: 0, executionId: exec.id, sequence: 12, type: "task_status_updated", payload: { from: "in_progress", to: "in_review" }, createdAt: t(2600) },
    { id: 0, executionId: exec.id, sequence: 13, type: "execution_completed", payload: { usage: { inputTokens: 438, outputTokens: 176 } }, createdAt: t(3200) },
  ];
  let idCounter = 1000 + exec.id * 100;
  for (const e of all) e.id = ++idCounter;
  return completed ? all : all.slice(0, 4);
}

function recordTaskExecution(exec: Execution): void {
  const task = S.tasks.find((t) => t.id === exec.taskId)!;
  const agent = S.agents.find((a) => a.id === exec.agentId)!;
  const agentName = agent.name;

  task.status = "in_review";
  task.hasRunningExecution = false;
  agent.status = "available";
  exec.status = "completed";
  exec.finishedAt = nowIso();
  exec.usage = { inputTokens: 438, outputTokens: 176, contextTokens: 1104, toolInvocations: 3, retrievedMemoryCount: 2, estimatedCost: 0, durationMs: 3200 };

  const working: Memory = {
    id: seqMemory++,
    scope: "task",
    type: "working",
    projectId: null,
    agentId: null,
    taskId: task.id,
    executionId: exec.id,
    title: `Working context for task ${task.id}: progress & decisions`,
    content: `Task "${task.title}" executed by ${agentName}. Decisions: scope kept to the task contract; artifact submitted as ${exec.id}.md.`,
    status: "active",
    version: 1,
    confidence: 0.6,
    trust: "medium",
    source: { type: "execution", refId: exec.id, actorType: "agent", actorId: agent.id, summary: `Working memory from execution ${exec.id}` },
    createdBy: { type: "agent", id: agent.id },
    approvedBy: null,
    approvedAt: null,
    expiresAt: new Date(Date.now() + 7 * 86400000).toISOString(),
    lastAccessedAt: nowIso(),
    createdAt: nowIso(),
    updatedAt: nowIso(),
  };
  const proposal: Memory = {
    id: seqMemory++,
    scope: "project",
    type: "semantic",
    projectId: task.projectId,
    agentId: null,
    taskId: null,
    executionId: exec.id,
    title: `Learnings from task: ${task.title}`,
    content: `Durable fact from "${task.title}": verified against project conventions; safe to reuse for related tasks.`,
    status: "pending_review",
    version: 1,
    confidence: 0.55,
    trust: "medium",
    source: { type: "execution", refId: exec.id, actorType: "agent", actorId: agent.id, summary: `Proposed by ${agentName} during execution ${exec.id}` },
    createdBy: { type: "agent", id: agent.id },
    approvedBy: null,
    approvedAt: null,
    expiresAt: null,
    lastAccessedAt: null,
    createdAt: nowIso(),
    updatedAt: nowIso(),
  };
  S.memories = [...S.memories, working, proposal];

  S.approvals = [
    ...S.approvals,
    {
      id: seqApproval++,
      requestType: "memory_review",
      taskId: null,
      memoryId: proposal.id,
      executionId: exec.id,
      status: "pending",
      requestedBy: { type: "agent", id: agent.id },
      decisionUserId: null,
      decisionReason: null,
      decidedAt: null,
      expiresAt: new Date(Date.now() + 10 * 86400000).toISOString(),
      createdAt: nowIso(),
    },
    {
      id: seqApproval++,
      requestType: "task_review",
      taskId: task.id,
      memoryId: null,
      executionId: exec.id,
      status: "pending",
      requestedBy: { type: "agent", id: agent.id },
      decisionUserId: null,
      decisionReason: null,
      decidedAt: null,
      expiresAt: new Date(Date.now() + 10 * 86400000).toISOString(),
      createdAt: nowIso(),
    },
  ];
  S.artifacts = [
    ...S.artifacts,
    {
      id: seqArtifact++,
      taskId: task.id,
      executionId: exec.id,
      agentId: agent.id,
      name: `${exec.id}.md`,
      artifactType: "document",
      contentType: "text/markdown",
      sizeBytes: 1240,
      version: 1,
      status: "submitted",
      reviewedBy: null,
      reviewNote: null,
      createdAt: nowIso(),
    },
  ];
  S.audit = [
    ...S.audit,
    { id: seqAudit++, actorType: "agent", actorId: agent.id, resourceType: "execution", resourceId: exec.id, action: "execution.completed", before: null, after: { status: "completed" }, executionId: exec.id, at: nowIso() },
    { id: seqAudit++, actorType: "agent", actorId: agent.id, resourceType: "memory", resourceId: proposal.id, action: "memory.proposed", before: null, after: { status: "pending_review" }, executionId: exec.id, at: nowIso() },
    { id: seqAudit++, actorType: "agent", actorId: agent.id, resourceType: "task", resourceId: task.id, action: "task.status_changed", before: { status: "in_progress" }, after: { status: "in_review" }, executionId: exec.id, at: nowIso() },
  ];
  S.tasks = S.tasks.map((x) => (x.id === task.id ? { ...task } : x));
  S.agents = S.agents.map((x) => (x.id === agent.id ? { ...agent } : x));
}

function mockExecuteTask(taskId: number): Execution {
  const task = S.tasks.find((t) => t.id === taskId);
  if (!task) throw new Error(`task ${taskId} not found in mock`);
  const running = S.executions.find((e) => e.taskId === taskId && (e.status === "queued" || e.status === "running"));
  if (running) return clone(running);

  const agent = task.assignee?.type === "agent" ? S.agents.find((a) => a.id === task.assignee!.id)! : S.agents[0];
  const override = task.modelOverride;
  const exec: Execution = {
    id: seqExecution++,
    taskId: task.id,
    projectId: task.projectId,
    agentId: agent.id,
    runtimeId: 1,
    gatewayId: override.enabled ? override.gatewayId! : agent.gatewayId,
    model: override.enabled ? override.model! : agent.defaultModel,
    modelResolution: {
      gateway: "mock-gateway",
      model: override.enabled ? override.model! : agent.defaultModel,
      source: override.enabled ? "task" : "agent",
    },
    status: "queued",
    startedAt: nowIso(),
    finishedAt: null,
    usage: { inputTokens: 0, outputTokens: 0, contextTokens: 0, toolInvocations: 0, retrievedMemoryCount: 2, estimatedCost: 0, durationMs: 0 },
    failureReason: null,
    createdAt: nowIso(),
  };
  S.executions = [...S.executions, exec];
  task.status = "in_progress";
  task.hasRunningExecution = true;
  agent.status = "working";
  S.tasks = S.tasks.map((t) => (t.id === task.id ? { ...task } : t));
  S.agents = S.agents.map((a) => (a.id === agent.id ? { ...agent } : a));
  S.audit = [...S.audit, { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "task", resourceId: task.id, action: "task.status_changed", before: { status: task.status }, after: { status: "in_progress" }, executionId: exec.id, at: nowIso() }];
  // simulated runner: queued -> running -> completed over ~6 s so the
  // 2 s polling loop observes the state transitions (contract §6 polling).
  S.runningUntil[exec.id] = Date.now() + 6000;
  return clone(exec);
}

/** Advances simulated mock execution state; called from every polling read. */
function tickMockExecutions(): void {
  for (const exec of S.executions) {
    const until = S.runningUntil[exec.id];
    if (until === undefined) continue;
    const task = S.tasks.find((t) => t.id === exec.taskId)!;
    if (exec.status === "queued" && Date.now() >= until - 4000) {
      exec.status = "running";
      S.events = [...S.events, ...buildEvents(exec, task, exec.agentId, false)];
      exec.usage = { ...exec.usage, inputTokens: 438, contextTokens: 1104 };
    }
    if (exec.status === "running" && Date.now() >= until) {
      S.events = [...S.events, ...buildEvents(exec, task, exec.agentId, true).slice(4)];
      recordTaskExecution(exec);
      delete S.runningUntil[exec.id];
    }
  }
}

function mockReviewTask(taskId: number, decision: "approve" | "reject", reason?: string): { data: Approval; task: Task } {
  const task = S.tasks.find((t) => t.id === taskId)!;
  const ap =
    S.approvals.find((a) => a.requestType === "task_review" && a.taskId === taskId && a.status === "pending") ??
    ({
      id: seqApproval++,
      requestType: "task_review",
      taskId,
      memoryId: null,
      executionId: S.executions.find((e) => e.taskId === taskId)?.id ?? null,
      status: "pending",
      requestedBy: { type: "agent", id: 1 },
      decisionUserId: null,
      decisionReason: null,
      decidedAt: null,
      expiresAt: null,
      createdAt: nowIso(),
    } as Approval);
  ap.status = decision === "approve" ? "approved" : "rejected";
  ap.decisionUserId = 1;
  ap.decisionReason = reason ?? null;
  ap.decidedAt = nowIso();
  S.approvals = [...S.approvals];
  task.status = decision === "approve" ? "done" : "in_progress";
  S.tasks = S.tasks.map((t) => (t.id === task.id ? { ...task } : t));
  if (decision === "reject" && reason) {
    S.comments = [...S.comments, { id: seqComment++, taskId, actor: { type: "user", id: 1 }, body: `Review rejected: ${reason}`, executionId: null, createdAt: nowIso() }];
  }
  S.audit = [...S.audit, { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "task", resourceId: task.id, action: "task.reviewed", before: null, after: { decision }, executionId: ap.executionId, at: nowIso() }];
  return { data: clone(ap), task: clone(task) };
}

function mockApprovalDecision(id: number, decision: "approve" | "reject", reason?: string): Approval {
  const ap = S.approvals.find((a) => a.id === id);
  if (!ap) throw new Error(`approval ${id} not found in mock`);
  ap.status = decision === "approve" ? "approved" : "rejected";
  ap.decisionUserId = 1;
  ap.decisionReason = reason ?? null;
  ap.decidedAt = nowIso();
  if (ap.requestType === "memory_review" && ap.memoryId) {
    const mem = S.memories.find((m) => m.id === ap.memoryId)!;
    const before = mem.status;
    mem.status = decision === "approve" ? "active" : "rejected";
    mem.approvedBy = decision === "approve" ? 1 : null;
    mem.approvedAt = nowIso();
    S.memories = [...S.memories];
    S.audit = [...S.audit, { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "memory", resourceId: mem.id, action: decision === "approve" ? "memory.approved" : "memory.rejected", before: { status: before }, after: { status: mem.status }, executionId: ap.executionId, at: nowIso() }];
  } else if (ap.requestType === "task_review" && ap.taskId) {
    const task = S.tasks.find((t) => t.id === ap.taskId)!;
    const before = task.status;
    task.status = decision === "approve" ? "done" : "in_progress";
    S.tasks = S.tasks.map((t) => (t.id === task.id ? { ...task } : t));
    if (decision === "reject" && reason) {
      S.comments = [...S.comments, { id: seqComment++, taskId: task.id, actor: { type: "user", id: 1 }, body: `Review rejected: ${reason}`, executionId: null, createdAt: nowIso() }];
    }
    S.audit = [...S.audit, { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "task", resourceId: task.id, action: "task.reviewed", before: { status: before }, after: { status: task.status }, executionId: ap.executionId, at: nowIso() }];
  }
  return clone(ap);
}

// ---------------------------------------------------------------------------
// auth
// ---------------------------------------------------------------------------

export async function getAuthContext(): Promise<AuthContext | null> {
  const real = await realLoadAuthContext();
  if (real) return real;
  return mockAuth();
}

export async function login(email: string, password: string): Promise<AuthContext> {
  return orMock(() => realLogin(email, password), () => {
    if (email !== SEED_USER.email || password !== "demo") {
      throw new Error("Invalid credentials (mock: use demo@overview.local / demo)");
    }
    return mockAuth();
  });
}

export async function logout(): Promise<void> {
  await realLogout().catch(() => undefined);
}

// ---------------------------------------------------------------------------
// reads
// ---------------------------------------------------------------------------

export function getDashboardStats() {
  tickMockExecutions();
  return orMock(ep.getDashboardStats, () => {
    const stats = clone(SEED_DASHBOARD);
    stats.pendingApprovals = S.approvals.filter((a) => a.status === "pending").length;
    stats.totals.memories = S.memories.length;
    stats.totals.pendingMemoryReviews = S.approvals.filter((a) => a.requestType === "memory_review" && a.status === "pending").length;
    stats.tasks.inReview = S.tasks.filter((t) => t.status === "in_review").length;
    stats.executions.running = S.executions.filter((e) => e.status === "queued" || e.status === "running").length;
    stats.recentActivity = S.audit.slice(-7).reverse().map((a) => ({
      id: a.id,
      actor: { type: a.actorType as "user" | "agent" | "system", id: a.actorId },
      action: a.action,
      resourceType: a.resourceType,
      resourceId: a.resourceId,
      at: a.at,
    }));
    return stats;
  });
}

export function getProjects(status?: string) {
  tickMockExecutions();
  return orMock(() => ep.getProjects(status), () => ok(S.projects.filter((p) => !status || p.status === status)));
}

export function getProject(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getProject(id), () => ok(S.projects.find((p) => p.id === id)!));
}

export function getProjectAgents(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getProjectAgents(id), () => ok(S.agents.filter((a) => (id === 1 ? a.id === 1 || a.id === 2 : a.id === 3))));
}

export function getProjectMemory(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getProjectMemory(id), () => ok(S.memories.filter((m) => m.projectId === id)));
}

export function getProjectTasks(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getProjectTasks(id), () => ok(S.tasks.filter((t) => t.projectId === id)));
}

export function getTask(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getTask(id), () => ok(S.tasks.find((t) => t.id === id)!));
}

export function getTaskComments(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getTaskComments(id), () => ok(S.comments.filter((c) => c.taskId === id)));
}

export function getTaskArtifacts(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getTaskArtifacts(id), () => ok(S.artifacts.filter((a) => a.taskId === id)));
}

export function getTaskMemory(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.getTaskMemory(id),
    () =>
      ok({
        working: S.memories.filter((m) => m.scope === "task" && m.taskId === id),
        proposed: S.memories.filter((m) => (m.executionId ? S.executions.some((e) => e.id === m.executionId && e.taskId === id) : false)),
      }),
  );
}

export function getTaskExecutions(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getTaskExecutions(id), () => ok(S.executions.filter((e) => e.taskId === id)));
}

export function getAgents(status?: string) {
  tickMockExecutions();
  return orMock(() => ep.getAgents(status), () => ok(S.agents.filter((a) => !status || a.status === status)));
}

export function getAgent(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getAgent(id), () => ok(S.agents.find((a) => a.id === id)!));
}

export function getAgentMemory(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getAgentMemory(id), () => ok(S.memories.filter((m) => m.agentId === id)));
}

export function getAgentExecutions(id: number, status?: string) {
  tickMockExecutions();
  return orMock(() => ep.getAgentExecutions(id, status), () => ok(S.executions.filter((e) => e.agentId === id && (!status || e.status === status))));
}

export function getGateways() {
  return orMock(ep.getGateways, () => ok(S.gateways));
}

export function getGatewayModels(id: number) {
  return orMock(() => ep.getGatewayModels(id), () => ({ data: { models: S.gateways.find((g) => g.id === id)?.models ?? [], discovered: false } }));
}

export function testGateway(id: number) {
  return orMock(() => ep.testGateway(id), () => ({ ok: true, message: "mock connection ok", latencyMs: 42, models: S.gateways.find((g) => g.id === id)?.models ?? [] }));
}

export function getRuntimes() {
  return orMock(ep.getRuntimes, () => ok(S.runtimes));
}

export function runRuntimeHealth(id: number) {
  return orMock(() => ep.runRuntimeHealth(id), () => ({ healthy: true, status: "healthy" }));
}

export function getExecutions(query?: { agentId?: number; projectId?: number; status?: string }) {
  tickMockExecutions();
  return orMock(
    () => ep.getExecutions(query),
    () =>
      ok(
        S.executions
          .filter((e) => (!query?.agentId || e.agentId === query.agentId) && (!query?.projectId || e.projectId === query.projectId) && (!query?.status || e.status === query.status))
          .sort((a, b) => b.id - a.id),
      ),
  );
}

export function getExecution(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getExecution(id), () => ok(S.executions.find((e) => e.id === id)!));
}

export function getExecutionEvents(id: number, afterSequence?: number) {
  tickMockExecutions();
  return orMock(
    () => ep.getExecutionEvents(id, afterSequence),
    () => ok(S.events.filter((e) => e.executionId === id && (!afterSequence || e.sequence > afterSequence)).sort((a, b) => a.sequence - b.sequence)),
  );
}

export function getExecutionArtifacts(id: number) {
  tickMockExecutions();
  return orMock(() => ep.getExecutionArtifacts(id), () => ok(S.artifacts.filter((a) => a.executionId === id)));
}

export function getExecutionMemories(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.getExecutionMemories(id),
    () => {
      const exec = S.executions.find((e) => e.id === id);
      const retrieved = exec
        ? S.memories
            .filter((m) => m.executionId === id || (id === 1 && (m.id === 2 || m.id === 4)))
            .map((m, i) => ({ ...m, rank: i + 1, score: i === 0 ? 0.62 : 0.41 }))
        : [];
      return ok({
        retrieved,
        proposed: S.memories.filter((m) => m.executionId === id && m.status === "pending_review"),
      });
    },
  );
}

export function cancelExecution(id: number) {
  return orMock(() => ep.cancelExecution(id), () => {
    const e = S.executions.find((x) => x.id === id)!;
    e.status = "cancelled";
    e.finishedAt = nowIso();
    return ok(e);
  });
}

export function getMemories(query?: { scope?: string; type?: string; status?: string; projectId?: number; agentId?: number; q?: string }) {
  tickMockExecutions();
  return orMock(
    () => ep.getMemories(query),
    () =>
      ok(
        S.memories.filter(
          (m) =>
            (!query?.scope || m.scope === query.scope) &&
            (!query?.type || m.type === query.type) &&
            (!query?.status || m.status === query.status) &&
            (!query?.projectId || m.projectId === query.projectId) &&
            (!query?.agentId || m.agentId === query.agentId),
        ),
      ),
  );
}

export function searchMemories(body: { query: string; limit?: number }): Promise<{ data: SearchResults }> {
  tickMockExecutions();
  return orMock(
    () => ep.searchMemories(body),
    () => {
      const tokens = body.query.toLowerCase().split(/\s+/).filter((t) => t.length > 2);
      const scored = S.memories
        .filter((m) => m.status === "active" || m.status === "pending_review")
        .map((m) => {
          const hay = `${m.title} ${m.content}`.toLowerCase();
          const hits = tokens.filter((t) => hay.includes(t)).length;
          return { m, score: hits / Math.max(1, tokens.length) };
        })
        .filter((x) => x.score > 0)
        .sort((a, b) => b.score - a.score)
        .slice(0, body.limit ?? 10);
      return ok({ results: scored.map((x, i) => ({ ...x.m, score: x.score, rank: i + 1 })), total: scored.length });
    },
  );
}

export function getMemoryDetail(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.getMemory(id),
    () => {
      const mem = S.memories.find((m) => m.id === id)!;
      const seedRetrievals = SEED_MEMORY_RETRIEVALS[id];
      const retrievals =
        seedRetrievals ?? (mem.executionId ? [{ executionId: mem.executionId, at: nowIso(), rank: 1 }] : []);
      return ok({
        memory: clone(mem),
        versions: clone(S.memoryVersions.filter((v) => v.memoryId === id)),
        retrievals,
      });
    },
  );
}

export function getMemoryVersions(id: number) {
  return orMock(() => ep.getMemoryVersions(id), () => ok(S.memoryVersions.filter((v) => v.memoryId === id)));
}

export function getMemorySources(id: number): Promise<{ data: MemorySourceDetail }> {
  const mem = S.memories.find((m) => m.id === id);
  const detail: MemorySourceDetail = {
    source: mem?.source ?? { type: "user", refId: null, actorType: "user", actorId: 1, summary: "unknown" },
  };
  if (mem?.source.type === "execution" && mem.source.refId) detail.execution = S.executions.find((e) => e.id === mem.source.refId);
  if (mem?.source.type === "task" && mem.source.refId) detail.task = S.tasks.find((t) => t.id === mem.source.refId);
  return orMock(() => ep.getMemorySources(id), () => ok(detail));
}

export function getMemoryHistory(id: number): Promise<{ data: MemoryHistoryEntry[] }> {
  const fallback = (): { data: MemoryHistoryEntry[] } => {
    const seeded = SEED_MEMORY_HISTORY[id];
    if (seeded) return ok(seeded);
    const mem = S.memories.find((m) => m.id === id);
    const entries: MemoryHistoryEntry[] = mem
      ? [{ event: "memory.created", at: mem.createdAt, actor: { type: "system", id: 1 } }]
      : [];
    const ap = S.approvals.find((a) => a.memoryId === id && a.decidedAt);
    if (ap) entries.push({ event: ap.status === "approved" ? "memory.approved" : "memory.rejected", at: ap.decidedAt!, actor: { type: "user", id: 1 } });
    return ok(entries);
  };
  return orMock(() => ep.getMemoryHistory(id), fallback);
}

export function approveMemory(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.approveMemory(id),
    () => {
      const mem = S.memories.find((m) => m.id === id)!;
      const before = mem.status;
      mem.status = "active";
      mem.approvedBy = 1;
      mem.approvedAt = nowIso();
      const ap = S.approvals.find((a) => a.requestType === "memory_review" && a.memoryId === id && a.status === "pending");
      if (ap) {
        ap.status = "approved";
        ap.decisionUserId = 1;
        ap.decidedAt = nowIso();
      }
      S.audit = [...S.audit, { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "memory", resourceId: id, action: "memory.approved", before: { status: before }, after: { status: "active" }, executionId: ap?.executionId ?? null, at: nowIso() }];
      return { data: clone(mem), approval: ap ? clone(ap) : undefined };
    },
  );
}

export function rejectMemory(id: number, reason?: string) {
  tickMockExecutions();
  return orMock(
    () => ep.rejectMemory(id, reason),
    () => {
      const mem = S.memories.find((m) => m.id === id)!;
      mem.status = "rejected";
      const ap = S.approvals.find((a) => a.requestType === "memory_review" && a.memoryId === id && a.status === "pending");
      if (ap) {
        ap.status = "rejected";
        ap.decisionUserId = 1;
        ap.decisionReason = reason ?? null;
        ap.decidedAt = nowIso();
      }
      return { data: clone(mem), approval: ap ? clone(ap) : undefined };
    },
  );
}

export function archiveMemory(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.archiveMemory(id),
    () => {
      const mem = S.memories.find((m) => m.id === id)!;
      mem.status = "archived";
      return ok(mem);
    },
  );
}

export function patchMemory(id: number, body: { content?: string; title?: string; confidence?: number; expiresAt?: string | null }) {
  tickMockExecutions();
  return orMock(
    () => ep.patchMemory(id, body),
    () => {
      const mem = S.memories.find((m) => m.id === id)!;
      if (body.content !== undefined) {
        S.memoryVersions = [...S.memoryVersions, { id: S.memoryVersions.length + 1, memoryId: id, version: mem.version + 1, content: body.content, changedBy: { type: "user", id: 1 }, reason: "edited", createdAt: nowIso() }];
        mem.content = body.content;
        mem.version += 1;
      }
      if (body.title !== undefined) mem.title = body.title;
      if (body.confidence !== undefined) mem.confidence = body.confidence;
      if (body.expiresAt !== undefined) mem.expiresAt = body.expiresAt;
      return ok(mem);
    },
  );
}

export function deleteMemory(id: number) {
  tickMockExecutions();
  return orMock(() => ep.deleteMemory(id), () => {
    S.memories = S.memories.filter((m) => m.id !== id);
    return undefined;
  });
}

export function supersedeMemory(id: number, content: string, reason?: string) {
  tickMockExecutions();
  return orMock(
    () => ep.supersedeMemory(id, content, reason),
    () => {
      const mem = S.memories.find((m) => m.id === id)!;
      mem.status = "superseded";
      const nextVersion: MemoryVersion = {
        id: S.memoryVersions.length + 1,
        memoryId: id,
        version: mem.version + 1,
        content,
        changedBy: { type: "user", id: 1 },
        reason: reason ?? "superseded",
        createdAt: nowIso(),
      };
      S.memoryVersions = [...S.memoryVersions, nextVersion];
      mem.version += 1;
      mem.content = content;
      mem.status = "active";
      mem.updatedAt = nowIso();
      S.memories = S.memories.map((m) => (m.id === id ? { ...mem } : m));
      S.audit = [
        ...S.audit,
        { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "memory", resourceId: id, action: "memory.superseded", before: { version: mem.version - 1 }, after: { version: mem.version }, executionId: null, at: nowIso() },
      ];
      return { data: clone(mem) };
    },
  );
}
export function createMemory(body: { scope: string; type: string; title: string; content: string; confidence?: number; trust?: string; projectId?: number | null; agentId?: number | null }) {
  tickMockExecutions();
  return orMock(
    () => ep.createMemory(body as Parameters<typeof ep.createMemory>[0]),
    () => {
      const mem: Memory = {
        id: seqMemory++,
        scope: body.scope as Memory["scope"],
        type: body.type as Memory["type"],
        projectId: body.projectId ?? (body.scope === "project" ? S.projects[0].id : null),
        agentId: body.agentId ?? (body.scope === "agent" ? S.agents[0].id : null),
        taskId: null,
        executionId: null,
        title: body.title,
        content: body.content,
        status: "active",
        version: 1,
        confidence: body.confidence ?? 0.5,
        trust: (body.trust as Memory["trust"]) ?? "medium",
        source: { type: "user", refId: null, actorType: "user", actorId: 1, summary: "Created manually" },
        createdBy: { type: "user", id: 1 },
        approvedBy: 1,
        approvedAt: nowIso(),
        expiresAt: null,
        lastAccessedAt: null,
        createdAt: nowIso(),
        updatedAt: nowIso(),
      };
      S.memories = [...S.memories, mem];
      return ok(mem);
    },
  );
}

export function getApprovals(query?: { status?: string; requestType?: string }) {
  tickMockExecutions();
  return orMock(
    () => ep.getApprovals(query),
    () =>
      ok(
        S.approvals
          .filter((a) => (!query?.status || a.status === query.status) && (!query?.requestType || a.requestType === query.requestType))
          .sort((a, b) => (a.status === "pending" ? 0 : 1) - (b.status === "pending" ? 0 : 1) || b.id - a.id),
      ),
  );
}

export function approveApproval(id: number) {
  tickMockExecutions();
  return orMock(() => ep.approveApproval(id), () => ok(mockApprovalDecision(id, "approve")));
}

export function rejectApproval(id: number, reason?: string) {
  tickMockExecutions();
  return orMock(() => ep.rejectApproval(id, reason), () => ok(mockApprovalDecision(id, "reject", reason)));
}

export function patchWorkspace(id: number, body: Record<string, unknown>): Promise<{ data: Workspace }> {
  return orMock(
    () => ep.patchWorkspace(id, body as never),
    () => {
      Object.assign(SEED_WORKSPACE, body);
      return ok(SEED_WORKSPACE);
    },
  );
}

export function createProject(body: { name: string; description?: string; objectives?: string[]; status?: string; priority?: string }): Promise<{ data: Project }> {
  return orMock(
    () => ep.createProject(body as never),
    () => {
      const p: Project = {
        id: S.projects.length + 1,
        workspaceId: 1,
        slug: body.name.toLowerCase().replace(/\s+/g, "-"),
        name: body.name,
        description: body.description ?? "",
        objectives: body.objectives ?? [],
        status: (body.status as Project["status"]) ?? "planning",
        priority: (body.priority as Project["priority"]) ?? "medium",
        startDate: null,
        dueDate: null,
        ownerUserId: 1,
        agentCount: 0,
        taskCount: 0,
      };
      S.projects = [...S.projects, p];
      return ok(p);
    },
  );
}

export function patchTask(id: number, body: { status?: string }) {
  tickMockExecutions();
  return orMock(
    () => ep.patchTask(id, body),
    () => {
      const t = S.tasks.find((x) => x.id === id)!;
      const before = t.status;
      t.status = body.status as Task["status"];
      S.tasks = S.tasks.map((x) => (x.id === id ? { ...t } : x));
      S.audit = [...S.audit, { id: seqAudit++, actorType: "user", actorId: 1, resourceType: "task", resourceId: id, action: "task.status_changed", before: { status: before }, after: { status: body.status }, executionId: null, at: nowIso() }];
      return ok(t);
    },
  );
}

export function createTaskComment(id: number, body: { body: string }) {
  tickMockExecutions();
  return orMock(
    () => ep.createTaskComment(id, body),
    () => {
      const c: TaskComment = { id: seqComment++, taskId: id, actor: { type: "user", id: 1 }, body: body.body, executionId: null, createdAt: nowIso() };
      S.comments = [...S.comments, c];
      return ok(c);
    },
  );
}

export function createTask(projectId: number, body: { title: string; description?: string; type?: string; priority?: string; assignee?: { type: "user" | "agent"; id: number } }): Promise<{ data: Task }> {
  tickMockExecutions();
  return orMock(
    () => ep.createTask(projectId, body as never),
    () => {
      const t: Task = {
        id: S.tasks.length + 1,
        projectId,
        parentTaskId: null,
        title: body.title,
        description: body.description ?? "",
        type: (body.type as Task["type"]) ?? "feature",
        customType: null,
        status: "todo",
        priority: (body.priority as Task["priority"]) ?? "medium",
        assignee: body.assignee ?? null,
        createdBy: { type: "user", id: 1 },
        dueDate: null,
        labels: [],
        modelOverride: { enabled: false, gatewayId: null, model: null, resolvedSource: "agent" },
        hasRunningExecution: false,
        createdAt: nowIso(),
        updatedAt: nowIso(),
      };
      S.tasks = [...S.tasks, t];
      return ok(t);
    },
  );
}

export function patchTaskModelOverride(id: number, body: { enabled: boolean; gatewayId?: number | null; model?: string | null }): Promise<{ data: TaskModelOverride }> {
  tickMockExecutions();
  return orMock(
    () => ep.patchTaskModelOverride(id, body),
    () => {
      const t = S.tasks.find((x) => x.id === id)!;
      t.modelOverride = {
        enabled: body.enabled,
        gatewayId: body.gatewayId ?? null,
        model: body.model ?? null,
        resolvedSource: body.enabled ? "task" : "agent",
      };
      S.tasks = S.tasks.map((x) => (x.id === id ? { ...t } : x));
      return ok(t.modelOverride);
    },
  );
}

export function reviewTask(id: number, decision: "approve" | "reject", reason?: string) {
  tickMockExecutions();
  return orMock(() => ep.reviewTask(id, decision, reason), () => mockReviewTask(id, decision, reason));
}

export function executeTask(id: number, memorySearchQuery?: string): Promise<{ data: Execution; message: string }> {
  tickMockExecutions();
  return orMock(
    () => ep.executeTask(id, memorySearchQuery),
    () => ({ data: mockExecuteTask(id), message: "execution started (mock)" }),
  );
}

export function patchAgent(id: number, body: Record<string, unknown>) {
  tickMockExecutions();
  return orMock(
    () => ep.patchAgent(id, body),
    () => {
      const a = S.agents.find((x) => x.id === id)!;
      Object.assign(a, body);
      S.agents = S.agents.map((x) => (x.id === id ? { ...a } : x));
      return ok(a);
    },
  );
}

export function activateAgent(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.activateAgent(id),
    () => {
      S.agents = S.agents.map((a) => (a.id === id ? { ...a, status: "available" as const } : a));
      return ok(S.agents.find((a) => a.id === id)!);
    },
  );
}

export function deactivateAgent(id: number) {
  tickMockExecutions();
  return orMock(
    () => ep.deactivateAgent(id),
    () => {
      S.agents = S.agents.map((a) => (a.id === id ? { ...a, status: "offline" as const } : a));
      return ok(S.agents.find((a) => a.id === id)!);
    },
  );
}

export function patchAgentMemoryConfig(id: number, body: { enabled?: boolean; allowedScopes?: Agent["memory"]["allowedScopes"]; retrieval?: Agent["memory"]["retrieval"] }) {
  tickMockExecutions();
  return orMock(
    () => ep.patchAgentMemoryConfig(id, body),
    () => {
      const a = S.agents.find((x) => x.id === id)!;
      a.memory = {
        enabled: body.enabled ?? a.memory.enabled,
        allowedScopes: body.allowedScopes ?? a.memory.allowedScopes,
        retrieval: body.retrieval ?? a.memory.retrieval,
      };
      S.agents = S.agents.map((x) => (x.id === id ? { ...a } : x));
      return ok(a.memory);
    },
  );
}

export function createGateway(body: { name: string; type: string; baseUrl: string; apiKeyRef: string; apiFormat?: string; models?: string[] }) {
  return orMock(
    () => ep.createGateway(body),
    () => {
      const g: Gateway = {
        id: S.gateways.length + 1,
        name: body.name,
        type: body.type,
        baseUrl: body.baseUrl,
        apiKeyRef: body.apiKeyRef,
        apiFormat: body.apiFormat ?? "chat",
        status: "unknown",
        models: body.models ?? [],
        lastCheckedAt: null,
      };
      S.gateways = [...S.gateways, g];
      return ok(g);
    },
  );
}

export function getWorkspaces() {
  return orMock(ep.getWorkspaces, () => ok([SEED_WORKSPACE]));
}

export { SEED_WORKSPACE };

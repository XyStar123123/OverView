import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { StatusBadge } from "../components/StatusBadge";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import {
  getAgent,
  getAgentMemory,
  getAgentExecutions,
  activateAgent,
  deactivateAgent,
  patchAgentMemoryConfig,
} from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { fmtDate } from "../lib/format";
import type { Agent, Execution, Memory, MemoryScope } from "../api/types";
import { Card, Meta, PageHead, StateView, Toggle } from "./ui";
import css from "./Page.module.css";

const SCOPES: MemoryScope[] = ["workspace", "project", "agent", "task"];

export default function AgentDetailPage() {
  const { id } = useParams();
  const agentId = Number(id);
  const agent = useApi(() => getAgent(agentId), [agentId]);
  const memories = useApi(() => getAgentMemory(agentId), [agentId, agent.data]);
  const executions = useApi(() => getAgentExecutions(agentId), [agentId, agent.data]);

  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);
  const [mem, setMem] = useState<Agent["memory"] | null>(agent.data?.data.memory ?? null);
  // re-sync local edit state when the server copy changes (after saves)
  const serverMem = agent.data?.data.memory;
  if (serverMem && JSON.stringify(mem) !== JSON.stringify(serverMem) && busy === null) {
    setMem(serverMem);
  }

  const memColumns: Column<Memory>[] = [
    { key: "title", header: "Memory", render: (m) => m.title },
    { key: "scope", header: "Scope", render: (m) => m.scope },
    { key: "status", header: "Status", render: (m) => <StatusBadge state={m.status} /> },
    { key: "confidence", header: "Confidence", mono: true, width: "100px", render: (m) => m.confidence.toFixed(2) },
    { key: "updated", header: "Updated", render: (m) => fmtDate(m.updatedAt) },
  ];

  const execColumns: Column<Execution>[] = [
    { key: "id", header: "#", mono: true, width: "56px", render: (e) => e.id },
    { key: "status", header: "Status", render: (e) => <StatusBadge state={e.status} /> },
    { key: "model", header: "Model", mono: true, render: (e) => e.model },
    { key: "task", header: "Task", render: (e) => <Link to={`/tasks/${e.taskId}`}>{e.taskId}</Link> },
    { key: "started", header: "Started", render: (e) => fmtDate(e.startedAt) },
    { key: "link", header: "", render: (e) => <Link to={`/executions/${e.id}`}>Details</Link> },
  ];

  const toggleStatus = async (action: "activate" | "deactivate") => {
    setBusy(action);
    setMsg(null);
    try {
      await (action === "activate" ? activateAgent(agentId) : deactivateAgent(agentId));
      agent.refresh();
    } catch (e) {
      setMsg({ kind: "err", text: e instanceof Error ? e.message : String(e) });
    } finally {
      setBusy(null);
    }
  };

  const saveMemoryConfig = async () => {
    if (!mem) return;
    setBusy("memory");
    setMsg(null);
    try {
      await patchAgentMemoryConfig(agentId, mem);
      agent.refresh();
      setMsg({ kind: "ok", text: "Memory config saved." });
    } catch (e) {
      setMsg({ kind: "err", text: e instanceof Error ? e.message : String(e) });
    } finally {
      setBusy(null);
    }
  };

  const setMemPatch = (p: Partial<Agent["memory"]>) => setMem((m) => (m ? { ...m, ...p } : m));
  const setRetrievalPatch = (p: Partial<Agent["memory"]["retrieval"]>) =>
    setMem((m) => (m ? { ...m, retrieval: { ...m.retrieval, ...p } } : m));
  const toggleScope = (s: MemoryScope) =>
    setMem((m) => {
      if (!m) return m;
      const has = m.allowedScopes.includes(s);
      return { ...m, allowedScopes: has ? m.allowedScopes.filter((x) => x !== s) : [...m.allowedScopes, s] };
    });

  return (
    <div>
      <StateView state={agent} empty={<EmptyState title="Agent not found" />}>
        {(d) => {
          const ag = d.data;
          return (
            <>
              <PageHead
                title={ag.name}
                sub={
                  <span>
                    <StatusBadge state={ag.status} /> {ag.description}
                  </span>
                }
                right={
                  <div className="ov-row">
                    {ag.status !== "offline" ? (
                      <button className="btn" onClick={() => toggleStatus("deactivate")} disabled={busy !== null}>
                        {busy === "deactivate" ? "Deactivating…" : "Deactivate"}
                      </button>
                    ) : (
                      <button className="btn btn-primary" onClick={() => toggleStatus("activate")} disabled={busy !== null}>
                        {busy === "activate" ? "Activating…" : "Activate"}
                      </button>
                    )}
                    <Link className="btn" to="/agents">All agents</Link>
                  </div>
                }
              />
              <Meta
                items={[
                  { label: "Role", value: ag.role },
                  { label: "Version", value: `v${ag.version}` },
                  { label: "Default model", value: <span className="ov-mono">{ag.defaultModel}</span> },
                  { label: "Gateway", value: `#${ag.gatewayId}` },
                  { label: "Runtime", value: `#${ag.runtimeId}` },
                  { label: "Concurrency limit", value: ag.concurrencyLimit },
                ]}
              />
              {msg && <div className={msg.kind === "err" ? "ov-error" : "ov-success"} role="alert" style={{ marginBottom: "var(--sp-3)" }}>{msg.text}</div>}
              <div className={css.twoCol}>
                <Card
                  title="Memory configuration"
                  right={
                    <button className="btn btn-primary" onClick={saveMemoryConfig} disabled={busy !== null || !mem}>
                      {busy === "memory" ? "Saving…" : "Save"}
                    </button>
                  }
                >
                  {mem ? (
                    <div className={css.form}>
                      <Toggle checked={mem.enabled} onChange={(v) => setMemPatch({ enabled: v })} label="Memory enabled" />
                      <div style={{ margin: "var(--sp-2) 0" }}>
                        <div className="ov-muted" style={{ marginBottom: "var(--sp-1)", fontSize: 12 }}>Allowed scopes</div>
                        <div className="ov-row">
                          {SCOPES.map((s) => (
                            <label key={s} className="ov-row" style={{ fontSize: 13 }}>
                              <input type="checkbox" checked={mem.allowedScopes.includes(s)} onChange={() => toggleScope(s)} />
                              {s}
                            </label>
                          ))}
                        </div>
                      </div>
                      <Toggle
                        checked={mem.retrieval.enabled}
                        onChange={(v) => setRetrievalPatch({ enabled: v })}
                        label="Retrieval enabled"
                      />
                      <div className="ov-row" style={{ marginTop: "var(--sp-2)" }}>
                        <label className="ov-row">
                          <span style={{ fontSize: 12 }}>Max memories</span>
                          <input type="number" min={1} value={mem.retrieval.maxMemories} onChange={(e) => setRetrievalPatch({ maxMemories: Number(e.target.value) })} />
                        </label>
                        <label className="ov-row">
                          <span style={{ fontSize: 12 }}>Context budget (tokens)</span>
                          <input type="number" min={0} value={mem.retrieval.contextBudgetTokens} onChange={(e) => setRetrievalPatch({ contextBudgetTokens: Number(e.target.value) })} />
                        </label>
                      </div>
                    </div>
                  ) : (
                    <EmptyState title="Memory config unavailable" />
                  )}
                </Card>

                <Card title={`Memory (${memories.data?.data.length ?? 0})`}>
                  <StateView state={memories} empty={<EmptyState title="No agent memory" />}>
                    {(m) =>
                      m.data.length === 0 ? (
                        <EmptyState title="No memories owned by this agent" />
                      ) : (
                        <DataTable columns={memColumns} rows={m.data} rowKey={(x) => x.id} />
                      )
                    }
                  </StateView>
                </Card>
              </div>

              <Card title={`Executions (${executions.data?.data.length ?? 0})`} style={{ marginTop: "var(--sp-4)" }}>
                <StateView state={executions} empty={<EmptyState title="No executions" />}>
                  {(e) =>
                    e.data.length === 0 ? (
                      <EmptyState title="This agent has no executions yet" />
                    ) : (
                      <DataTable columns={execColumns} rows={e.data} rowKey={(x) => x.id} />
                    )
                  }
                </StateView>
              </Card>
            </>
          );
        }}
      </StateView>
    </div>
  );
}

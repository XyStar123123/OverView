import { useState } from "react";
import {
  getWorkspaces,
  patchWorkspace,
  getGateways,
  getGatewayModels,
  testGateway,
  createGateway,
  getRuntimes,
  runRuntimeHealth,
} from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { fmtDate } from "../lib/format";
import type { Gateway, GatewayTestResult, Runtime, Workspace } from "../api/types";
import { StatusBadge } from "../components/StatusBadge";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import { Card, PageHead, StateView, Toggle } from "./ui";
import css from "./Page.module.css";

export default function SettingsPage() {
  const workspaces = useApi(getWorkspaces);
  const gateways = useApi(getGateways);
  const runtimes = useApi(getRuntimes);
  const ws = workspaces.data?.data[0];

  const [wsDraft, setWsDraft] = useState<Workspace | null>(null);
  const [wsMsg, setWsMsg] = useState<string | null>(null);
  const [wsBusy, setWsBusy] = useState(false);
  const serverWs = workspaces.data?.data[0];
  if (serverWs && !wsDraft) setWsDraft(serverWs);
  const draft = wsDraft ?? ws;

  // gateway test results
  const [testResults, setTestResults] = useState<Record<number, GatewayTestResult | "busy">>({});
  // runtime health
  const [health, setHealth] = useState<Record<number, string | "busy">>({});
  // create gateway form
  const [gName, setGName] = useState("");
  const [gType, setGType] = useState("openai");
  const [gBaseUrl, setGBaseUrl] = useState("");
  const [gApiKey, setGApiKey] = useState("");
  const [gMsg, setGMsg] = useState<string | null>(null);
  const [gBusy, setGBusy] = useState(false);
  // models per gateway
  const [modelFor, setModelFor] = useState<number | null>(null);
  const models = useApi(async () => (modelFor ? getGatewayModels(modelFor) : Promise.resolve(null)), [modelFor]);

  const saveWorkspace = async () => {
    if (!draft) return;
    setWsBusy(true);
    setWsMsg(null);
    try {
      await patchWorkspace(draft.id, {
        name: draft.name,
        description: draft.description,
        defaultGatewayId: draft.defaultGatewayId,
        defaultModel: draft.defaultModel,
        defaultRuntimeId: draft.defaultRuntimeId,
        memoryPolicy: draft.memoryPolicy,
      });
      workspaces.refresh();
      setWsMsg("Workspace settings saved.");
      setWsDraft(null);
    } catch (e) {
      setWsMsg(e instanceof Error ? e.message : String(e));
    } finally {
      setWsBusy(false);
    }
  };

  const runGatewayTest = async (id: number) => {
    setTestResults((r) => ({ ...r, [id]: "busy" }));
    try {
      const res = await testGateway(id);
      setTestResults((r) => ({ ...r, [id]: res }));
    } catch (e) {
      setTestResults((r) => ({ ...r, [id]: { ok: false, message: e instanceof Error ? e.message : String(e), latencyMs: 0, models: [] } }));
    }
  };

  const runHealth = async (id: number) => {
    setHealth((h) => ({ ...h, [id]: "busy" }));
    try {
      const res = await runRuntimeHealth(id);
      setHealth((h) => ({ ...h, [id]: res.healthy ? res.status : "unhealthy" }));
    } catch (e) {
      setHealth((h) => ({ ...h, [id]: e instanceof Error ? e.message : "unreachable" }));
    }
  };

  const createGw = async () => {
    if (!gName.trim() || !gBaseUrl.trim()) return;
    setGBusy(true);
    setGMsg(null);
    try {
      await createGateway({ name: gName.trim(), type: gType, baseUrl: gBaseUrl.trim(), apiKeyRef: gApiKey.trim() || "env:LLM_API_KEY", models: [] });
      gateways.refresh();
      setGName("");
      setGBaseUrl("");
      setGApiKey("");
      setGMsg("Gateway added.");
    } catch (e) {
      setGMsg(e instanceof Error ? e.message : String(e));
    } finally {
      setGBusy(false);
    }
  };

  const gatewayColumns: Column<Gateway>[] = [
    { key: "name", header: "Gateway", render: (g) => g.name },
    { key: "type", header: "Type", render: (g) => g.type },
    { key: "url", header: "Base URL", mono: true, render: (g) => g.baseUrl },
    { key: "status", header: "Status", render: (g) => {
        const r = testResults[g.id];
        return <span className="ov-row">
          <StatusBadge state={g.status} />
          {r === "busy" && <span className="ov-muted">testing…</span>}
          {r && r !== "busy" && !r.ok && <span className="ov-muted">{r.message}</span>}
          {r && r !== "busy" && r.ok && <span className="ov-success">{r.latencyMs} ms</span>}
        </span>;
      } },
    { key: "checked", header: "Last checked", render: (g) => fmtDate(g.lastCheckedAt) },
    { key: "actions", header: "", render: (g) => (
        <div className="ov-row">
          <button className="btn btn-sm" onClick={() => void runGatewayTest(g.id)}>Test</button>
          <button className="btn btn-sm" onClick={() => setModelFor(modelFor === g.id ? null : g.id)}>
            {modelFor === g.id ? "Hide models" : "Models"}
          </button>
        </div>
      ) },
  ];

  const runtimeColumns: Column<Runtime>[] = [
    { key: "name", header: "Runtime", render: (r) => r.name },
    { key: "adapter", header: "Adapter", mono: true, render: (r) => r.adapter },
    { key: "status", header: "Status", render: (r) => {
        const h = health[r.id];
        return <span className="ov-row">
          <StatusBadge state={r.status} />
          {h === "busy" && <span className="ov-muted">checking…</span>}
          {h && h !== "busy" && <span className={h === "healthy" ? "ov-success" : "ov-error"}>{h}</span>}
        </span>;
      } },
    { key: "caps", header: "Capabilities", render: (r) => r.capabilities.join(", ") },
    { key: "actions", header: "", render: (r) => (
        <button className="btn btn-sm" onClick={() => void runHealth(r.id)}>Health check</button>
      ) },
  ];

  return (
    <div>
      <PageHead title="Settings" sub="Workspace defaults, LLM gateways and runtime health." />

      <Card title="Workspace">
        <StateView state={workspaces} empty={<EmptyState title="No workspace" />}>
          {() => {
            if (!draft) return <EmptyState title="No workspace data" />;
            return (
              <div className={css.form}>
                {wsMsg && <div className={wsMsg === "Workspace settings saved." ? "ov-success" : "ov-error"} role="alert" style={{ marginBottom: "var(--sp-2)", fontSize: 13 }}>{wsMsg}</div>}
                <div className={css.row}>
                  <label className={css.field}>
                    <span style={{ fontSize: 12 }}>Name</span>
                    <input value={draft.name} onChange={(e) => setWsDraft({ ...draft, name: e.target.value })} />
                  </label>
                  <label className={css.field}>
                    <span style={{ fontSize: 12 }}>Description</span>
                    <input value={draft.description ?? ""} onChange={(e) => setWsDraft({ ...draft, description: e.target.value || null })} />
                  </label>
                </div>
                <div className={css.row}>
                  <label className={css.field}>
                    <span style={{ fontSize: 12 }}>Default gateway</span>
                    <select value={draft.defaultGatewayId ?? ""} onChange={(e) => setWsDraft({ ...draft, defaultGatewayId: e.target.value ? Number(e.target.value) : null })}>
                      <option value="">None</option>
                      {(gateways.data?.data ?? []).map((g) => (
                        <option key={g.id} value={g.id}>{g.name} ({g.type})</option>
                      ))}
                    </select>
                  </label>
                  <label className={css.field}>
                    <span style={{ fontSize: 12 }}>Default model</span>
                    <input value={draft.defaultModel ?? ""} onChange={(e) => setWsDraft({ ...draft, defaultModel: e.target.value || null })} placeholder="gpt-4o-mini" />
                  </label>
                  <label className={css.field}>
                    <span style={{ fontSize: 12 }}>Default runtime</span>
                    <select value={draft.defaultRuntimeId ?? ""} onChange={(e) => setWsDraft({ ...draft, defaultRuntimeId: e.target.value ? Number(e.target.value) : null })}>
                      <option value="">None</option>
                      {(runtimes.data?.data ?? []).map((r) => (
                        <option key={r.id} value={r.id}>{r.name}</option>
                      ))}
                    </select>
                  </label>
                </div>
                <Toggle
                  checked={draft.memoryPolicy.requireReviewForSharedScopes}
                  onChange={(v) => setWsDraft({ ...draft, memoryPolicy: { ...draft.memoryPolicy, requireReviewForSharedScopes: v } })}
                  label="Require review for shared-scope memory writes"
                />
                <div className={css.actions}>
                  <button className="btn btn-primary" onClick={saveWorkspace} disabled={wsBusy}>
                    {wsBusy ? "Saving…" : "Save"}
                  </button>
                </div>
              </div>
            );
          }}
        </StateView>
      </Card>

      <Card title={`Gateways (${gateways.data?.data.length ?? 0})`} style={{ marginTop: "var(--sp-4)" }}>
        <StateView state={gateways} empty={<EmptyState title="No gateways" />}>
          {(g) =>
            g.data.length === 0 ? (
              <EmptyState title="No LLM gateways configured" />
            ) : (
              <DataTable columns={gatewayColumns} rows={g.data} rowKey={(x) => x.id} />
            )
          }
        </StateView>
        {modelFor !== null && (
          <div style={{ marginTop: "var(--sp-3)" }}>
            <div className="ov-muted" style={{ fontSize: 12, marginBottom: "var(--sp-1)" }}>Models for gateway #{modelFor}</div>
            {models.data ? (
              models.data.data.models.length === 0 ? (
                <EmptyState title="No models listed" hint={models.data.data.discovered ? "" : "(discovery disabled)"} />
              ) : (
                <div className="ov-row">
                  {models.data.data.models.map((m) => (
                    <span key={m} className="ov-mono" style={{ fontSize: 12 }}>{m}</span>
                  ))}
                </div>
              )
            ) : models.error ? (
              <div className="ov-error" style={{ fontSize: 13 }}>{models.error}</div>
            ) : (
              <div className="ov-muted">Loading models…</div>
            )}
          </div>
        )}
        <div style={{ marginTop: "var(--sp-3)" }}>
          <div className="ov-sectionTitle" style={{ marginBottom: "var(--sp-2)" }}>Add gateway</div>
          <div className={css.form}>
            {gMsg && <div className={gMsg === "Gateway added." ? "ov-success" : "ov-error"} role="alert" style={{ marginBottom: "var(--sp-2)", fontSize: 13 }}>{gMsg}</div>}
            <div className={css.row}>
              <label className={css.field}>
                <span style={{ fontSize: 12 }}>Name</span>
                <input value={gName} onChange={(e) => setGName(e.target.value)} placeholder="local-llm" />
              </label>
              <label className={css.field}>
                <span style={{ fontSize: 12 }}>Type</span>
                <select value={gType} onChange={(e) => setGType(e.target.value)}>
                  <option value="openai">openai</option>
                  <option value="anthropic">anthropic</option>
                  <option value="ollama">ollama</option>
                  <option value="vllm">vllm</option>
                  <option value="custom">custom</option>
                </select>
              </label>
            </div>
            <div className={css.row}>
              <label className={css.field}>
                <span style={{ fontSize: 12 }}>Base URL</span>
                <input value={gBaseUrl} onChange={(e) => setGBaseUrl(e.target.value)} placeholder="http://localhost:11434" />
              </label>
              <label className={css.field}>
                <span style={{ fontSize: 12 }}>API key ref (secret reference, not the key)</span>
                <input value={gApiKey} onChange={(e) => setGApiKey(e.target.value)} placeholder="env:LLM_API_KEY" />
              </label>
            </div>
            <div className={css.actions}>
              <button className="btn btn-primary" onClick={createGw} disabled={gBusy || !gName.trim() || !gBaseUrl.trim()}>
                {gBusy ? "Adding…" : "Add gateway"}
              </button>
            </div>
          </div>
        </div>
      </Card>

      <Card title={`Runtimes (${runtimes.data?.data.length ?? 0})`} style={{ marginTop: "var(--sp-4)" }}>
        <StateView state={runtimes} empty={<EmptyState title="No runtimes" />}>
          {(r) =>
            r.data.length === 0 ? (
              <EmptyState title="No agent runtimes registered" />
            ) : (
              <DataTable columns={runtimeColumns} rows={r.data} rowKey={(x) => x.id} />
            )
          }
        </StateView>
      </Card>
    </div>
  );
}

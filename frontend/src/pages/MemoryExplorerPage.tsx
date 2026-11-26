import { useState } from "react";
import { Link } from "react-router-dom";
import { StatusBadge } from "../components/StatusBadge";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import {
  getMemories,
  getMemoryDetail,
  getMemoryVersions,
  approveMemory,
  rejectMemory,
  archiveMemory,
  searchMemories,
} from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { fmtDate, timeAgo } from "../lib/format";
import type { Memory, MemoryScope, MemoryStatus, MemoryVersion } from "../api/types";
import { Card, PageHead, StateView } from "./ui";
import css from "./Page.module.css";

const SCOPES: MemoryScope[] = ["workspace", "project", "agent", "task"];
const STATUSES: MemoryStatus[] = ["candidate", "pending_review", "active", "superseded", "archived", "rejected", "expired"];

export default function MemoryExplorerPage() {
  const [scope, setScope] = useState("");
  const [status, setStatus] = useState("");
  const [q, setQ] = useState("");
  const [selected, setSelected] = useState<Memory | null>(null);

  const memories = useApi(() => getMemories({ scope: scope || undefined, status: status || undefined }), [scope, status]);
  const search = useApi(async () => (q.trim() ? searchMemories({ query: q.trim(), limit: 10 }) : Promise.resolve({ data: { results: [], total: 0 } })), [q]);
  const detail = useApi(() => (selected ? getMemoryDetail(selected.id) : Promise.resolve(null)), [selected]);
  const versions = useApi(() => (selected ? getMemoryVersions(selected.id) : Promise.resolve({ data: [] as MemoryVersion[] })), [selected]);

  const [msg, setMsg] = useState<string | null>(null);

  const rows: Memory[] = q.trim() ? (search.data?.data.results ?? []) : (memories.data?.data ?? []);

  const columns: Column<Memory>[] = [
    { key: "id", header: "#", mono: true, width: "56px", render: (m) => m.id },
    { key: "title", header: "Memory", render: (m) => (
        <div className="ov-cell">
          <div>{m.title}</div>
          <div className="ov-muted">{m.content.slice(0, 80)}{m.content.length > 80 ? "…" : ""}</div>
        </div>
      ) },
    { key: "scope", header: "Scope", render: (m) => m.scope },
    { key: "type", header: "Type", render: (m) => m.type },
    { key: "status", header: "Status", render: (m) => <StatusBadge state={m.status} /> },
    { key: "confidence", header: "Conf.", mono: true, width: "64px", render: (m) => m.confidence.toFixed(2) },
    { key: "created", header: "Created", render: (m) => timeAgo(m.createdAt), width: "110px" },
    { key: "actions", header: "", width: "200px", render: (m) => (
        <div className="ov-row">
          <button className="btn btn-sm" onClick={(e) => { e.stopPropagation(); setSelected(m); }}>Details</button>
          {m.status === "pending_review" && (
            <>
              <button className="btn btn-sm" onClick={(e) => { e.stopPropagation(); void act("approve", m); }}>Approve</button>
              <button className="btn btn-sm btn-danger" onClick={(e) => { e.stopPropagation(); void act("reject", m); }}>Reject</button>
            </>
          )}
          {m.status !== "archived" && (
            <button className="btn btn-sm" onClick={(e) => { e.stopPropagation(); void act("archive", m); }}>Archive</button>
          )}
        </div>
      ) },
  ];

  const act = async (action: "approve" | "reject" | "archive", m: Memory) => {
    setMsg(null);
    try {
      if (action === "approve") await approveMemory(m.id);
      else if (action === "reject") await rejectMemory(m.id);
      else await archiveMemory(m.id);
      memories.refresh();
      if (selected?.id === m.id) setSelected({ ...m });
    } catch (e) {
      setMsg(e instanceof Error ? e.message : String(e));
    }
  };

  return (
    <div>
      <PageHead
        title="Memory"
        sub="Working, episodic, semantic and procedural memories across all scopes."
        right={
          <input className="ov-input" placeholder="Search memories…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search memories" />
        }
      />
      <div className={css.filters}>
        <label className={css.filterField}>
          <span>Scope</span>
          <select value={scope} onChange={(e) => setScope(e.target.value)}>
            <option value="">All</option>
            {SCOPES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label className={css.filterField}>
          <span>Status</span>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
      </div>

      {msg && <div className="ov-error" role="alert" style={{ marginBottom: "var(--sp-3)" }}>{msg}</div>}

      <Card title={`Memories (${rows.length})`}>
        {search.loading || memories.loading ? (
          <EmptyState title="Loading…" />
        ) : search.error || memories.error ? (
          <div className="ov-error" role="alert">Failed to load memories: {(search.error ?? memories.error)}</div>
        ) : rows.length === 0 ? (
          <EmptyState title={q ? "No results" : "No memories"} hint={q ? "Try a different search." : "Memories appear as agents work."} />
        ) : (
          <DataTable columns={columns} rows={rows} rowKey={(m) => m.id} onRowClick={(m) => setSelected(m)} />
        )}
      </Card>

      {selected && (
        <Card title={`Memory #${selected.id} — ${selected.title}`} style={{ marginTop: "var(--sp-4)" }}>
          <StateView state={detail} empty={<EmptyState title="Details unavailable" />}>
            {(d) =>
              d.data ? (
                <div className={css.twoCol}>
                  <div>
                    <pre className="ov-mono" style={{ whiteSpace: "pre-wrap", fontSize: 13 }}>{d.data.memory.content}</pre>
                    <dl className="ov-muted" style={{ fontSize: 12, marginTop: "var(--sp-2)" }}>
                      <div>Trust: {d.data.memory.trust} · Version v{d.data.memory.version} · Expires: {d.data.memory.expiresAt ? fmtDate(d.data.memory.expiresAt) : "—"}</div>
                      <div>Source: {d.data.memory.source.summary}</div>
                    </dl>
                  </div>
                  <div>
                    <h4 style={{ marginBottom: "var(--sp-2)" }}>Retrievals</h4>
                    {d.data.retrievals.length === 0 ? (
                      <EmptyState title="Never retrieved" />
                    ) : (
                      <ul style={{ fontSize: 13 }}>
                        {d.data.retrievals.map((r) => (
                          <li key={r.executionId}>
                            <Link to={`/executions/${r.executionId}`}>Execution #{r.executionId}</Link> — rank {r.rank}, {timeAgo(r.at)}
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>
              ) : (
                <EmptyState title="Details unavailable" />
              )
            }
          </StateView>
          <div style={{ marginTop: "var(--sp-3)" }}>
            <h4 style={{ marginBottom: "var(--sp-2)" }}>Versions</h4>
            <StateView state={versions} empty={<EmptyState title="No versions" />}>
              {(v) =>
                (v.data.length === 0 ? (
                  <EmptyState title="No version history" />
                ) : (
                  <DataTable
                    columns={[
                      { key: "v", header: "Version", mono: true, width: "80px", render: (x: typeof v.data[number]) => `v${x.version}` },
                      { key: "by", header: "Changed by", render: (x: typeof v.data[number]) => `${x.changedBy.type} #${x.changedBy.id}` },
                      { key: "reason", header: "Reason", render: (x: typeof v.data[number]) => x.reason ?? "—" },
                      { key: "at", header: "At", render: (x: typeof v.data[number]) => fmtDate(x.createdAt) },
                    ]}
                    rows={v.data}
                    rowKey={(x) => x.id}
                  />
                ))
              }
            </StateView>
          </div>
        </Card>
      )}
    </div>
  );
}

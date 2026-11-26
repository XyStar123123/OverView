import { useState } from "react";
import { Link } from "react-router-dom";
import { PageHead, Card } from "./ui";
import { DataTable, Column } from "../components/DataTable";
import { StatusBadge } from "../components/StatusBadge";
import { EmptyState } from "../components/EmptyState";
import { getAgents } from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import type { Agent } from "../api/types";

const AGENT_STATUSES: Agent["status"][] = ["draft", "available", "working", "blocked", "waiting_for_approval", "offline", "disabled", "error"];

const columns: Column<Agent>[] = [
  { key: "name", header: "Agent", render: (a) => (
      <div className="ov-cell">
        <div>{a.name}</div>
        <div className="ov-muted">{a.role}</div>
      </div>
    ) },
  { key: "status", header: "Status", render: (a) => <StatusBadge state={a.status} /> },
  { key: "model", header: "Default model", render: (a) => <span className="ov-mono">{a.defaultModel}</span> },
  { key: "tools", header: "Tools", render: (a) => `${a.toolAllowlist.length} allowed` },
  { key: "link", header: "", render: (a) => <Link to={`/agents/${a.id}`}>Details</Link> },
];

export default function AgentsPage() {
  const [status, setStatus] = useState<string>("");
  const agents = useApi(() => getAgents(status || undefined), [status]);

  return (
    <div>
      <PageHead
        title="Agents"
        sub="Registry, status and configuration of the AI workforce."
        right={
          <select className="ov-select" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filter by status">
            <option value="">All statuses</option>
            {AGENT_STATUSES.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        }
      />
      <Card title="Agents">
        {agents.data ? (
          agents.data.data.length === 0 ? (
            <EmptyState title="No agents" hint="No agent matches this filter." />
          ) : (
            <DataTable columns={columns} rows={agents.data.data} rowKey={(a) => a.id} />
          )
        ) : agents.error ? (
          <div className="ov-error" role="alert">
            Failed to load agents: {agents.error}
          </div>
        ) : (
          <EmptyState title="Loading…" />
        )}
      </Card>
    </div>
  );
}

import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { StatusBadge } from "../components/StatusBadge";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import { getExecutions } from "../api/dataSource";
import { useApi, usePolling } from "../hooks/useApi";
import { fmtDate, fmtDuration } from "../lib/format";
import type { Execution, ExecutionStatus } from "../api/types";
import { Card, PageHead, StateView } from "./ui";

const STATUSES: ExecutionStatus[] = ["queued", "running", "waiting", "completed", "failed", "cancelled"];

export default function ExecutionsPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState("");
  const executions = useApi(() => getExecutions({ status: status || undefined }), [status]);

  const anyActive = executions.data?.data.some((e) => e.status === "queued" || e.status === "running") ?? false;
  usePolling(anyActive, 3000, () => executions.refresh());

  const columns: Column<Execution>[] = [
    { key: "id", header: "#", mono: true, width: "56px", render: (e) => e.id },
    { key: "status", header: "Status", render: (e) => <StatusBadge state={e.status} /> },
    { key: "agent", header: "Agent", render: (e) => <Link to={`/agents/${e.agentId}`}>{e.agentId}</Link> },
    { key: "task", header: "Task", render: (e) => <Link to={`/tasks/${e.taskId}`}>{e.taskId}</Link> },
    { key: "model", header: "Model", mono: true, render: (e) => `${e.modelResolution.gateway} / ${e.model}` },
    { key: "tokens", header: "Tokens", mono: true, width: "90px", render: (e) => `${e.usage.inputTokens + e.usage.outputTokens}` },
    { key: "duration", header: "Duration", mono: true, width: "90px", render: (e) => (e.usage.durationMs ? fmtDuration(e.usage.durationMs) : "—") },
    { key: "started", header: "Started", render: (e) => fmtDate(e.startedAt) },
    { key: "link", header: "", render: (e) => <Link to={`/executions/${e.id}`}>Details</Link> },
  ];

  return (
    <div>
      <PageHead
        title="Executions"
        sub="Agent task executions across runtimes and gateways."
        right={
          <select className="ov-select" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filter by status">
            <option value="">All statuses</option>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        }
      />
      <Card title={`Executions (${executions.data?.data.length ?? 0})`}>
        <StateView state={executions} empty={<EmptyState title="No executions" />}>
          {(d) =>
            d.data.length === 0 ? (
              <EmptyState title="No executions match this filter" hint="Run a task to create one." />
            ) : (
              <DataTable columns={columns} rows={d.data} rowKey={(e) => e.id} onRowClick={(e) => navigate(`/executions/${e.id}`)} />
            )
          }
        </StateView>
      </Card>
    </div>
  );
}

import { useState } from "react";
import { Link } from "react-router-dom";
import { StatusBadge } from "../components/StatusBadge";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import { getApprovals, approveApproval, rejectApproval } from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { timeAgo } from "../lib/format";
import type { Approval, ApprovalStatus } from "../api/types";
import { Card, PageHead, StateView } from "./ui";
import css from "./Page.module.css";

const STATUSES: ApprovalStatus[] = ["pending", "approved", "rejected", "expired"];

export default function ApprovalsPage() {
  const [status, setStatus] = useState<string>("pending");
  const [rejecting, setRejecting] = useState<Approval | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const approvals = useApi(() => getApprovals({ status: status || undefined }), [status]);

  const columns: Column<Approval>[] = [
    { key: "id", header: "#", mono: true, width: "56px", render: (a) => a.id },
    { key: "type", header: "Type", render: (a) => a.requestType.replace("_", " ") },
    { key: "resource", header: "Resource", render: (a) => (
        <span>
          {a.taskId ? <Link to={`/tasks/${a.taskId}`}>task #{a.taskId}</Link> : null}
          {a.memoryId ? <Link to="/memory">memory #{a.memoryId}</Link> : null}
          {a.executionId ? <Link to={`/executions/${a.executionId}`}>exec #{a.executionId}</Link> : null}
          {!a.taskId && !a.memoryId && !a.executionId ? "—" : null}
        </span>
      ) },
    { key: "status", header: "Status", render: (a) => <StatusBadge state={a.status} /> },
    { key: "requestedBy", header: "Requested by", render: (a) => `${a.requestedBy.type} #${a.requestedBy.id}` },
    { key: "decided", header: "Decided", render: (a) => (a.decidedAt ? timeAgo(a.decidedAt) : "—") },
    { key: "actions", header: "", width: "200px", render: (a) =>
        a.status === "pending" ? (
          <div className="ov-row">
            <button className="btn btn-sm btn-primary" disabled={busy !== null} onClick={() => void act(a, "approve")}>Approve</button>
            <button className="btn btn-sm btn-danger" disabled={busy !== null} onClick={() => { setRejecting(a); setReason(""); }}>Reject</button>
          </div>
        ) : a.decisionReason ? (
          <span className="ov-muted" title={a.decisionReason}>reason on file</span>
        ) : (
          <span className="ov-muted">—</span>
        )
      },
  ];

  const act = async (a: Approval, decision: "approve" | "reject") => {
    setBusy(`${a.id}:${decision}`);
    setMsg(null);
    try {
      if (decision === "approve") await approveApproval(a.id);
      else await rejectApproval(a.id, reason.trim() || undefined);
      approvals.refresh();
      setRejecting(null);
    } catch (e) {
      setMsg(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div>
      <PageHead
        title="Approvals"
        sub="Task and memory review requests awaiting a human decision."
        right={
          <select className="ov-select" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filter by status">
            <option value="pending">Pending</option>
            {STATUSES.filter((s) => s !== "pending").map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
            <option value="">All</option>
          </select>
        }
      />
      {msg && <div className="ov-error" role="alert" style={{ marginBottom: "var(--sp-3)" }}>{msg}</div>}
      <Card title={`Approvals (${approvals.data?.data.length ?? 0})`}>
        <StateView state={approvals} empty={<EmptyState title="No approvals" />}>
          {(d) =>
            d.data.length === 0 ? (
              <EmptyState title={status === "pending" ? "Nothing pending" : "No approvals match this filter"} hint="Agent runs create review requests here." />
            ) : (
              <DataTable columns={columns} rows={d.data} rowKey={(a) => a.id} />
            )
          }
        </StateView>
      </Card>

      {rejecting && (
        <Card title={`Reject approval #${rejecting.id}`} style={{ marginTop: "var(--sp-4)" }}>
          <div className={css.form}>
            <label className={css.field}>
              <span style={{ fontSize: 12 }}>Reason (optional)</span>
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} style={{ width: "100%" }} />
            </label>
            <div className={css.actions}>
              <button className="btn" onClick={() => setRejecting(null)}>Cancel</button>
              <button className="btn btn-danger" disabled={busy !== null} onClick={() => void act(rejecting, "reject")}>
                {busy === `${rejecting.id}:reject` ? "Rejecting…" : "Reject"}
              </button>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
}

import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { StatusBadge } from "../components/StatusBadge";
import { EventTimeline } from "../components/EventTimeline";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import { ConfirmDialog } from "../components/ConfirmDialog";
import { getExecution, getExecutionEvents, getExecutionArtifacts, getExecutionMemories, cancelExecution } from "../api/dataSource";
import { useApi, usePolling } from "../hooks/useApi";
import { fmtDate, fmtBytes, timeAgo } from "../lib/format";
import type { Artifact, Execution } from "../api/types";
import { Card, Meta, PageHead, StateView, ExecutionUsage } from "./ui";
import css from "./Page.module.css";

export default function ExecutionDetailPage() {
  const { id } = useParams();
  const executionId = Number(id);
  const execution = useApi(() => getExecution(executionId), [executionId]);
  const events = useApi(() => getExecutionEvents(executionId), [executionId, execution.data]);
  const artifacts = useApi(() => getExecutionArtifacts(executionId), [executionId, execution.data]);
  const memories = useApi(() => getExecutionMemories(executionId), [executionId, execution.data]);

  const [cancelOpen, setCancelOpen] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const live = execution.data?.data.status === "running" || execution.data?.data.status === "queued";
  usePolling(live, 2000, () => {
    execution.refresh();
    events.refresh();
  });

  const artifactColumns: Column<Artifact>[] = [
    { key: "name", header: "Artifact", render: (a) => a.name },
    { key: "type", header: "Type", render: (a) => a.artifactType },
    { key: "size", header: "Size", mono: true, width: "90px", render: (a) => fmtBytes(a.sizeBytes) },
    { key: "version", header: "Version", mono: true, width: "80px", render: (a) => `v${a.version}` },
    { key: "status", header: "Status", render: (a) => <StatusBadge state={a.status} /> },
    { key: "at", header: "Submitted", render: (a) => timeAgo(a.createdAt) },
  ];

  const cancel = async () => {
    setMsg(null);
    try {
      await cancelExecution(executionId);
      execution.refresh();
      setMsg("Execution cancelled.");
    } catch (e) {
      setMsg(e instanceof Error ? e.message : String(e));
    }
  };

  return (
    <div>
      <StateView state={execution} empty={<EmptyState title="Execution not found" />}>
        {(d) => {
          const ex: Execution = d.data;
          return (
            <>
              <PageHead
                title={`Execution #${ex.id}`}
                sub={<span><StatusBadge state={ex.status} /> {ex.modelResolution.gateway} / {ex.model}</span>}
                right={
                  <div className="ov-row">
                    {live && (
                      <button className="btn btn-danger" onClick={() => setCancelOpen(true)}>Cancel execution</button>
                    )}
                    <Link className="btn" to={`/tasks/${ex.taskId}`}>Task #{ex.taskId}</Link>
                  </div>
                }
              />
              {msg && <div className={msg === "Execution cancelled." ? "ov-success" : "ov-error"} role="alert" style={{ marginBottom: "var(--sp-3)" }}>{msg}</div>}
              <Meta
                items={[
                  { label: "Agent", value: <Link to={`/agents/${ex.agentId}`}>{ex.agentId}</Link> },
                  { label: "Project", value: <Link to={`/projects/${ex.projectId}`}>{ex.projectId}</Link> },
                  { label: "Runtime", value: `#${ex.runtimeId}` },
                  { label: "Model source", value: ex.modelResolution.source },
                  { label: "Started", value: fmtDate(ex.startedAt) },
                  { label: "Finished", value: fmtDate(ex.finishedAt) },
                ]}
              />
              {ex.failureReason && <div className="ov-error" role="alert" style={{ marginBottom: "var(--sp-3)" }}>Failure: {ex.failureReason}</div>}

              <div className={css.twoCol}>
                <Card title={`Events (${events.data?.data.length ?? 0})`} right={live && <span className="ov-success">live</span>}>
                  <StateView state={events} empty={<EmptyState title="No events" />}>
                    {(ev) => (
                      <EventTimeline events={ev.data} live={live} />
                    )}
                  </StateView>
                </Card>

                <div style={{ display: "grid", gap: "var(--sp-4)" }}>
                  <Card title={`Artifacts (${artifacts.data?.data.length ?? 0})`}>
                    <StateView state={artifacts} empty={<EmptyState title="No artifacts" />}>
                      {(a) =>
                        a.data.length === 0 ? (
                          <EmptyState title="No artifacts submitted" />
                        ) : (
                          <DataTable columns={artifactColumns} rows={a.data} rowKey={(x) => x.id} />
                        )
                      }
                    </StateView>
                  </Card>

                  <Card title="Usage">
                    <ExecutionUsage execution={ex} />
                  </Card>

                  <Card title={`Memories (retrieved ${memories.data?.data.retrieved.length ?? 0}, proposed ${memories.data?.data.proposed.length ?? 0})`}>
                    <StateView state={memories} empty={<EmptyState title="No memory activity" />}>
                      {(m) => (
                        <div style={{ display: "grid", gap: "var(--sp-3)" }}>
                          {m.data.retrieved.length > 0 && (
                            <div>
                              <div className="ov-muted" style={{ fontSize: 12, marginBottom: "var(--sp-1)" }}>Retrieved</div>
                              {m.data.retrieved.map((r) => (
                                <div key={r.id} style={{ fontSize: 13 }}>
                                  #{r.id} — {r.title} <span className="ov-muted">(score {r.score.toFixed(2)})</span>
                                </div>
                              ))}
                            </div>
                          )}
                          {m.data.proposed.length > 0 && (
                            <div>
                              <div className="ov-muted" style={{ fontSize: 12, marginBottom: "var(--sp-1)" }}>Proposed for review</div>
                              {m.data.proposed.map((p) => (
                                <div key={p.id} style={{ fontSize: 13 }}>
                                  <StatusBadge state={p.status} /> {p.title}
                                </div>
                              ))}
                            </div>
                          )}
                          {m.data.retrieved.length === 0 && m.data.proposed.length === 0 && <EmptyState title="No memory retrieval or proposals" />}
                        </div>
                      )}
                    </StateView>
                  </Card>
                </div>
              </div>

              <ConfirmDialog
                open={cancelOpen}
                title="Cancel this execution?"
                body={<p>The running agent will be stopped. Partial results are kept.</p>}
                confirmLabel="Cancel execution"
                danger
                onConfirm={cancel}
                onClose={() => setCancelOpen(false)}
              />
            </>
          );
        }}
      </StateView>
    </div>
  );
}

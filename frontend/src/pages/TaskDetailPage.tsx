import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { StatusBadge } from "../components/StatusBadge";
import { DataTable, Column } from "../components/DataTable";
import { EmptyState } from "../components/EmptyState";
import {
  getTask,
  getTaskComments,
  getTaskArtifacts,
  getTaskExecutions,
  getTaskMemory,
  createTaskComment,
  executeTask,
  reviewTask,
  patchTask,
} from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { fmtDate, fmtBytes } from "../lib/format";
import type { Artifact, Execution, Task, TaskComment, TaskStatus } from "../api/types";
import { Card, Meta, PageHead, StateView } from "./ui";
import css from "./Page.module.css";

const STATUSES: TaskStatus[] = ["todo", "in_progress", "pending", "blocked", "in_review", "done", "cancelled"];

export default function TaskDetailPage() {
  const { id } = useParams();
  const taskId = Number(id);
  const task = useApi(() => getTask(taskId), [taskId]);
  const comments = useApi(() => getTaskComments(taskId), [taskId, task.data]);
  const artifacts = useApi(() => getTaskArtifacts(taskId), [taskId, task.data]);
  const executions = useApi(() => getTaskExecutions(taskId), [taskId, task.data]);
  const memory = useApi(() => getTaskMemory(taskId), [taskId, task.data]);

  const [comment, setComment] = useState("");
  const [posting, setPosting] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  const run = async (key: string, fn: () => Promise<unknown>) => {
    setBusy(key);
    try {
      await fn();
    } finally {
      setBusy(null);
    }
  };

  const artifactColumns: Column<Artifact>[] = [
    { key: "name", header: "Artifact", render: (a) => a.name },
    { key: "type", header: "Type", render: (a) => a.artifactType },
    { key: "size", header: "Size", render: (a) => fmtBytes(a.sizeBytes) },
    { key: "version", header: "Version", mono: true, width: "80px", render: (a) => `v${a.version}` },
    { key: "status", header: "Status", render: (a) => <StatusBadge state={a.status} /> },
    { key: "at", header: "Submitted", render: (a) => fmtDate(a.createdAt) },
  ];

  const executionColumns: Column<Execution>[] = [
    { key: "id", header: "#", mono: true, width: "56px", render: (e) => e.id },
    { key: "status", header: "Status", render: (e) => <StatusBadge state={e.status} /> },
    { key: "model", header: "Model", mono: true, render: (e) => `${e.modelResolution.gateway} / ${e.model}` },
    { key: "started", header: "Started", render: (e) => fmtDate(e.startedAt) },
    { key: "finished", header: "Finished", render: (e) => fmtDate(e.finishedAt) },
    { key: "link", header: "", render: (e) => <Link to={`/executions/${e.id}`}>Details</Link> },
  ];

  const commentColumns: Column<TaskComment>[] = [
    { key: "actor", header: "Actor", render: (c) => `${c.actor.type} #${c.actor.id}` },
    { key: "body", header: "Comment", render: (c) => c.body },
    { key: "at", header: "At", render: (c) => fmtDate(c.createdAt) },
  ];

  return (
    <StateView state={task} empty={<EmptyState title="Task not found" />}>
      {(t) => {
        const tk: Task = t.data;
        const inReview = tk.status === "in_review";
        return (
          <div>
            <PageHead
              title={tk.title}
              sub={
                <span>
                  <StatusBadge state={tk.status} /> {tk.type} · priority {tk.priority}
                </span>
              }
              right={
                <div className="ov-row">
                  <select
                    aria-label="Change status"
                    value={tk.status}
                    style={{ width: 140 }}
                    disabled={busy === "status"}
                    onChange={(e) =>
                      void run("status", async () => {
                        await patchTask(taskId, { status: e.target.value });
                        task.refresh();
                      })
                    }
                  >
                    {STATUSES.map((s) => (
                      <option key={s} value={s}>
                        {s.replace(/_/g, " ")}
                      </option>
                    ))}
                  </select>
                </div>
              }
            />

            {tk.description && <p style={{ whiteSpace: "pre-wrap" }}>{tk.description}</p>}

            <Meta
              items={[
                {
                  label: "Project",
                  value: <Link to={`/projects/${tk.projectId}`}>{`#${tk.projectId}`}</Link>,
                },
                { label: "Assignee", value: tk.assignee ? `${tk.assignee.type} #${tk.assignee.id}` : "—" },
                { label: "Due", value: fmtDate(tk.dueDate) },
                { label: "Updated", value: fmtDate(tk.updatedAt) },
                { label: "Running execution", value: tk.hasRunningExecution ? "yes" : "no" },
              ]}
            />

            <div className={css.split}>
              <div>
                <Card
                  title="Actions"
                  right={inReview ? <StatusBadge state="in_review" label="in review" /> : undefined}
                >
                  <div className={css.actions}>
                    <button
                      type="button"
                      className="btn btn-primary"
                      disabled={busy === "execute" || tk.hasRunningExecution}
                      onClick={() =>
                        void run("execute", async () => {
                          await executeTask(taskId);
                          task.refresh();
                        })
                      }
                    >
                      {busy === "execute" ? "Starting..." : "Run agent"}
                    </button>
                    {inReview && (
                      <>
                        <button
                          type="button"
                          className="btn"
                          disabled={busy === "approve"}
                          onClick={() =>
                            void run("approve", async () => {
                              await reviewTask(taskId, "approve");
                              task.refresh();
                            })
                          }
                        >
                          {busy === "approve" ? "..." : "Approve"}
                        </button>
                        <input
                          value={reason}
                          onChange={(e) => setReason(e.target.value)}
                          placeholder="Reject reason"
                          style={{ flex: 1 }}
                        />
                        <button
                          type="button"
                          className="btn btn-danger"
                          disabled={busy === "reject"}
                          onClick={() =>
                            void run("reject", async () => {
                              await reviewTask(taskId, "reject", reason.trim() || undefined);
                              setReason("");
                              task.refresh();
                              comments.refresh();
                            })
                          }
                        >
                          {busy === "reject" ? "..." : "Reject"}
                        </button>
                      </>
                    )}
                  </div>
                </Card>

                <Card title="Artifacts">
                  <StateView state={artifacts} empty={<EmptyState title="No artifacts" />}>
                    {(d) =>
                      d.data.length === 0 ? (
                        <EmptyState title="No artifacts submitted yet" hint="Agent runs produce artifacts here." />
                      ) : (
                        <DataTable columns={artifactColumns} rows={d.data} rowKey={(a) => a.id} />
                      )
                    }
                  </StateView>
                </Card>

                <Card title="Executions">
                  <StateView state={executions} empty={<EmptyState title="No executions" />}>
                    {(d) =>
                      d.data.length === 0 ? (
                        <EmptyState title="No executions yet" hint="Run the agent to start one." />
                      ) : (
                        <DataTable
                          columns={executionColumns}
                          rows={d.data}
                          rowKey={(e) => e.id}
                        />
                      )
                    }
                  </StateView>
                </Card>
              </div>

              <div>
                <Card title="Memory">
                  <StateView state={memory} empty={<EmptyState title="No task memory" />}>
                    {(m) => (
                      <>
                        <div className={css.sectionTitle}>Working</div>
                        {m.data.working.length === 0 ? (
                          <div className="ov-muted">No working memory for this task.</div>
                        ) : (
                          m.data.working.map((mem) => (
                            <div key={mem.id} className={css.listItem}>
                              <StatusBadge state={mem.status} /> {mem.title}
                            </div>
                          ))
                        )}
                        <div className={css.sectionTitle}>Proposed</div>
                        {m.data.proposed.length === 0 ? (
                          <div className="ov-muted">No proposed memories.</div>
                        ) : (
                          m.data.proposed.map((mem) => (
                            <div key={mem.id} className={css.listItem}>
                              <StatusBadge state={mem.status} /> {mem.title}{" "}
                              <Link to="/approvals">Review</Link>
                            </div>
                          ))
                        )}
                      </>
                    )}
                  </StateView>
                </Card>

                <Card title="Comments">
                  <StateView state={comments} empty={<EmptyState title="No comments" />}>
                    {(c) => (
                      <>
                        <div style={{ marginBottom: "var(--sp-3)" }}>
                          <textarea
                            rows={2}
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            placeholder="Add a comment"
                            style={{ width: "100%" }}
                          />
                          <div className={css.actions}>
                            <button
                              type="button"
                              className="btn btn-primary"
                              disabled={posting || !comment.trim()}
                              onClick={() =>
                                void (async () => {
                                  setPosting(true);
                                  try {
                                    await createTaskComment(taskId, { body: comment.trim() });
                                    setComment("");
                                    comments.refresh();
                                  } finally {
                                    setPosting(false);
                                  }
                                })()
                              }
                            >
                              {posting ? "Posting..." : "Post"}
                            </button>
                          </div>
                        </div>
                        <DataTable columns={commentColumns} rows={c.data} rowKey={(cm) => cm.id} empty={<EmptyState title="No comments yet" />} />
                      </>
                    )}
                  </StateView>
                </Card>
              </div>
            </div>
          </div>
        );
      }}
    </StateView>
  );
}

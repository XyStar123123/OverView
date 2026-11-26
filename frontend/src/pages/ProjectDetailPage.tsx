import { useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { KanbanBoard } from "../components/KanbanBoard";
import { DataTable, Column } from "../components/DataTable";
import { StatusBadge } from "../components/StatusBadge";
import { EmptyState } from "../components/EmptyState";
import { getProject, getProjectAgents, getProjectTasks, patchTask, createTask } from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { fmtDate } from "../lib/format";
import type { Project, Task } from "../api/types";
import { Card, Meta, PageHead, StateView } from "./ui";
import css from "./Page.module.css";

type Tab = "overview" | "board" | "tasks";

export default function ProjectDetailPage({ tab }: { tab: Tab }) {
  const { id } = useParams();
  const projectId = Number(id);
  const navigate = useNavigate();
  const project = useApi(() => getProject(projectId), [projectId]);
  const agents = useApi(() => getProjectAgents(projectId), [projectId]);
  const tasks = useApi(() => getProjectTasks(projectId), [projectId]);

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [assignee, setAssignee] = useState("");
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  const agentOptions = useMemo(
    () => (agents.data?.data ?? []).filter((a) => a.status === "available" || a.status === "working"),
    [agents.data],
  );

  const taskColumns: Column<Task>[] = [
    { key: "id", header: "#", mono: true, width: "56px", render: (t) => t.id },
    { key: "title", header: "Task", render: (t) => <Link to={`/tasks/${t.id}`}>{t.title}</Link> },
    { key: "status", header: "Status", render: (t) => <StatusBadge state={t.status} /> },
    { key: "priority", header: "Priority", render: (t) => <StatusBadge state={`priority_${t.priority}`} label={t.priority} /> },
    { key: "assignee", header: "Assignee", render: (t) => (t.assignee ? `${t.assignee.type} #${t.assignee.id}` : "—") },
    { key: "due", header: "Due", render: (t) => fmtDate(t.dueDate) },
  ];

  const createSubmit = async () => {
    if (!title.trim()) return;
    setCreating(true);
    setCreateError(null);
    try {
      await createTask(projectId, {
        title: title.trim(),
        description: description.trim() || undefined,
        assignee: assignee ? { type: "agent", id: Number(assignee) } : undefined,
      });
      setTitle("");
      setDescription("");
      setAssignee("");
      tasks.refresh();
    } catch (e) {
      setCreateError(e instanceof Error ? e.message : String(e));
    } finally {
      setCreating(false);
    }
  };

  return (
    <div>
      <StateView state={project} empty={<EmptyState title="Project not found" />}>
        {(p) => {
          const pr: Project = p.data;
          return (
            <>
              <PageHead
                title={pr.name}
                sub={
                  <span>
                    <StatusBadge state={pr.status} /> {pr.description}
                  </span>
                }
                right={
                  <div className="ov-row">
                    <Link className={tab === "overview" ? "btn btn-primary" : "btn"} to={`/projects/${pr.id}`}>
                      Overview
                    </Link>
                    <Link className={tab === "board" ? "btn btn-primary" : "btn"} to={`/projects/${pr.id}/board`}>
                      Board
                    </Link>
                    <Link className={tab === "tasks" ? "btn btn-primary" : "btn"} to={`/projects/${pr.id}/tasks`}>
                      Tasks
                    </Link>
                  </div>
                }
              />
              <Meta
                items={[
                  { label: "Priority", value: <StatusBadge state={`priority_${pr.priority}`} label={pr.priority} /> },
                  { label: "Agents", value: pr.agentCount },
                  { label: "Tasks", value: pr.taskCount },
                  { label: "Start", value: fmtDate(pr.startDate) },
                  { label: "Due", value: fmtDate(pr.dueDate) },
                ]}
              />
              {pr.objectives.length > 0 && (
                <Card title="Objectives">
                  <ul style={{ margin: 0, paddingLeft: 20 }}>
                    {pr.objectives.map((o) => (
                      <li key={o}>{o}</li>
                    ))}
                  </ul>
                </Card>
              )}

              {tab === "overview" && (
                <div className={css.split}>
                  <Card title="Agents on this project">
                    <StateView state={agents} empty={<EmptyState title="No agents" hint="Agents will show up here when assigned." />}>
                      {(d) =>
                        (d.data.length === 0
                          ? <EmptyState title="No agents assigned" />
                          : d.data.map((a) => (
                              <div key={a.id} className={css.listItem}>
                                <Link to={`/agents/${a.id}`} style={{ fontWeight: 600 }}>
                                  {a.name}
                                </Link>
                                <span>
                                  <StatusBadge state={a.status} />
                                </span>
                              </div>
                            ))
                      )}
                    </StateView>
                  </Card>
                  <Card title="Recent tasks" right={<Link to={`/projects/${pr.id}/board`}>Board</Link>}>
                    <StateView state={tasks} empty={<EmptyState title="No tasks yet" />}>
                      {(d) =>
                        d.data.slice(0, 5).length === 0 ? (
                          <EmptyState title="No tasks yet" hint="Create the first task below." />
                        ) : (
                          <DataTable
                            columns={[
                              { key: "title", header: "Task", render: (t: Task) => <Link to={`/tasks/${t.id}`}>{t.title}</Link> },
                              { key: "status", header: "Status", render: (t: Task) => <StatusBadge state={t.status} /> },
                            ]}
                            rows={d.data.slice(0, 5)}
                            rowKey={(t: Task) => t.id}
                          />
                        )
                      }
                    </StateView>
                  </Card>
                </div>
              )}

              {tab === "board" && (
                <StateView state={tasks} empty={<EmptyState title="No tasks" hint="Create a task to see the board." />}>
                  {(d) => <KanbanBoard tasks={d.data} onStatusChange={(taskId, status) => void patchTask(taskId, { status }).then(() => tasks.refresh())} />}
                </StateView>
              )}

              {tab === "tasks" && (
                <>
                  <Card
                    title="Create task"
                    right={<Link to={`/projects/${pr.id}`}>Back</Link>}
                  >
                    <div className={css.form}>
                      <div className={css.row}>
                        <label className={css.field}>
                          <span>Title</span>
                          <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Task title" />
                        </label>
                        <label className={css.field}>
                          <span>Assign to agent</span>
                          <select value={assignee} onChange={(e) => setAssignee(e.target.value)}>
                            <option value="">Unassigned</option>
                            {agentOptions.map((a) => (
                              <option key={a.id} value={a.id}>
                                {a.name}
                              </option>
                            ))}
                          </select>
                        </label>
                      </div>
                      <label className={css.field}>
                        <span>Description</span>
                        <textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="What should the agent do?" />
                      </label>
                      {createError && <div className="ov-error">{createError}</div>}
                      <div className={css.actions}>
                        <button
                          type="button"
                          className="btn btn-primary"
                          disabled={creating || !title.trim()}
                          onClick={() => void createSubmit()}
                        >
                          {creating ? "Creating..." : "Create task"}
                        </button>
                      </div>
                    </div>
                  </Card>
                  <StateView state={tasks} empty={<EmptyState title="No tasks" />}>
                    {(d) =>
                      d.data.length === 0 ? (
                        <EmptyState title="No tasks yet" hint="Create the first task above." />
                      ) : (
                        <DataTable columns={taskColumns} rows={d.data} rowKey={(t) => t.id} onRowClick={(t) => navigate(`/tasks/${t.id}`)} />
                      )
                    }
                  </StateView>
                </>
              )}
            </>
          );
        }}
      </StateView>
    </div>
  );
}

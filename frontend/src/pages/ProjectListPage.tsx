import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { getProjects, createProject } from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { StatusBadge } from "../components/StatusBadge";
import { EmptyState } from "../components/EmptyState";
import { fmtDate } from "../lib/format";
import { Card, PageHead, StateView } from "./ui";
import css from "./Page.module.css";

const STATUS_FILTER = ["", "planning", "active", "paused", "completed", "archived"] as const;

export default function ProjectListPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState("");
  const projects = useApi(
    () => getProjects(status || undefined),
    [status],
  );

  const [showForm, setShowForm] = useState(false);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [objectives, setObjectives] = useState("");
  const [priority, setPriority] = useState("medium");
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  const submit = async () => {
    if (!name.trim()) return;
    setCreating(true);
    setCreateError(null);
    try {
      const res = await createProject({
        name: name.trim(),
        description: description.trim(),
        objectives: objectives
          .split("\n")
          .map((s) => s.trim())
          .filter(Boolean),
        status: "planning",
        priority,
      });
      projects.refresh();
      setShowForm(false);
      setName("");
      setDescription("");
      setObjectives("");
      navigate(`/projects/${res.data.id}`);
    } catch (e) {
      setCreateError(e instanceof Error ? e.message : String(e));
    } finally {
      setCreating(false);
    }
  };

  return (
    <div>
      <PageHead
        title="Projects"
        sub="Workspace projects and their progress."
        right={
          <button type="button" className="btn btn-primary" onClick={() => setShowForm((v) => !v)}>
            {showForm ? "Cancel" : "New project"}
          </button>
        }
      />

      {showForm && (
        <Card title="Create project">
          <div className={css.form}>
            <div className={css.row}>
              <label className={css.field}>
                <span>Name</span>
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Project name" />
              </label>
              <label className={css.field}>
                <span>Priority</span>
                <select value={priority} onChange={(e) => setPriority(e.target.value)}>
                  <option value="low">low</option>
                  <option value="medium">medium</option>
                  <option value="high">high</option>
                </select>
              </label>
            </div>
            <label className={css.field}>
              <span>Description</span>
              <input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="What is this project about?" />
            </label>
            <label className={css.field}>
              <span>Objectives (one per line)</span>
              <textarea rows={3} value={objectives} onChange={(e) => setObjectives(e.target.value)} />
            </label>
            {createError && <div className="ov-error">{createError}</div>}
            <div className={css.actions}>
              <button type="button" className="btn btn-primary" onClick={() => void submit()} disabled={creating || !name.trim()}>
                {creating ? "Creating..." : "Create"}
              </button>
            </div>
          </div>
        </Card>
      )}

      <div className={css.filters}>
        <label className={css.filterField}>
          <span>Status</span>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            {STATUS_FILTER.map((s) => (
              <option key={s} value={s}>
                {s === "" ? "all" : s}
              </option>
            ))}
          </select>
        </label>
      </div>

      <StateView state={projects} empty={<EmptyState title="No projects" hint="Create the first project." />}>
        {(data) => (
          <div className={css.cards}>
            {data.data.map((p) => (
              <Card key={p.id} className={css.projectCard}>
                <div className="ov-row-between">
                  <Link to={`/projects/${p.id}`} style={{ fontWeight: 600 }}>
                    {p.name}
                  </Link>
                  <StatusBadge state={p.status} />
                </div>
                <p className="ov-muted" style={{ margin: "var(--sp-2) 0" }}>
                  {p.description}
                </p>
                <div className="ov-row" style={{ fontSize: 12 }}>
                  <span className="ov-muted">{p.taskCount} tasks · {p.agentCount} agents</span>
                  <span className="ov-muted">due {fmtDate(p.dueDate)}</span>
                </div>
              </Card>
            ))}
          </div>
        )}
      </StateView>
    </div>
  );
}

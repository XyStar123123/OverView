import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import type { Task, TaskStatus } from "../api/types";
import { StatusBadge, stateColor } from "./StatusBadge";
import css from "./KanbanBoard.module.css";

/** Board statuses shown as columns (manual drag = PATCH /tasks/{id}). */
const COLUMNS: TaskStatus[] = ["todo", "in_progress", "pending", "blocked", "in_review", "done"];

interface KanbanBoardProps {
  tasks: Task[];
  onStatusChange: (taskId: number, status: TaskStatus) => void | Promise<void>;
}

/**
 * Status-grouped kanban with HTML5 drag-and-drop for manual status change
 * (contract §6: "drag hanya untuk ubah status manual — call PATCH /tasks/{id}").
 */
export function KanbanBoard({ tasks, onStatusChange }: KanbanBoardProps) {
  const [dragOver, setDragOver] = useState<TaskStatus | null>(null);
  const [dragId, setDragId] = useState<number | null>(null);

  const byStatus = useMemo(() => {
    const map = new Map<TaskStatus, Task[]>();
    for (const c of COLUMNS) map.set(c, []);
    for (const t of tasks) {
      const arr = map.get(t.status);
      if (arr) arr.push(t);
      else map.set(t.status, [t]);
    }
    return map;
  }, [tasks]);

  const handleDrop = (status: TaskStatus) => {
    setDragOver(null);
    if (dragId !== null) void onStatusChange(dragId, status);
    setDragId(null);
  };

  return (
    <div className={css.board}>
      {COLUMNS.map((col) => (
        <section
          key={col}
          className={`${css.column} ${dragOver === col ? css.columnOver : ""}`}
          style={{ borderTopColor: stateColor(col) }}
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(col);
          }}
          onDragLeave={() => setDragOver((d) => (d === col ? null : d))}
          onDrop={(e) => {
            e.preventDefault();
            handleDrop(col);
          }}
          aria-label={`${col.replace(/_/g, " ")} tasks`}
        >
          <header className={css.colHead}>
            <StatusBadge state={col} />
            <span className={css.count}>{byStatus.get(col)?.length ?? 0}</span>
          </header>
          <div className={css.cards}>
            {(byStatus.get(col) ?? []).map((t) => (
              <article
                key={t.id}
                className={css.card}
                draggable
                onDragStart={() => setDragId(t.id)}
                onDragEnd={() => setDragId(null)}
              >
                <Link to={`/tasks/${t.id}`} className={css.cardTitle}>
                  {t.title}
                </Link>
                <div className={css.cardMeta}>
                  <span className={css.priority} style={{ color: stateColor(`priority_${t.priority}`) }} data-p={t.priority}>
                    {t.priority}
                  </span>
                  {t.assignee && <span className={css.assignee}>{t.assignee.type} #{t.assignee.id}</span>}
                  {t.hasRunningExecution && <span className={css.running}>running</span>}
                </div>
              </article>
            ))}
            {(byStatus.get(col)?.length ?? 0) === 0 && <div className={css.emptyCol}>No tasks</div>}
          </div>
        </section>
      ))}
    </div>
  );
}

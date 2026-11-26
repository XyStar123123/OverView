import { CSSProperties, ReactNode } from "react";
import { EmptyState } from "../components/EmptyState";
import { fmtDate, fmtDuration, timeAgo } from "../lib/format";
import type { Execution } from "../api/types";
import css from "./Page.module.css";

export function PageHead({ title, sub, right }: { title: string; sub?: ReactNode; right?: ReactNode }) {
  return (
    <div className="ov-row-between" style={{ marginBottom: "var(--sp-4)" }}>
      <div>
        <h1>{title}</h1>
        {sub && <div className="ov-muted">{sub}</div>}
      </div>
      {right && <div className="ov-row">{right}</div>}
    </div>
  );
}

export function Card({
  title,
  right,
  children,
  style,
  className = "",
}: {
  title?: ReactNode;
  right?: ReactNode;
  children: ReactNode;
  style?: CSSProperties;
  className?: string;
}) {
  return (
    <section className={`ov-card ${className}`} style={style}>
      {(title || right) && (
        <div className="ov-row-between" style={{ marginBottom: "var(--sp-3)" }}>
          {title && <h3 style={{ margin: 0 }}>{title}</h3>}
          {right}
        </div>
      )}
      {children}
    </section>
  );
}

export function Field({ label, children, style }: { label: string; children: ReactNode; style?: CSSProperties }) {
  return (
    <label className={css.field} style={style}>
      <span style={{ fontSize: 12, color: "var(--ov-text-secondary)" }}>{label}</span>
      {children}
    </label>
  );
}

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <label className="switch">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  );
}

/** The shape every useApi result has, so views can accept fetch state by name. */
export interface ApiStateLike<T> {
  loading: boolean;
  error: string | null;
  data: T | null;
}

/** Renders loading / error / empty / content in one place. */
export function StateView<T>({ state, children, empty }: { state: ApiStateLike<T>; children: ReactNode | ((data: T) => ReactNode); empty?: ReactNode }) {
  if (state.loading) return <div className="ov-muted">Loading…</div>;
  if (state.error) return <div className="ov-error">Error: {state.error}</div>;
  if (state.data === null) return <>{empty ?? <EmptyState title="Nothing here" />}</>;
  const content = typeof children === "function" ? (children as (d: T) => ReactNode)(state.data) : children;
  return <>{content}</>;
}

export function Meta({ items }: { items: { label: string; value: ReactNode }[] }) {
  return (
    <div className={css.meta}>
      {items.map((it) => (
        <div key={it.label} className={css.metaItem}>
          <label>{it.label}</label>
          <div>{it.value}</div>
        </div>
      ))}
    </div>
  );
}

export function ExecutionUsage({ execution }: { execution: Execution }) {
  const u = execution.usage;
  return (
    <Meta
      items={[
        { label: "Input tokens", value: u.inputTokens },
        { label: "Output tokens", value: u.outputTokens },
        { label: "Context tokens", value: u.contextTokens },
        { label: "Tool calls", value: u.toolInvocations },
        { label: "Memories retrieved", value: u.retrievedMemoryCount },
        { label: "Est. cost", value: `$${u.estimatedCost.toFixed(2)}` },
        { label: "Duration", value: fmtDuration(u.durationMs) },
      ]}
    />
  );
}

export function ActivityList({
  items,
}: {
  items: { id: number; actor: { type: string; id: number }; action: string; at: string }[];
}) {
  if (items.length === 0) return <EmptyState title="No recent activity" />;
  return (
    <ul className={css.list}>
      {items.map((a) => (
        <li key={a.id} className={css.listItem}>
          <span>
            <strong>{a.actor.type}</strong> #{a.actor.id} — {a.action}
          </span>
          <span className="ov-muted" title={fmtDate(a.at)}>
            {timeAgo(a.at)}
          </span>
        </li>
      ))}
    </ul>
  );
}

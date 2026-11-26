import css from "./StatusBadge.module.css";

/**
 * StatusBadge — the single owner of the "one color per state" map
 * (ARCHITECTURE.md §6.1). All badge/pill state colors in the app flow
 * through the tokens below; components never hardcode state colors.
 */
const STATE_COLOR: Record<string, string> = {
  // gray
  todo: "var(--st-gray)",
  candidate: "var(--st-gray)",
  unknown: "var(--st-gray)",
  // blue
  in_progress: "var(--st-blue)",
  running: "var(--st-blue)",
  // amber
  pending: "var(--st-amber)",
  pending_review: "var(--st-amber)",
  waiting: "var(--st-amber)",
  // priority (visual cue on kanban cards)
  high: "var(--st-red)",
  medium: "var(--st-amber)",
  low: "var(--st-gray)",
  priority_high: "var(--st-red)",
  priority_medium: "var(--st-amber)",
  priority_low: "var(--st-gray)",
  blocked: "var(--st-red)",
  failed: "var(--st-red)",
  error: "var(--st-red)",
  rejected: "var(--st-red)",
  // violet
  in_review: "var(--st-violet)",
  approved: "var(--st-violet)",
  // green
  done: "var(--st-green)",
  completed: "var(--st-green)",
  active: "var(--st-green)",
  connected: "var(--st-green)",
  healthy: "var(--st-green)",
  available: "var(--st-green)",
  // gray-400 (muted / inactive)
  cancelled: "var(--st-gray400)",
  archived: "var(--st-gray400)",
  superseded: "var(--st-gray400)",
  expired: "var(--st-gray400)",
  offline: "var(--st-gray400)",
  disabled: "var(--st-gray400)",
  paused: "var(--st-gray400)",
  draft: "var(--st-gray400)",
};

export function stateColor(state: string): string {
  return STATE_COLOR[state] ?? "var(--st-gray)";
}

/** A generic state-colored pill (dot + label). `state` drives the color. */
export function StatusBadge({ state, label, className = "" }: { state: string; label?: string; className?: string }) {
  const color = stateColor(state);
  const text = label ?? state.replace(/_/g, " ");
  return (
    <span
      className={`${css.badge} ${className}`}
      style={{ color, background: `color-mix(in srgb, ${color} 12%, white)`, borderColor: color }}
      data-state={state}
    >
      <span className={css.dot} style={{ background: color }} aria-hidden="true" />
      {text}
    </span>
  );
}

/** A plain colored dot (for table density / kanban columns). */
export function StateDot({ state }: { state: string }) {
  return <span className={css.dot} style={{ background: stateColor(state) }} aria-hidden="true" />;
}

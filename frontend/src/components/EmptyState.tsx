import { ReactNode } from "react";
import css from "./EmptyState.module.css";

/** Neutral empty/error placeholder — low cognitive load, one message. */
export function EmptyState({ title, hint, children }: { title: string; hint?: string; children?: ReactNode }) {
  return (
    <div className={css.empty}>
      <div className={css.title}>{title}</div>
      {hint && <div className={css.hint}>{hint}</div>}
      {children}
    </div>
  );
}

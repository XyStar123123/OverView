import { useState } from "react";
import type { ExecutionEvent, ExecutionEventType } from "../api/types";
import { fmtDate, timeAgo } from "../lib/format";
import css from "./EventTimeline.module.css";

const EVENT_LABEL: Partial<Record<ExecutionEventType, string>> = {
  task_received: "Task received",
  agent_initialized: "Agent initialized",
  context_assembled: "Context assembled",
  memory_retrieval: "Memory retrieval",
  memory_stored: "Memory stored",
  memory_rejected: "Memory rejected",
  llm_request: "LLM request",
  llm_response: "LLM response",
  tool_requested: "Tool requested",
  tool_executed: "Tool executed",
  tool_rejected: "Tool rejected",
  approval_requested: "Approval requested",
  artifact_submitted: "Artifact submitted",
  task_status_updated: "Task status updated",
  execution_completed: "Execution completed",
  execution_failed: "Execution failed",
  error: "Error",
};

/** Vertical event timeline for an execution (contract §6: all events, collapsible payloads). */
export function EventTimeline({ events, live }: { events: ExecutionEvent[]; live?: boolean }) {
  const [open, setOpen] = useState<number | null>(null);
  return (
    <ol className={css.timeline}>
      {events.map((e, i) => {
        const failed = e.type === "execution_failed" || e.type === "error";
        const last = i === events.length - 1;
        const isOpen = open === e.id;
        return (
          <li key={`${e.sequence}-${i}`} className={css.item}>
            <div className={css.rail}>
              <span className={`${css.node} ${failed ? css.nodeFailed : ""} ${!last && live ? css.nodeLive : ""}`} aria-hidden="true" />
              {!last && <span className={css.line} aria-hidden="true" />}
            </div>
            <div className={css.body}>
              <div className={css.head}>
                <span className={css.seq}>{e.sequence}</span>
                <span className={css.type}>{EVENT_LABEL[e.type] ?? e.type}</span>
                <span className={css.time} title={fmtDate(e.createdAt)}>
                  {timeAgo(e.createdAt)}
                </span>
              </div>
              {Object.keys(e.payload).length > 0 && (
                <>
                  <button type="button" className={css.toggle} onClick={() => setOpen(isOpen ? null : e.id)}>
                    {isOpen ? "Hide payload" : "Show payload"}
                  </button>
                  {isOpen && (
                    <pre className={css.payload}>{JSON.stringify(e.payload, undefined, 2)}</pre>
                  )}
                </>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

import { ReactNode, useState } from "react";
import css from "./ConfirmDialog.module.css";

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  body?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
  onConfirm: () => void | Promise<void>;
  onClose: () => void;
}

/** Minimal modal confirm (no portal library; prototype-scale). */
export function ConfirmDialog({ open, title, body, confirmLabel = "Confirm", cancelLabel = "Cancel", danger, onConfirm, onClose }: ConfirmDialogProps) {
  const [busy, setBusy] = useState(false);
  if (!open) return null;

  const confirm = async () => {
    setBusy(true);
    try {
      await onConfirm();
      onClose();
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className={css.overlay} role="presentation">
      <div className={css.dialog} role="dialog" aria-modal="true" aria-label={title}>
        <h2 className={css.title}>{title}</h2>
        {body && <div className={css.body}>{body}</div>}
        <div className={css.actions}>
          <button type="button" className="btn" onClick={onClose} disabled={busy}>
            {cancelLabel}
          </button>
          <button type="button" className={danger ? "btn btn-danger" : "btn btn-primary"} onClick={confirm} disabled={busy}>
            {busy ? "Saving..." : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

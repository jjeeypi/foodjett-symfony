export function ConfirmDialog({
  title,
  message,
  confirmLabel = 'Confirm',
  destructive = false,
  busy = false,
  confirmDisabled = false,
  onConfirm,
  onClose,
}: {
  title: string
  message: string
  confirmLabel?: string
  destructive?: boolean
  busy?: boolean
  confirmDisabled?: boolean
  onConfirm: () => void
  onClose: () => void
}) {
  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/75 p-3 backdrop-blur-sm sm:items-center" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onClose() }}>
      <section role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-5 shadow-2xl sm:p-6">
        <h2 id="confirm-dialog-title" className="text-xl font-black text-white">{title}</h2>
        <p className="mt-3 text-sm leading-6 text-white/55">{message}</p>
        <div className="mt-6 grid grid-cols-2 gap-3">
          <button type="button" disabled={busy} onClick={onClose} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65 transition hover:border-white/30 hover:text-white disabled:opacity-50">Cancel</button>
          <button type="button" disabled={busy || confirmDisabled} onClick={onConfirm} className={`min-h-12 rounded-xl text-sm font-black transition disabled:cursor-not-allowed disabled:opacity-40 ${destructive ? 'bg-red-400 text-brand-black hover:bg-red-300' : 'bg-neon-green text-brand-black hover:bg-[#55ff35]'}`}>{busy ? 'Working…' : confirmLabel}</button>
        </div>
      </section>
    </div>
  )
}

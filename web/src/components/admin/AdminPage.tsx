import type { ReactNode } from 'react'
import { humanize } from './formatting'

export function AdminPageHeader({ eyebrow, title, description, action }: { eyebrow: string; title: string; description: string; action?: ReactNode }) {
  return <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">{eyebrow}</p><h2 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">{title}</h2><p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">{description}</p></div>{action}</header>
}

export function AdminLoadingState({ rows = 4 }: { rows?: number }) {
  return <div className="mt-5 space-y-3" aria-label="Loading" aria-busy="true">{Array.from({ length: rows }, (_, index) => <div key={index} className="h-24 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}</div>
}

export function AdminErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return <section role="alert" className="mt-5 rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-12 text-center"><h3 className="font-black text-white">Could not load this section</h3><p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-red-100/75">{message}</p><button type="button" onClick={onRetry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section>
}

export function AdminEmptyState({ title, message }: { title: string; message: string }) {
  return <section className="mt-5 rounded-2xl border border-dashed border-white/10 bg-brand-surface/60 px-5 py-14 text-center"><h3 className="font-black text-white">{title}</h3><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-white/40">{message}</p></section>
}

export function AdminStatusBadge({ value }: { value: string }) {
  const positive = ['active', 'paid', 'confirmed', 'verified', 'resolved', 'closed'].includes(value)
  const warning = ['pending', 'open', 'in_progress', 'partially_refunded'].includes(value)
  const negative = ['failed', 'rejected', 'refunded', 'suspended', 'inactive'].includes(value)
  const style = positive ? 'border-neon-green/25 bg-neon-green/[0.08] text-neon-green' : warning ? 'border-amber-300/25 bg-amber-300/[0.08] text-amber-200' : negative ? 'border-red-400/25 bg-red-400/[0.08] text-red-300' : 'border-white/10 bg-white/[0.05] text-white/60'
  return <span className={`inline-flex shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ${style}`}>{humanize(value)}</span>
}

export function AdminConfirmDialog({ title, message, confirmLabel, busy, danger = false, onCancel, onConfirm }: { title: string; message: string; confirmLabel: string; busy: boolean; danger?: boolean; onCancel: () => void; onConfirm: () => void }) {
  return <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onCancel() }}><section role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-6 shadow-2xl"><h3 className="text-xl font-black text-white">{title}</h3><p className="mt-2 text-sm leading-6 text-white/45">{message}</p><div className="mt-6 grid grid-cols-2 gap-3"><button type="button" disabled={busy} onClick={onCancel} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65 disabled:opacity-50">Cancel</button><button type="button" disabled={busy} onClick={onConfirm} className={`min-h-12 rounded-xl text-sm font-black text-brand-black disabled:opacity-50 ${danger ? 'bg-red-400' : 'bg-neon-green'}`}>{busy ? 'Saving…' : confirmLabel}</button></div></section></div>
}

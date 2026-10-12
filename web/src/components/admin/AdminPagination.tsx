import type { PaginationMeta } from '../../lib/api'

export function AdminPagination({ meta, onPage }: { meta: PaginationMeta; onPage: (page: number) => void }) {
  if (meta.lastPage <= 1) return null

  return (
    <nav className="mt-6 flex items-center justify-between gap-3" aria-label="Results pagination">
      <button type="button" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)} className="min-h-11 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/65 transition hover:border-neon-green/30 hover:text-neon-green disabled:cursor-not-allowed disabled:opacity-30">Previous</button>
      <span className="text-xs font-bold uppercase tracking-[0.12em] text-white/35">Page {meta.page} of {meta.lastPage} · {meta.total} total</span>
      <button type="button" disabled={meta.page >= meta.lastPage} onClick={() => onPage(meta.page + 1)} className="min-h-11 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/65 transition hover:border-neon-green/30 hover:text-neon-green disabled:cursor-not-allowed disabled:opacity-30">Next</button>
    </nav>
  )
}

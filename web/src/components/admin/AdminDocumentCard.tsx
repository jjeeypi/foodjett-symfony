import { apiAssetUrl } from '../../lib/api'
import type { AdminApprovalDocument } from '../../lib/api'

export function AdminDocumentCard({ document, busy = false, onVerify, onReject }: {
  document: AdminApprovalDocument
  busy?: boolean
  onVerify?: () => void
  onReject?: () => void
}) {
  const url = apiAssetUrl(document.file_path)
  const image = /\.(?:jpe?g|png|gif|webp)$/i.test(document.file_path)
  const pdf = /\.pdf$/i.test(document.file_path)
  const hasActions = Boolean(onVerify || onReject)

  return (
    <article className="overflow-hidden rounded-xl border border-white/10 bg-brand-black/45">
      <div className="flex min-h-44 items-center justify-center border-b border-white/10 bg-black/40 p-3">
        {url && image ? (
          <a href={url} target="_blank" rel="noreferrer" className="block w-full">
            <img src={url} alt={`${humanize(document.type)} preview`} loading="lazy" className="mx-auto max-h-64 w-full rounded-lg object-contain" />
          </a>
        ) : (
          <div className="text-center">
            <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04] text-xl font-black text-neon-green">{pdf ? 'PDF' : 'FILE'}</span>
            {url && <a href={url} target="_blank" rel="noreferrer" className="mt-3 inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-bold text-neon-green hover:bg-neon-green/10">Open {pdf ? 'PDF' : 'document'} ↗</a>}
          </div>
        )}
      </div>
      <div className="p-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0"><h5 className="font-black text-white">{humanize(document.type)}</h5><p className="mt-1 break-all text-[11px] text-white/30">{document.file_path.split('/').at(-1)}</p></div>
          <DocumentStatusBadge status={document.status} />
        </div>
        {document.rejection_reason && <p className="mt-3 rounded-lg bg-red-400/[0.07] px-3 py-2 text-xs leading-5 text-red-200">{document.rejection_reason}</p>}
        {hasActions && (
          <div className="mt-4 grid grid-cols-2 gap-2">
            <button type="button" disabled={busy || document.status === 'rejected' || !onReject} onClick={onReject} className="min-h-11 rounded-lg border border-red-400/25 text-xs font-black text-red-300 disabled:cursor-not-allowed disabled:opacity-35">Reject</button>
            <button type="button" disabled={busy || document.status === 'verified' || !onVerify} onClick={onVerify} className="min-h-11 rounded-lg border border-neon-green/30 bg-neon-green/[0.07] text-xs font-black text-neon-green disabled:cursor-not-allowed disabled:opacity-35">Verify</button>
          </div>
        )}
      </div>
    </article>
  )
}

function DocumentStatusBadge({ status }: { status: AdminApprovalDocument['status'] }) {
  const style = status === 'verified' ? 'bg-neon-green/[0.08] text-neon-green' : status === 'rejected' ? 'bg-red-400/[0.08] text-red-300' : 'bg-amber-300/[0.08] text-amber-200'
  return <span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ${style}`}>{status}</span>
}

function humanize(value: string): string {
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

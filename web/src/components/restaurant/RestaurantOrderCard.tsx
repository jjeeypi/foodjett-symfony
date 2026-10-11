import type { RestaurantOrderSummary } from '../../lib/api'

export type RestaurantOrderAction = 'accept' | 'reject' | 'extend' | 'ready'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

function timeAgo(isoDate: string, now: number): string {
  const elapsedMinutes = Math.max(0, Math.floor((now - new Date(isoDate).getTime()) / 60_000))
  if (elapsedMinutes < 1) return 'Just now'
  if (elapsedMinutes < 60) return `${elapsedMinutes}m ago`
  const hours = Math.floor(elapsedMinutes / 60)
  if (hours < 24) return `${hours}h ${elapsedMinutes % 60}m ago`
  return `${Math.floor(hours / 24)}d ago`
}

function statusLabel(order: RestaurantOrderSummary): string {
  if (order.ready_at) return 'Ready for pickup'
  return order.status.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

export function RestaurantOrderCard({
  order,
  now,
  highlighted,
  busy,
  onAction,
}: {
  order: RestaurantOrderSummary
  now: number
  highlighted: boolean
  busy: boolean
  onAction: (action: RestaurantOrderAction, order: RestaurantOrderSummary) => void
}) {
  const canMarkReady = !order.ready_at && ['finding_rider', 'rider_assigned', 'at_restaurant'].includes(order.status)
  const canExtend = !order.ready_at && Boolean(order.estimated_ready_at)

  return (
    <article className={`relative overflow-hidden rounded-2xl border bg-brand-surface p-4 transition duration-500 sm:p-5 ${highlighted ? 'border-neon-green shadow-[0_0_32px_rgba(57,255,20,0.2)]' : 'border-white/10 hover:border-white/20'}`}>
      {highlighted && <span className="absolute right-0 top-0 rounded-bl-xl bg-neon-green px-3 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-brand-black">New</span>}
      <header className="flex items-start justify-between gap-3 pr-10">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.14em] text-neon-green">{order.order_number}</p>
          <h3 className="mt-1 text-lg font-black text-white">{order.customer_name}</h3>
        </div>
        <div className="text-right">
          <p className="text-base font-black text-white">{peso.format(Number(order.total_amount))}</p>
          <p className="mt-1 text-xs text-white/40">{timeAgo(order.placed_at, now)}</p>
        </div>
      </header>

      <div className="mt-4 space-y-2 border-y border-white/[0.07] py-4">
        {order.items.length > 0 ? order.items.map((item) => (
          <div key={item.id} className="flex gap-3 text-sm">
            <span className="font-black text-neon-green">{item.quantity}×</span>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-white/90">{item.name}{item.variant ? <span className="font-normal text-white/45"> · {item.variant.name}</span> : null}</p>
              {item.addons.length > 0 && <p className="mt-0.5 text-xs text-white/40">Add: {item.addons.map((addon) => addon.name).join(', ')}</p>}
              {item.special_instructions && <p className="mt-1 rounded-lg bg-amber-400/[0.08] px-2.5 py-1.5 text-xs text-amber-200">Item note: {item.special_instructions}</p>}
            </div>
          </div>
        )) : <p className="text-sm text-white/40">No item details available.</p>}
      </div>

      {order.customer_notes && (
        <div className="mt-3 rounded-xl border border-amber-300/15 bg-amber-300/[0.06] px-3 py-2.5">
          <p className="text-[10px] font-black uppercase tracking-[0.14em] text-amber-200/70">Customer note</p>
          <p className="mt-1 text-sm leading-5 text-amber-100">{order.customer_notes}</p>
        </div>
      )}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs">
        <span className="rounded-full bg-white/[0.06] px-2.5 py-1 font-bold text-white/55">{statusLabel(order)}</span>
        <span className="font-semibold uppercase tracking-wide text-white/35">{order.payment_method}</span>
      </div>

      {order.status === 'placed' && (
        <div className="mt-4 grid grid-cols-2 gap-2">
          <button type="button" disabled={busy} onClick={() => onAction('reject', order)} className="min-h-12 rounded-xl border border-red-400/25 bg-red-400/[0.06] text-sm font-bold text-red-200 transition hover:bg-red-400/10 disabled:opacity-50">Reject</button>
          <button type="button" disabled={busy} onClick={() => onAction('accept', order)} className="min-h-12 rounded-xl bg-neon-green text-sm font-black text-brand-black transition hover:bg-[#55ff35] disabled:opacity-50">Accept order</button>
        </div>
      )}

      {(canExtend || canMarkReady) && (
        <div className="mt-4 grid grid-cols-2 gap-2">
          {canExtend && <button type="button" disabled={busy} onClick={() => onAction('extend', order)} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/70 transition hover:border-white/30 hover:text-white disabled:opacity-50">Extend prep</button>}
          {canMarkReady && <button type="button" disabled={busy} onClick={() => onAction('ready', order)} className="min-h-12 rounded-xl bg-neon-green text-sm font-black text-brand-black transition hover:bg-[#55ff35] disabled:opacity-50">Mark ready</button>}
        </div>
      )}
    </article>
  )
}

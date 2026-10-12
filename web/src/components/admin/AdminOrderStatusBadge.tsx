import type { OrderStatus } from '../../lib/api'
import { humanize } from './formatting'

export function AdminOrderStatusBadge({ status }: { status: OrderStatus }) {
  const terminal = status === 'delivered' || status.startsWith('cancelled_') || status === 'rejected_by_restaurant' || status === 'failed_delivery'
  const delivery = ['rider_assigned', 'at_restaurant', 'picked_up', 'on_the_way', 'arrived'].includes(status)
  const style = status === 'delivered'
    ? 'border-neon-green/25 bg-neon-green/[0.08] text-neon-green'
    : terminal
      ? 'border-red-400/25 bg-red-400/[0.08] text-red-300'
      : delivery
        ? 'border-cyan-300/25 bg-cyan-300/[0.08] text-cyan-200'
        : 'border-amber-300/25 bg-amber-300/[0.08] text-amber-200'

  return <span className={`inline-flex shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ${style}`}>{humanize(status)}</span>
}

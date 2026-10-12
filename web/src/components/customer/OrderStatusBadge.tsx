import type { OrderStatus } from '../../lib/api'
import { orderStatusLabel } from '../../lib/orderStatus'

const activeStatuses = new Set<OrderStatus>([
  'placed',
  'accepted',
  'preparing',
  'ready',
  'finding_rider',
  'rider_assigned',
  'at_restaurant',
  'picked_up',
  'on_the_way',
  'arrived',
])

export function OrderStatusBadge({ status }: { status: OrderStatus }) {
  const className = status === 'delivered'
    ? 'border-neon-green/25 bg-neon-green/[0.08] text-neon-green'
    : activeStatuses.has(status)
      ? 'border-sky-300/20 bg-sky-300/[0.07] text-sky-200'
      : 'border-red-300/20 bg-red-300/[0.07] text-red-200'

  return (
    <span className={`inline-flex min-h-7 items-center rounded-full border px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.09em] ${className}`}>
      {orderStatusLabel(status)}
    </span>
  )
}

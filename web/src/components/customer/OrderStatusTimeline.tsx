import type { OrderStatus } from '../../lib/api'
import { terminalOrderLabels } from '../../lib/orderStatus'

const progressStatuses: Array<{ status: OrderStatus; label: string; description: string }> = [
  { status: 'placed', label: 'Order placed', description: 'Waiting for the restaurant to confirm.' },
  { status: 'accepted', label: 'Accepted', description: 'The restaurant accepted your order.' },
  { status: 'preparing', label: 'Preparing', description: 'Your food is being prepared.' },
  { status: 'ready', label: 'Ready for pickup', description: 'Your order is packed and ready.' },
  { status: 'finding_rider', label: 'Finding a rider', description: 'We are matching your order with a rider.' },
  { status: 'rider_assigned', label: 'Rider assigned', description: 'A rider is heading to the restaurant.' },
  { status: 'at_restaurant', label: 'At the restaurant', description: 'Your rider is collecting the order.' },
  { status: 'picked_up', label: 'Picked up', description: 'Your rider has your order.' },
  { status: 'on_the_way', label: 'On the way', description: 'Your order is travelling to you.' },
  { status: 'arrived', label: 'Rider arrived', description: 'Please meet your rider.' },
  { status: 'delivered', label: 'Delivered', description: 'Enjoy your meal!' },
]

export function OrderStatusTimeline({ status }: { status: OrderStatus }) {
  const currentIndex = progressStatuses.findIndex((step) => step.status === status)
  const terminalLabel = terminalOrderLabels[status]

  return (
    <div>
      {terminalLabel && (
        <div className="mb-5 rounded-2xl border border-red-400/25 bg-red-500/8 px-4 py-3">
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-red-300">Order closed</p>
          <p className="mt-1 font-semibold text-white">{terminalLabel}</p>
        </div>
      )}

      <ol aria-label="Order progress" className="space-y-0">
        {progressStatuses.map((step, index) => {
          const complete = !terminalLabel && currentIndex >= index
          const current = step.status === status

          return (
            <li key={step.status} className="relative grid grid-cols-[2rem_1fr] gap-3 pb-5 last:pb-0">
              {index < progressStatuses.length - 1 && <span aria-hidden="true" className={`absolute left-[0.9375rem] top-7 h-[calc(100%-0.5rem)] w-px ${complete && !current ? 'bg-neon-green/70' : 'bg-white/12'}`} />}
              <span aria-hidden="true" className={`relative z-10 mt-0.5 flex h-8 w-8 items-center justify-center rounded-full border text-xs font-black transition ${current ? 'border-neon-green bg-neon-green text-brand-black shadow-[0_0_16px_rgba(57,255,20,0.3)]' : complete ? 'border-neon-green/65 bg-neon-green/10 text-neon-green' : 'border-white/15 bg-brand-surface-2 text-white/25'}`}>
                {complete ? '✓' : index + 1}
              </span>
              <div className="pt-0.5">
                <p className={`text-sm font-bold ${current ? 'text-neon-green' : complete ? 'text-white' : 'text-white/35'}`}>{step.label}</p>
                {(current || (index === 0 && currentIndex < 0)) && <p className="mt-1 text-xs leading-5 text-white/50">{step.description}</p>}
              </div>
            </li>
          )
        })}
      </ol>
    </div>
  )
}

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
  const showPickupCode = Boolean(order.pickup_code) && ['rider_assigned', 'at_restaurant'].includes(order.status)

  return (
    <article className={`fj-order-card ${highlighted ? 'is-highlighted' : ''}`}>
      {highlighted && <span className="fj-order-card__new">New</span>}
      <header className="fj-order-card__header">
        <div>
          <p>{order.order_number}</p>
          <h3>{order.customer_name}</h3>
        </div>
        <div>
          <strong>{peso.format(Number(order.total_amount))}</strong>
          <small>{timeAgo(order.placed_at, now)}</small>
        </div>
      </header>

      <div className="fj-order-card__items">
        {order.items.length > 0 ? order.items.map((item) => (
          <div key={item.id} className="fj-order-card__item">
            <span>{item.quantity}×</span>
            <div>
              <p>{item.name}{item.variant ? <em> · {item.variant.name}</em> : null}</p>
              {item.addons.length > 0 && <small>Add: {item.addons.map((addon) => addon.name).join(', ')}</small>}
              {item.special_instructions && <mark>Item note: {item.special_instructions}</mark>}
            </div>
          </div>
        )) : <p className="fj-order-card__muted">No item details available.</p>}
      </div>

      {order.customer_notes && (
        <div className="fj-order-card__note">
          <strong>Customer note</strong>
          <p>{order.customer_notes}</p>
        </div>
      )}

      {showPickupCode && (
        <div className="fj-order-card__pickup" aria-label={`Pickup code ${order.pickup_code}`}>
          <p>Pickup code · give this to the rider</p>
          <strong>{order.pickup_code}</strong>
        </div>
      )}

      <div className="fj-order-card__meta">
        <span>{statusLabel(order)}</span>
        <small>{order.payment_method}</small>
      </div>

      {order.status === 'placed' && (
        <div className="fj-order-card__actions">
          <button type="button" disabled={busy} onClick={() => onAction('reject', order)} className="is-danger">Reject</button>
          <button type="button" disabled={busy} onClick={() => onAction('accept', order)} className="is-primary">Accept order</button>
        </div>
      )}

      {(canExtend || canMarkReady) && (
        <div className="fj-order-card__actions">
          {canExtend && <button type="button" disabled={busy} onClick={() => onAction('extend', order)} className="is-secondary">Extend prep</button>}
          {canMarkReady && <button type="button" disabled={busy} onClick={() => onAction('ready', order)} className="is-primary">Mark ready</button>}
        </div>
      )}
    </article>
  )
}

import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { OrderStatusTimeline } from '../components/customer/OrderStatusTimeline'
import { LiveRiderMap } from '../components/customer/LiveRiderMap'
import {
  apiAssetUrl,
  authorizeMercure,
  cancelCustomerOrder,
  getCustomerOrder,
  isApiError,
  resolveOrderConversation,
  type CheckoutOrder,
  type CustomerOrderDetail,
  type OrderStatus,
} from '../lib/api'
import { isTerminalOrderStatus, orderStatusLabel } from '../lib/orderStatus'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
const FALLBACK_POLL_MS = 15_000

type ConnectionState = 'connecting' | 'live' | 'disconnected' | 'finished'

interface OrderRealtimePayload {
  event?: string
  order_id?: string
  status?: OrderStatus
  estimated_ready_at?: string | null
  rider?: CustomerOrderDetail['rider']
  latitude?: string
  longitude?: string
  timestamp?: string
}

const LIVE_RIDER_MAP_STATUSES: OrderStatus[] = ['rider_assigned', 'at_restaurant', 'picked_up', 'on_the_way', 'arrived']

function displayDate(value: string | null): string {
  return value ? dateTime.format(new Date(value)) : '—'
}

function OrderSkeleton() {
  return (
    <div className="animate-pulse space-y-5" aria-label="Loading order">
      <div className="h-32 rounded-3xl bg-white/[0.055]" />
      <div className="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(19rem,0.75fr)]">
        <div className="h-[34rem] rounded-3xl bg-white/[0.055]" />
        <div className="h-[26rem] rounded-3xl bg-white/[0.055]" />
      </div>
    </div>
  )
}

function OrderPlacedBanner({ order }: { order: Pick<CheckoutOrder, 'id' | 'order_number' | 'payment_method' | 'total_amount'> }) {
  const paidOnline = order.payment_method === 'gcash' || order.payment_method === 'card'

  return (
    <section className="overflow-hidden rounded-3xl border border-neon-green/25 bg-[radial-gradient(circle_at_top_right,rgba(57,255,20,0.14),transparent_42%),#111111] px-5 py-6 sm:px-7">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-4">
          <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-neon-green text-xl font-black text-brand-black">✓</span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-neon-green">Order #{order.order_number}</p>
            <h1 className="mt-1 text-2xl font-black text-white">{paidOnline ? 'Payment Successful' : 'Order placed!'}</h1>
            <p className="mt-1 text-sm text-white/55">{paidOnline ? 'Your simulated payment was accepted instantly.' : 'Your restaurant has received the order.'}</p>
          </div>
        </div>
        <strong className="text-xl text-white">{currency.format(Number(order.total_amount))}</strong>
      </div>
    </section>
  )
}

function ConnectionBadge({ state, onRefresh, refreshing }: { state: ConnectionState; onRefresh: () => void; refreshing: boolean }) {
  if (state === 'finished') return <span className="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold text-white/50">Completed</span>
  if (state === 'live') return <span className="inline-flex items-center gap-2 rounded-full border border-neon-green/25 bg-neon-green/8 px-3 py-1.5 text-xs font-bold text-neon-green"><span className="h-2 w-2 rounded-full bg-neon-green shadow-[0_0_8px_rgba(57,255,20,0.75)]" />Live</span>
  if (state === 'connecting') return <span className="rounded-full border border-amber-300/20 bg-amber-300/8 px-3 py-1.5 text-xs font-semibold text-amber-200">Connecting…</span>

  return (
    <button type="button" onClick={onRefresh} disabled={refreshing} className="inline-flex min-h-10 items-center gap-2 rounded-full border border-amber-300/25 bg-amber-300/8 px-3 text-xs font-bold text-amber-100 transition hover:border-amber-300/50 disabled:opacity-50">
      <span className="h-2 w-2 rounded-full bg-amber-300" />{refreshing ? 'Refreshing…' : 'Offline — refresh'}
    </button>
  )
}

export default function OrderTrackingPage() {
  const { id = '' } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const checkoutOrder = (location.state as { order?: CheckoutOrder } | null)?.order
  const [order, setOrder] = useState<CustomerOrderDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<{ message: string; status: number } | null>(null)
  const [connection, setConnection] = useState<ConnectionState>('connecting')
  const [cancelOpen, setCancelOpen] = useState(false)
  const [cancelReason, setCancelReason] = useState('Changed my mind')
  const [cancelling, setCancelling] = useState(false)
  const [cancelError, setCancelError] = useState('')
  const [openingChat, setOpeningChat] = useState(false)
  const [chatError, setChatError] = useState('')
  const eventSourceRef = useRef<EventSource | null>(null)

  const loadOrder = useCallback(async (silent = false) => {
    if (!id) return
    if (silent) setRefreshing(true)

    try {
      const response = await getCustomerOrder(id)
      setOrder(response.order)
      setError(null)
    } catch (requestError) {
      const normalized = isApiError(requestError)
        ? requestError
        : { message: 'We could not load this order. Please try again.', status: 0 }
      setError({ message: normalized.message, status: normalized.status })
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [id])

  useEffect(() => {
    if (!id) return
    const controller = new AbortController()

    void getCustomerOrder(id, controller.signal).then((response) => {
      setOrder(response.order)
      setError(null)
    }).catch((requestError: unknown) => {
      if (requestError instanceof DOMException && requestError.name === 'AbortError') return
      const normalized = isApiError(requestError)
        ? requestError
        : { message: 'We could not load this order. Please try again.', status: 0 }
      setError({ message: normalized.message, status: normalized.status })
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })

    return () => controller.abort()
  }, [id])

  const orderId = order?.id
  const orderStatus = order?.status

  useEffect(() => {
    if (!orderId || !orderStatus || isTerminalOrderStatus(orderStatus)) {
      eventSourceRef.current?.close()
      eventSourceRef.current = null
      return
    }

    let cancelled = false
    let source: EventSource | null = null
    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      const topic = `orders/${orderId}/status`
      if (!authorization.topics.includes(topic)) throw new Error('This order topic was not authorized.')

      const hubUrl = new URL(authorization.hub_url)
      hubUrl.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(hubUrl.toString(), { withCredentials: true })
      eventSourceRef.current = source

      source.onopen = () => setConnection('live')
      source.onerror = () => setConnection('disconnected')

      const receive = (rawEvent: Event) => {
        const event = rawEvent as MessageEvent<string>
        try {
          const payload = JSON.parse(event.data) as OrderRealtimePayload
          if (payload.order_id !== orderId) return
          if (payload.event === 'rider.location_updated') {
            if (payload.latitude === undefined || payload.longitude === undefined) return
            setOrder((current) => current?.rider ? {
              ...current,
              rider: {
                ...current.rider,
                current_latitude: payload.latitude ?? current.rider.current_latitude,
                current_longitude: payload.longitude ?? current.rider.current_longitude,
                last_location_at: payload.timestamp ?? current.rider.last_location_at,
              },
            } : current)
            return
          }
          setOrder((current) => current ? {
            ...current,
            status: payload.status ?? current.status,
            estimated_ready_at: payload.estimated_ready_at === undefined ? current.estimated_ready_at : payload.estimated_ready_at,
            rider: payload.rider === undefined ? current.rider : payload.rider,
          } : current)
          void loadOrder(true)
        } catch {
          setConnection('disconnected')
        }
      }

      source.addEventListener('order.status_changed', receive)
      source.addEventListener('order.updated', receive)
      source.addEventListener('rider.location_updated', receive)
    }).catch(() => {
      if (!cancelled) setConnection('disconnected')
    })

    return () => {
      cancelled = true
      source?.close()
      if (eventSourceRef.current === source) eventSourceRef.current = null
    }
  }, [loadOrder, orderId, orderStatus])

  useEffect(() => {
    if (connection !== 'disconnected' || !orderStatus || isTerminalOrderStatus(orderStatus)) return
    const interval = window.setInterval(() => void loadOrder(true), FALLBACK_POLL_MS)
    return () => window.clearInterval(interval)
  }, [connection, loadOrder, orderStatus])

  async function confirmCancellation() {
    if (!order || !cancelReason.trim()) return
    setCancelling(true)
    setCancelError('')
    try {
      await cancelCustomerOrder(order.id, cancelReason.trim())
      setCancelOpen(false)
      await loadOrder(true)
    } catch (requestError) {
      setCancelError(isApiError(requestError) ? requestError.message : 'The order could not be cancelled.')
    } finally {
      setCancelling(false)
    }
  }

  async function messageRider() {
    if (!order?.rider || openingChat) return
    setOpeningChat(true)
    setChatError('')
    try {
      const response = await resolveOrderConversation(order.id, 'customer_rider')
      navigate(`/messages/${response.conversation.id}`)
    } catch (requestError) {
      setChatError(isApiError(requestError) ? requestError.message : 'The rider conversation could not be opened.')
    } finally {
      setOpeningChat(false)
    }
  }

  if (loading && !order) {
    return (
      <div className="mx-auto max-w-6xl space-y-5">
        {checkoutOrder && <OrderPlacedBanner order={checkoutOrder} />}
        <OrderSkeleton />
      </div>
    )
  }

  if (error && !order) {
    const unavailable = error.status === 403 || error.status === 404
    return (
      <section className="mx-auto max-w-xl rounded-3xl border border-white/10 bg-brand-surface px-5 py-12 text-center sm:px-9">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-red-400/20 bg-red-500/8 text-2xl text-red-300">!</div>
        <h1 className="mt-5 text-2xl font-black text-white">{unavailable ? 'Order unavailable' : 'Could not load order'}</h1>
        <p className="mt-2 text-sm leading-6 text-white/50">{unavailable ? 'This order does not exist or does not belong to your account.' : error.message}</p>
        <div className="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
          {!unavailable && <button type="button" onClick={() => { setLoading(true); void loadOrder() }} className="min-h-12 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button>}
          <Link to="/" className="inline-flex min-h-12 items-center justify-center rounded-xl border border-white/15 px-5 text-sm font-semibold text-white/70">Back to home</Link>
        </div>
      </section>
    )
  }

  if (!order) return null

  const logoUrl = apiAssetUrl(order.restaurant.logo_path)
  const cancelled = order.status.startsWith('cancelled_') || order.status === 'rejected_by_restaurant' || order.status === 'failed_delivery'
  const showLiveRiderMap = order.rider !== null && LIVE_RIDER_MAP_STATUSES.includes(order.status)

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      {checkoutOrder && checkoutOrder.id === order.id && (
        <OrderPlacedBanner order={order} />
      )}

      <header className="flex flex-col gap-4 rounded-3xl border border-white/10 bg-brand-surface px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
        <div className="flex min-w-0 items-center gap-4">
          <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white/[0.04]">
            {logoUrl ? <img src={logoUrl} alt="" className="h-full w-full object-cover" /> : <span className="text-lg font-black text-neon-green">FJ</span>}
          </div>
          <div className="min-w-0">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Order #{order.order_number}</p>
            <h1 className="mt-1 truncate text-2xl font-black text-white sm:text-3xl">{order.restaurant.name}</h1>
            <p className="mt-1 text-sm font-semibold text-white/50">{orderStatusLabel(order.status)} · {displayDate(order.placed_at)}</p>
          </div>
        </div>
        <ConnectionBadge state={isTerminalOrderStatus(order.status) ? 'finished' : connection} onRefresh={() => void loadOrder(true)} refreshing={refreshing} />
      </header>

      {connection === 'disconnected' && !isTerminalOrderStatus(order.status) && (
        <div role="status" className="rounded-2xl border border-amber-300/20 bg-amber-300/8 px-4 py-3 text-sm text-amber-100">
          Live updates are temporarily disconnected. This page is refreshing automatically every 15 seconds; you can also refresh now.
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(20rem,0.8fr)] lg:items-start">
        <div className="space-y-5">
          <section className="rounded-3xl border border-white/10 bg-brand-surface p-5 sm:p-7">
            <div className="mb-6 flex items-start justify-between gap-3">
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.17em] text-neon-green">Live progress</p>
                <h2 className="mt-1 text-xl font-black text-white">Track your order</h2>
              </div>
              {order.estimated_ready_at && !isTerminalOrderStatus(order.status) && (
                <div className="rounded-xl border border-white/10 bg-white/[0.035] px-3 py-2 text-right">
                  <p className="text-[10px] font-bold uppercase tracking-wider text-white/35">Estimated ready</p>
                  <p className="mt-0.5 text-sm font-bold text-white">{displayDate(order.estimated_ready_at)}</p>
                </div>
              )}
            </div>
            <OrderStatusTimeline status={order.status} />
          </section>

          {order.rider && (
            <section className="rounded-3xl border border-neon-green/20 bg-neon-green/[0.045] p-5 sm:p-6">
              <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Your rider</p>
              <div className="mt-3 flex items-center justify-between gap-4">
                <div>
                  <h2 className="text-lg font-black text-white">{order.rider.name}</h2>
                  <p className="mt-1 text-sm capitalize text-white/50">{order.rider.vehicle_type.replaceAll('_', ' ')}</p>
                </div>
                <button type="button" onClick={() => void messageRider()} disabled={openingChat} className="inline-flex min-h-11 items-center justify-center rounded-xl border border-neon-green/35 px-4 text-sm font-bold text-neon-green transition hover:bg-neon-green hover:text-brand-black disabled:cursor-wait disabled:opacity-60">{openingChat ? 'Opening…' : 'Message rider'}</button>
              </div>
              {chatError && <p role="alert" className="mt-3 text-sm text-red-200">{chatError}</p>}
            </section>
          )}

          {showLiveRiderMap && order.rider && (
            <LiveRiderMap
              riderLatitude={order.rider.current_latitude}
              riderLongitude={order.rider.current_longitude}
              destinationLatitude={order.delivery_address.latitude}
              destinationLongitude={order.delivery_address.longitude}
              lastLocationAt={order.rider.last_location_at}
            />
          )}

          <section className="rounded-3xl border border-white/10 bg-brand-surface p-5 sm:p-7">
            <div className="flex items-end justify-between gap-4">
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Items</p>
                <h2 className="mt-1 text-xl font-black text-white">Order summary</h2>
              </div>
              <span className="text-sm text-white/40">{order.item_count} item{order.item_count === 1 ? '' : 's'}</span>
            </div>
            <div className="mt-5 divide-y divide-white/8">
              {order.items.map((item) => (
                <div key={item.id} className="flex gap-4 py-4 first:pt-0 last:pb-0">
                  <span className="flex h-8 min-w-8 items-center justify-center rounded-lg bg-neon-green/10 text-xs font-black text-neon-green">{item.quantity}×</span>
                  <div className="min-w-0 flex-1">
                    <p className="font-bold text-white">{item.name}</p>
                    {item.variant && <p className="mt-0.5 text-xs text-white/45">{item.variant.name}</p>}
                    {item.addons.length > 0 && <p className="mt-0.5 text-xs text-white/45">Add-ons: {item.addons.map((addon) => addon.name).join(', ')}</p>}
                    {item.special_instructions && <p className="mt-1 text-xs italic text-white/35">“{item.special_instructions}”</p>}
                  </div>
                  <span className="text-sm font-bold text-white">{currency.format(Number(item.unit_price) * item.quantity)}</span>
                </div>
              ))}
            </div>
          </section>
        </div>

        <aside className="space-y-5 lg:sticky lg:top-28">
          <section className="rounded-3xl border border-white/10 bg-brand-surface p-5 sm:p-6">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Payment</p>
            <h2 className="mt-1 text-lg font-black capitalize text-white">{order.payment_method === 'cod' ? 'Cash on Delivery' : order.payment_method}</h2>
            {order.payment_method === 'cod' ? (
              <p className="mt-3 rounded-xl border border-amber-300/15 bg-amber-300/8 px-3 py-3 text-sm leading-5 text-amber-100">Pay the rider {currency.format(Number(order.total_amount))} on arrival. Payment will show as paid after cash collection is confirmed.</p>
            ) : (
              <p className="mt-2 text-sm text-white/50">Simulated payment status: <strong className="capitalize text-neon-green">{order.payment?.status ?? 'paid'}</strong></p>
            )}
            <dl className="mt-5 space-y-3 border-t border-white/8 pt-4 text-sm">
              <div className="flex justify-between gap-4 text-white/50"><dt>Subtotal</dt><dd>{currency.format(Number(order.subtotal))}</dd></div>
              <div className="flex justify-between gap-4 text-white/50"><dt>Delivery fee</dt><dd>{currency.format(Number(order.delivery_fee))}</dd></div>
              {Number(order.service_fee) > 0 && <div className="flex justify-between gap-4 text-white/50"><dt>Service fee</dt><dd>{currency.format(Number(order.service_fee))}</dd></div>}
              {Number(order.discount_amount) > 0 && <div className="flex justify-between gap-4 text-neon-green"><dt>Discount</dt><dd>−{currency.format(Number(order.discount_amount))}</dd></div>}
              {Number(order.tip_amount) > 0 && <div className="flex justify-between gap-4 text-white/50"><dt>Rider tip</dt><dd>{currency.format(Number(order.tip_amount))}</dd></div>}
              <div className="flex justify-between gap-4 border-t border-white/8 pt-3 text-base font-black text-white"><dt>Total</dt><dd>{currency.format(Number(order.total_amount))}</dd></div>
            </dl>
          </section>

          <section className="rounded-3xl border border-white/10 bg-brand-surface p-5 sm:p-6">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Delivery address</p>
            <h2 className="mt-2 font-black text-white">{order.delivery_address.label}</h2>
            <p className="mt-1 text-sm leading-6 text-white/55">{order.delivery_address.address_line}</p>
            {order.delivery_address.landmark && <p className="mt-2 text-xs text-white/40">Landmark: {order.delivery_address.landmark}</p>}
            {order.delivery_address.delivery_instructions && <p className="mt-2 rounded-xl bg-white/[0.035] px-3 py-2 text-xs leading-5 text-white/55">{order.delivery_address.delivery_instructions}</p>}
          </section>

          {cancelled && order.cancellation_reason && <div className="rounded-2xl border border-red-400/20 bg-red-500/8 px-4 py-3 text-sm text-red-100"><strong>Reason:</strong> {order.cancellation_reason}</div>}

          {order.can_cancel && (
            <button type="button" onClick={() => setCancelOpen(true)} className="min-h-12 w-full rounded-xl border border-red-400/30 text-sm font-bold text-red-200 transition hover:bg-red-500/10">Cancel order</button>
          )}
          <Link to="/" className="inline-flex min-h-11 w-full items-center justify-center text-sm font-semibold text-white/45 transition hover:text-white">Back to restaurants</Link>
        </aside>
      </div>

      {cancelOpen && (
        <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/75 p-0 backdrop-blur-sm sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="cancel-order-title" onMouseDown={(event) => { if (event.target === event.currentTarget && !cancelling) setCancelOpen(false) }}>
          <div className="w-full max-w-md rounded-t-3xl border border-white/12 bg-brand-surface p-5 shadow-2xl sm:rounded-3xl sm:p-6">
            <h2 id="cancel-order-title" className="text-xl font-black text-white">Cancel this order?</h2>
            <p className="mt-2 text-sm leading-6 text-white/50">The restaurant will be notified. This cannot be undone.</p>
            <label htmlFor="cancel-reason" className="mt-5 block text-sm font-semibold text-white/75">Reason</label>
            <textarea id="cancel-reason" value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} maxLength={2000} rows={3} className="mt-2 w-full resize-none rounded-xl border border-white/15 bg-brand-surface-2 px-3 py-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-red-300 focus:ring-2 focus:ring-red-300/15" />
            {cancelError && <p role="alert" className="mt-2 text-sm text-red-300">{cancelError}</p>}
            <div className="mt-5 grid grid-cols-2 gap-3">
              <button type="button" onClick={() => setCancelOpen(false)} disabled={cancelling} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/70 disabled:opacity-50">Keep order</button>
              <button type="button" onClick={() => void confirmCancellation()} disabled={cancelling || !cancelReason.trim()} className="min-h-12 rounded-xl bg-red-500 px-4 text-sm font-black text-white transition hover:bg-red-400 disabled:cursor-not-allowed disabled:opacity-50">{cancelling ? 'Cancelling…' : 'Yes, cancel'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

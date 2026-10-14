import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { RestaurantOrderCard } from '../../components/restaurant/RestaurantOrderCard'
import type { RestaurantOrderAction } from '../../components/restaurant/RestaurantOrderCard'
import type { RestaurantOutletContext } from '../../layouts/RestaurantLayout'
import {
  acceptRestaurantOrder,
  authorizeMercure,
  extendRestaurantOrderPrepTime,
  getRestaurantDashboard,
  getRestaurantOrders,
  isApiError,
  markRestaurantOrderReady,
  rejectRestaurantOrder,
} from '../../lib/api'
import type { RestaurantDashboard, RestaurantOrderSummary } from '../../lib/api'

type QueueTab = 'active' | 'history'
type ConnectionState = 'connecting' | 'live' | 'reconnecting'
type DialogState = { action: Exclude<RestaurantOrderAction, 'ready'>; order: RestaurantOrderSummary } | null

const activeStatuses = new Set(['placed', 'accepted', 'preparing', 'ready', 'finding_rider', 'rider_assigned', 'at_restaurant'])
const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

function playNewOrderTone(): void {
  try {
    const AudioContextClass = window.AudioContext
    const context = new AudioContextClass()
    const gain = context.createGain()
    gain.gain.setValueAtTime(0.0001, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.16, context.currentTime + 0.015)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.42)
    gain.connect(context.destination)
    const oscillator = context.createOscillator()
    oscillator.type = 'sine'
    oscillator.frequency.setValueAtTime(660, context.currentTime)
    oscillator.frequency.setValueAtTime(880, context.currentTime + 0.16)
    oscillator.connect(gain)
    oscillator.start()
    oscillator.stop(context.currentTime + 0.44)
    oscillator.addEventListener('ended', () => void context.close(), { once: true })
  } catch {
    // The highlighted card and toast remain available when a browser blocks audio.
  }
}

function queueFor(order: RestaurantOrderSummary): 'new' | 'preparing' | 'ready' | 'history' {
  if (!activeStatuses.has(order.status)) return 'history'
  if (order.status === 'placed') return 'new'
  if (order.status === 'ready' || order.ready_at) return 'ready'
  return 'preparing'
}

export function RestaurantDashboardPage() {
  const { restaurant } = useOutletContext<RestaurantOutletContext>()
  const eventSourceRef = useRef<EventSource | null>(null)
  const highlightTimerRef = useRef<number | null>(null)
  const [orders, setOrders] = useState<RestaurantOrderSummary[]>([])
  const [dashboard, setDashboard] = useState<RestaurantDashboard | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [actionError, setActionError] = useState('')
  const [busyOrderId, setBusyOrderId] = useState<string | null>(null)
  const [dialog, setDialog] = useState<DialogState>(null)
  const [dialogValue, setDialogValue] = useState('')
  const [tab, setTab] = useState<QueueTab>('active')
  const [connection, setConnection] = useState<ConnectionState>('connecting')
  const [highlightedOrderId, setHighlightedOrderId] = useState<string | null>(null)
  const [newOrderNotice, setNewOrderNotice] = useState('')
  const [now, setNow] = useState(Date.now())

  const loadQueue = useCallback(async (signal?: AbortSignal, silent = false) => {
    try {
      const [orderResponse, dashboardResponse] = await Promise.all([
        getRestaurantOrders(signal),
        getRestaurantDashboard(signal),
      ])
      setOrders(orderResponse.data)
      setDashboard(dashboardResponse)
    } catch (requestError) {
      if (signal?.aborted) return
      if (!silent) setError(isApiError(requestError) ? requestError.message : 'Unable to load the restaurant order queue.')
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      void loadQueue(controller.signal).finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    })
    return () => controller.abort()
  }, [loadQueue])

  useEffect(() => {
    const interval = window.setInterval(() => setNow(Date.now()), 30_000)
    return () => window.clearInterval(interval)
  }, [])

  useEffect(() => {
    let cancelled = false
    let source: EventSource | null = null
    const topic = `restaurant/${restaurant.id}/orders`

    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      if (!authorization.topics.includes(topic)) throw new Error('Restaurant order topic was not authorized.')
      const hubUrl = new URL(authorization.hub_url)
      hubUrl.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(hubUrl.toString(), { withCredentials: true })
      eventSourceRef.current = source
      source.onopen = () => setConnection('live')
      source.onerror = () => setConnection('reconnecting')
      source.addEventListener('order.placed', (rawEvent) => {
        const event = rawEvent as MessageEvent<string>
        try {
          const payload = JSON.parse(event.data) as { order_id?: string; order_number?: string }
          if (!payload.order_id) return
          setHighlightedOrderId(payload.order_id)
          setNewOrderNotice(`New order ${payload.order_number ?? ''} is waiting for your response.`)
          playNewOrderTone()
          if (highlightTimerRef.current) window.clearTimeout(highlightTimerRef.current)
          highlightTimerRef.current = window.setTimeout(() => setHighlightedOrderId(null), 12_000)
          void loadQueue(undefined, true)
        } catch {
          setConnection('reconnecting')
        }
      })
    }).catch(() => {
      if (!cancelled) setConnection('reconnecting')
    })

    return () => {
      cancelled = true
      source?.close()
      if (eventSourceRef.current === source) eventSourceRef.current = null
      if (highlightTimerRef.current) window.clearTimeout(highlightTimerRef.current)
    }
  }, [loadQueue, restaurant.id])

  useEffect(() => {
    if (connection !== 'reconnecting') return
    const interval = window.setInterval(() => void loadQueue(undefined, true), 30_000)
    return () => window.clearInterval(interval)
  }, [connection, loadQueue])

  const groups = useMemo(() => ({
    new: orders.filter((order) => queueFor(order) === 'new'),
    preparing: orders.filter((order) => queueFor(order) === 'preparing'),
    ready: orders.filter((order) => queueFor(order) === 'ready'),
    history: orders.filter((order) => queueFor(order) === 'history'),
  }), [orders])

  function openAction(action: RestaurantOrderAction, order: RestaurantOrderSummary) {
    setActionError('')
    if (action === 'ready') {
      void performAction(action, order)
      return
    }
    setDialog({ action, order })
    setDialogValue(action === 'accept' ? String(restaurant.default_prep_time_minutes || 20) : action === 'extend' ? '5' : '')
  }

  async function performAction(action: RestaurantOrderAction, order: RestaurantOrderSummary, value = '') {
    setBusyOrderId(order.id)
    setActionError('')
    try {
      const response = action === 'accept'
        ? await acceptRestaurantOrder(order.id, Number(value))
        : action === 'reject'
          ? await rejectRestaurantOrder(order.id, value.trim())
          : action === 'extend'
            ? await extendRestaurantOrderPrepTime(order.id, Number(value))
            : await markRestaurantOrderReady(order.id)
      setOrders((current) => current.map((entry) => entry.id === order.id ? response.order : entry))
      setDialog(null)
      setDialogValue('')
      await loadQueue(undefined, true)
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'The order could not be updated.')
    } finally {
      setBusyOrderId(null)
    }
  }

  function submitDialog() {
    if (!dialog) return
    const valid = dialog.action === 'reject'
      ? dialogValue.trim().length > 0 && dialogValue.trim().length <= 2000
      : Number.isInteger(Number(dialogValue)) && Number(dialogValue) >= 1 && Number(dialogValue) <= (dialog.action === 'accept' ? 180 : 120)
    if (!valid) {
      setActionError(dialog.action === 'reject' ? 'Enter a rejection reason (up to 2,000 characters).' : 'Enter a valid number of minutes.')
      return
    }
    void performAction(dialog.action, dialog.order, dialogValue)
  }

  return (
    <div className="fj-restaurant-orders-page">
      <div className="fj-restaurant-page-heading fj-restaurant-page-heading--split">
        <div>
          <p>LIVE KITCHEN QUEUE</p>
          <h1>Orders</h1>
          <span>Accept incoming orders, keep prep estimates current, and signal when food is ready.</span>
        </div>
        <div className={`fj-live-pill ${connection === 'live' ? 'is-live' : 'is-reconnecting'}`}>
          <i />
          {connection === 'live' ? 'Live updates on' : connection === 'connecting' ? 'Connecting…' : 'Reconnecting · auto-refresh on'}
        </div>
      </div>

      {newOrderNotice && (
        <div role="status" className="fj-restaurant-notice fj-restaurant-notice--success">
          <span>{newOrderNotice}</span>
          <button type="button" onClick={() => setNewOrderNotice('')}>Dismiss</button>
        </div>
      )}

      {actionError && <div role="alert" className="fj-restaurant-notice fj-restaurant-notice--error">{actionError}</div>}

      <section aria-label="Today's summary" className="fj-restaurant-stats">
        <StatCard label="Today's orders" value={dashboard ? String(dashboard.today_order_count) : '—'} />
        <StatCard label="Today's revenue" value={dashboard ? peso.format(Number(dashboard.today_revenue)) : '—'} />
        <StatCard label="Awaiting response" value={dashboard ? String(dashboard.pending_orders_count) : '—'} attention={Boolean(dashboard?.pending_orders_count)} />
        <StatCard label="In preparation" value={String(groups.preparing.length)} />
      </section>

      <div className="fj-restaurant-tabs">
        <button type="button" onClick={() => setTab('active')} className={tab === 'active' ? 'is-active' : ''}>Active <span>{groups.new.length + groups.preparing.length + groups.ready.length}</span></button>
        <button type="button" onClick={() => setTab('history')} className={tab === 'history' ? 'is-active' : ''}>Completed & cancelled <span>{groups.history.length}</span></button>
      </div>

      {loading ? <OrderQueueSkeleton /> : error ? (
        <section className="fj-restaurant-error-state">
          <p>{error}</p>
          <button type="button" onClick={() => { setLoading(true); setError(''); void loadQueue().finally(() => setLoading(false)) }}>Retry</button>
        </section>
      ) : tab === 'history' ? (
        groups.history.length > 0 ? <OrderGrid orders={groups.history} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} /> : <EmptyQueue message="No completed or cancelled orders yet." />
      ) : groups.new.length + groups.preparing.length + groups.ready.length === 0 ? (
        <EmptyQueue message="No orders right now. New orders will appear here automatically." />
      ) : (
        <div className="fj-order-board">
          <QueueColumn title="New / pending" count={groups.new.length} accent="amber" orders={groups.new} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} />
          <QueueColumn title="Preparing" count={groups.preparing.length} accent="blue" orders={groups.preparing} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} />
          <QueueColumn title="Ready for pickup" count={groups.ready.length} accent="green" orders={groups.ready} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} />
        </div>
      )}

      {dialog && (
        <div className="fj-restaurant-dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busyOrderId) setDialog(null) }}>
          <section role="dialog" aria-modal="true" aria-labelledby="order-action-title" className="fj-restaurant-dialog">
            <p className="fj-restaurant-dialog__eyebrow">{dialog.order.order_number}</p>
            <h2 id="order-action-title">{dialog.action === 'accept' ? 'Accept this order?' : dialog.action === 'reject' ? 'Why are you rejecting it?' : 'Extend preparation time'}</h2>
            {dialog.action === 'reject' ? (
              <textarea autoFocus value={dialogValue} onChange={(event) => setDialogValue(event.target.value)} maxLength={2000} rows={4} placeholder="Give the customer a clear reason" />
            ) : (
              <label className="fj-restaurant-dialog__field">
                {dialog.action === 'accept' ? 'Estimated preparation time' : 'Additional preparation time'}
                <span>
                  <input autoFocus type="number" min="1" max={dialog.action === 'accept' ? 180 : 120} inputMode="numeric" value={dialogValue} onChange={(event) => setDialogValue(event.target.value)} />
                  <small>minutes</small>
                </span>
              </label>
            )}
            {actionError && <p role="alert" className="fj-restaurant-dialog__error">{actionError}</p>}
            <div className="fj-restaurant-dialog__actions">
              <button type="button" disabled={Boolean(busyOrderId)} onClick={() => setDialog(null)} className="is-secondary">Cancel</button>
              <button type="button" disabled={Boolean(busyOrderId)} onClick={submitDialog} className={dialog.action === 'reject' ? 'is-danger' : 'is-primary'}>{busyOrderId ? 'Saving…' : dialog.action === 'accept' ? 'Accept order' : dialog.action === 'reject' ? 'Reject order' : 'Extend time'}</button>
            </div>
          </section>
        </div>
      )}
    </div>
  )
}

function StatCard({ label, value, attention = false }: { label: string; value: string; attention?: boolean }) {
  return <div className={`fj-restaurant-stat ${attention ? 'is-attention' : ''}`}><p>{label}</p><strong>{value}</strong></div>
}

function QueueColumn({ title, count, accent, orders, ...props }: { title: string; count: number; accent: 'amber' | 'blue' | 'green'; orders: RestaurantOrderSummary[]; now: number; highlightedOrderId: string | null; busyOrderId: string | null; onAction: (action: RestaurantOrderAction, order: RestaurantOrderSummary) => void }) {
  return (
    <section className={`fj-order-column fj-order-column--${accent}`}>
      <header><h2><span />{title}</h2><strong>{count}</strong></header>
      {orders.length > 0 ? <OrderGrid orders={orders} {...props} /> : <div className="fj-order-column__empty">Nothing here</div>}
    </section>
  )
}

function OrderGrid({ orders, now, highlightedOrderId, busyOrderId, onAction }: { orders: RestaurantOrderSummary[]; now: number; highlightedOrderId: string | null; busyOrderId: string | null; onAction: (action: RestaurantOrderAction, order: RestaurantOrderSummary) => void }) {
  return <div className="fj-order-grid">{orders.map((order) => <RestaurantOrderCard key={order.id} order={order} now={now} highlighted={highlightedOrderId === order.id} busy={busyOrderId === order.id} onAction={onAction} />)}</div>
}

function EmptyQueue({ message }: { message: string }) {
  return <section className="fj-restaurant-empty"><span>✓</span><h2>All caught up</h2><p>{message}</p></section>
}

function OrderQueueSkeleton() {
  return <div className="fj-order-board fj-order-board--loading" aria-busy="true">{[0, 1, 2].map((column) => <div key={column}><span /><div>{[0, 1].map((card) => <i key={card} />)}</div></div>)}</div>
}

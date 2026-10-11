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
    <div>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Live kitchen queue</p>
          <h1 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">Orders</h1>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">Accept incoming orders, keep prep estimates current, and signal when food is ready.</p>
        </div>
        <div className={`inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold ${connection === 'live' ? 'border-neon-green/25 bg-neon-green/[0.07] text-neon-green' : 'border-amber-300/20 bg-amber-300/[0.06] text-amber-200'}`}>
          <span className={`h-2 w-2 rounded-full ${connection === 'live' ? 'animate-pulse bg-neon-green' : 'bg-amber-300'}`} />
          {connection === 'live' ? 'Live updates on' : connection === 'connecting' ? 'Connecting…' : 'Reconnecting · auto-refresh on'}
        </div>
      </div>

      {newOrderNotice && (
        <div role="status" className="mt-5 flex items-center justify-between gap-3 rounded-xl border border-neon-green/30 bg-neon-green/10 px-4 py-3 text-sm font-bold text-neon-green shadow-[0_0_28px_rgba(57,255,20,0.1)]">
          <span>{newOrderNotice}</span>
          <button type="button" onClick={() => setNewOrderNotice('')} className="min-h-9 rounded-lg px-2 text-xs text-neon-green/70 hover:bg-neon-green/10">Dismiss</button>
        </div>
      )}

      {actionError && <div role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200">{actionError}</div>}

      <section aria-label="Today's summary" className="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Today's orders" value={dashboard ? String(dashboard.today_order_count) : '—'} />
        <StatCard label="Today's revenue" value={dashboard ? peso.format(Number(dashboard.today_revenue)) : '—'} />
        <StatCard label="Awaiting response" value={dashboard ? String(dashboard.pending_orders_count) : '—'} attention={Boolean(dashboard?.pending_orders_count)} />
        <StatCard label="In preparation" value={String(groups.preparing.length)} />
      </section>

      <div className="mt-7 flex gap-1 rounded-xl border border-white/10 bg-brand-surface p-1 sm:w-fit">
        <button type="button" onClick={() => setTab('active')} className={`min-h-11 flex-1 rounded-lg px-5 text-sm font-bold transition sm:flex-none ${tab === 'active' ? 'bg-neon-green text-brand-black' : 'text-white/50 hover:text-white'}`}>Active <span className="ml-1 opacity-60">{groups.new.length + groups.preparing.length + groups.ready.length}</span></button>
        <button type="button" onClick={() => setTab('history')} className={`min-h-11 flex-1 rounded-lg px-5 text-sm font-bold transition sm:flex-none ${tab === 'history' ? 'bg-neon-green text-brand-black' : 'text-white/50 hover:text-white'}`}>Completed & cancelled <span className="ml-1 opacity-60">{groups.history.length}</span></button>
      </div>

      {loading ? <OrderQueueSkeleton /> : error ? (
        <section className="mt-6 rounded-2xl border border-red-400/20 bg-brand-surface px-5 py-10 text-center">
          <p className="text-sm text-red-200">{error}</p>
          <button type="button" onClick={() => { setLoading(true); setError(''); void loadQueue().finally(() => setLoading(false)) }} className="mt-4 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Retry</button>
        </section>
      ) : tab === 'history' ? (
        groups.history.length > 0 ? <OrderGrid orders={groups.history} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} /> : <EmptyQueue message="No completed or cancelled orders yet." />
      ) : groups.new.length + groups.preparing.length + groups.ready.length === 0 ? (
        <EmptyQueue message="No orders right now. New orders will appear here automatically." />
      ) : (
        <div className="mt-6 grid items-start gap-6 xl:grid-cols-3">
          <QueueColumn title="New / pending" count={groups.new.length} accent="amber" orders={groups.new} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} />
          <QueueColumn title="Preparing" count={groups.preparing.length} accent="blue" orders={groups.preparing} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} />
          <QueueColumn title="Ready for pickup" count={groups.ready.length} accent="green" orders={groups.ready} now={now} highlightedOrderId={highlightedOrderId} busyOrderId={busyOrderId} onAction={openAction} />
        </div>
      )}

      {dialog && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/75 p-3 backdrop-blur-sm sm:items-center" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busyOrderId) setDialog(null) }}>
          <section role="dialog" aria-modal="true" aria-labelledby="order-action-title" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-5 shadow-2xl sm:p-6">
            <p className="text-xs font-black uppercase tracking-[0.16em] text-neon-green">{dialog.order.order_number}</p>
            <h2 id="order-action-title" className="mt-2 text-xl font-black text-white">{dialog.action === 'accept' ? 'Accept this order?' : dialog.action === 'reject' ? 'Why are you rejecting it?' : 'Extend preparation time'}</h2>
            {dialog.action === 'reject' ? (
              <textarea autoFocus value={dialogValue} onChange={(event) => setDialogValue(event.target.value)} maxLength={2000} rows={4} placeholder="Give the customer a clear reason" className="mt-5 w-full resize-none rounded-xl border border-white/10 bg-brand-black px-3 py-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-neon-green focus:ring-2 focus:ring-neon-green/15" />
            ) : (
              <label className="mt-5 block text-sm font-semibold text-white/70">
                {dialog.action === 'accept' ? 'Estimated preparation time' : 'Additional preparation time'}
                <span className="mt-2 flex items-center gap-3">
                  <input autoFocus type="number" min="1" max={dialog.action === 'accept' ? 180 : 120} inputMode="numeric" value={dialogValue} onChange={(event) => setDialogValue(event.target.value)} className="h-12 min-w-0 flex-1 rounded-xl border border-white/10 bg-brand-black px-3 text-white outline-none focus:border-neon-green focus:ring-2 focus:ring-neon-green/15" />
                  <span className="text-sm text-white/40">minutes</span>
                </span>
              </label>
            )}
            {actionError && <p role="alert" className="mt-3 text-sm text-red-300">{actionError}</p>}
            <div className="mt-6 grid grid-cols-2 gap-3">
              <button type="button" disabled={Boolean(busyOrderId)} onClick={() => setDialog(null)} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65 disabled:opacity-50">Cancel</button>
              <button type="button" disabled={Boolean(busyOrderId)} onClick={submitDialog} className={`min-h-12 rounded-xl text-sm font-black disabled:opacity-50 ${dialog.action === 'reject' ? 'bg-red-400 text-brand-black' : 'bg-neon-green text-brand-black'}`}>{busyOrderId ? 'Saving…' : dialog.action === 'accept' ? 'Accept order' : dialog.action === 'reject' ? 'Reject order' : 'Extend time'}</button>
            </div>
          </section>
        </div>
      )}
    </div>
  )
}

function StatCard({ label, value, attention = false }: { label: string; value: string; attention?: boolean }) {
  return <div className={`rounded-2xl border p-4 sm:p-5 ${attention ? 'border-amber-300/25 bg-amber-300/[0.06]' : 'border-white/10 bg-brand-surface'}`}><p className="text-xs font-bold uppercase tracking-[0.13em] text-white/40">{label}</p><p className={`mt-2 text-2xl font-black sm:text-3xl ${attention ? 'text-amber-200' : 'text-white'}`}>{value}</p></div>
}

function QueueColumn({ title, count, accent, orders, ...props }: { title: string; count: number; accent: 'amber' | 'blue' | 'green'; orders: RestaurantOrderSummary[]; now: number; highlightedOrderId: string | null; busyOrderId: string | null; onAction: (action: RestaurantOrderAction, order: RestaurantOrderSummary) => void }) {
  const accentClass = accent === 'amber' ? 'bg-amber-300' : accent === 'blue' ? 'bg-sky-400' : 'bg-neon-green'
  return (
    <section>
      <header className="mb-3 flex items-center justify-between px-1"><h2 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.12em] text-white/70"><span className={`h-2.5 w-2.5 rounded-full ${accentClass}`} />{title}</h2><span className="rounded-full bg-white/[0.06] px-2 py-0.5 text-xs font-bold text-white/45">{count}</span></header>
      {orders.length > 0 ? <OrderGrid orders={orders} {...props} /> : <div className="rounded-2xl border border-dashed border-white/10 px-4 py-9 text-center text-sm text-white/30">Nothing here</div>}
    </section>
  )
}

function OrderGrid({ orders, now, highlightedOrderId, busyOrderId, onAction }: { orders: RestaurantOrderSummary[]; now: number; highlightedOrderId: string | null; busyOrderId: string | null; onAction: (action: RestaurantOrderAction, order: RestaurantOrderSummary) => void }) {
  return <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-1">{orders.map((order) => <RestaurantOrderCard key={order.id} order={order} now={now} highlighted={highlightedOrderId === order.id} busy={busyOrderId === order.id} onAction={onAction} />)}</div>
}

function EmptyQueue({ message }: { message: string }) {
  return <section className="mt-6 rounded-2xl border border-dashed border-white/10 bg-brand-surface/60 px-5 py-16 text-center"><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neon-green/[0.08] text-2xl text-neon-green">✓</span><h2 className="mt-4 text-lg font-black text-white">All caught up</h2><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-white/40">{message}</p></section>
}

function OrderQueueSkeleton() {
  return <div className="mt-6 grid gap-6 xl:grid-cols-3">{[0, 1, 2].map((column) => <div key={column}><div className="mb-3 h-5 w-32 animate-pulse rounded bg-white/[0.07]"/><div className="space-y-3">{[0, 1].map((card) => <div key={card} className="h-64 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}</div></div>)}</div>
}

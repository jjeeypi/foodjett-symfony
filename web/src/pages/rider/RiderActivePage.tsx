import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useOutletContext } from 'react-router-dom'
import type { RiderOutletContext } from '../../layouts/RiderLayout'
import {
  authorizeMercure,
  getRiderActiveOrder,
  getRiderConversations,
  isApiError,
  riderArrivedAtCustomer,
  riderArrivedAtRestaurant,
  riderConfirmDelivery,
  riderConfirmPickup,
  riderCustomerUnreachable,
  riderStartDelivery,
  updateRiderLocation,
} from '../../lib/api'
import type { RiderActiveOrder } from '../../lib/api'
import { orderStatusLabel } from '../../lib/orderStatus'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const progress = ['rider_assigned', 'at_restaurant', 'picked_up', 'on_the_way', 'arrived', 'delivered'] as const

export function RiderActivePage() {
  const { refreshProfile } = useOutletContext<RiderOutletContext>()
  const [order, setOrder] = useState<RiderActiveOrder | null>(null)
  const [conversationId, setConversationId] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [pickupCode, setPickupCode] = useState('')
  const [proof, setProof] = useState<File | null>(null)
  const [cashCollected, setCashCollected] = useState(false)
  const sourceRef = useRef<EventSource | null>(null)
  const lastLocationSentAt = useRef(0)
  const activeOrderId = order?.id ?? null

  const loadActive = useCallback(async (signal?: AbortSignal) => {
    try {
      const response = await getRiderActiveOrder(signal)
      setOrder(response.order)
      setError('')
      if (response.order) {
        try {
          const conversations = await getRiderConversations(signal)
          setConversationId(conversations.data.find((conversation) => conversation.order.id === response.order?.id)?.id ?? null)
        } catch {
          if (!signal?.aborted) setConversationId(null)
        }
      } else {
        setConversationId(null)
      }
    } catch (requestError) {
      if (signal?.aborted) return
      if (isApiError(requestError) && requestError.status === 404) {
        setOrder(null)
        setError('')
      } else {
        setError(isApiError(requestError) ? requestError.message : 'Unable to load your active delivery.')
      }
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      void loadActive(controller.signal).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [loadActive])

  useEffect(() => {
    sourceRef.current?.close()
    sourceRef.current = null
    if (!activeOrderId) return
    let cancelled = false
    let source: EventSource | null = null
    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      const topic = `orders/${activeOrderId}/status`
      if (!authorization.topics.includes(topic)) return
      const url = new URL(authorization.hub_url)
      url.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(url.toString(), { withCredentials: true })
      sourceRef.current = source
      source.addEventListener('order.status_changed', () => void loadActive())
      source.addEventListener('order.updated', () => void loadActive())
    }).catch(() => undefined)
    return () => {
      cancelled = true
      source?.close()
      if (sourceRef.current === source) sourceRef.current = null
    }
  }, [activeOrderId, loadActive])

  useEffect(() => {
    if (!activeOrderId || !navigator.geolocation) return
    const watchId = navigator.geolocation.watchPosition((position) => {
      const now = Date.now()
      if (now - lastLocationSentAt.current < 7_000) return
      lastLocationSentAt.current = now
      void updateRiderLocation(position.coords.latitude, position.coords.longitude).catch(() => undefined)
    }, () => undefined, { enableHighAccuracy: true, maximumAge: 5_000, timeout: 10_000 })
    return () => navigator.geolocation.clearWatch(watchId)
  }, [activeOrderId])

  async function advance(action: () => Promise<{ order: RiderActiveOrder }>, success: string) {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const response = await action()
      setOrder(response.order)
      setNotice(success)
    } catch (requestError) {
      setError(isApiError(requestError) ? requestError.message : 'The delivery status could not be updated.')
      if (isApiError(requestError) && requestError.status === 409) void loadActive()
    } finally {
      setBusy(false)
    }
  }

  async function confirmPickup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!pickupCode.trim()) {
      setError('Enter the four-digit pickup code from the restaurant.')
      return
    }
    await advance(() => riderConfirmPickup(pickupCode.trim()), 'Pickup confirmed.')
    setPickupCode('')
  }

  async function confirmDelivery(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!proof) {
      setError('Take or choose a proof-of-delivery photo.')
      return
    }
    if (proof.size > 10 * 1024 * 1024) {
      setError('Proof photo must be 10 MB or smaller.')
      return
    }
    if (order?.payment_method === 'cod' && !cashCollected) {
      setError('Confirm that you collected the COD cash first.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const response = await riderConfirmDelivery(proof, cashCollected)
      setNotice(`Delivery complete${response.earning ? ` · earned ${peso.format(Number(response.earning.total_earned))}` : ''}.`)
      setOrder(null)
      await refreshProfile()
    } catch (requestError) {
      setError(isApiError(requestError) ? requestError.message : 'Delivery could not be confirmed.')
    } finally {
      setBusy(false)
    }
  }

  async function customerUnreachable() {
    setBusy(true)
    setError('')
    try {
      const response = await riderCustomerUnreachable()
      setNotice(response.message)
      setOrder(null)
      await refreshProfile()
    } catch (requestError) {
      setError(isApiError(requestError) ? requestError.message : 'The failed-delivery action could not be completed.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <ActiveSkeleton />

  return <div>
    <header><p className="text-xs font-black uppercase tracking-[0.18em] text-neon-green">Current task</p><h1 className="mt-1 text-3xl font-black text-white">Active delivery</h1></header>
    {notice && <div role="status" className="mt-4 rounded-xl border border-neon-green/25 bg-neon-green/[0.07] px-4 py-3 text-sm font-semibold text-neon-green">{notice}</div>}
    {error && <div role="alert" className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200"><span>{error}</span><button type="button" onClick={() => { setLoading(true); void loadActive().finally(() => setLoading(false)) }} className="min-h-9 shrink-0 rounded-lg border border-red-300/20 px-3 text-xs font-bold">Retry</button></div>}

    {!order ? <section className="mt-6 rounded-3xl border border-dashed border-white/10 bg-brand-surface px-6 py-14 text-center"><span className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-neon-green/[0.07] text-2xl text-neon-green">✓</span><h2 className="mt-5 text-xl font-black text-white">No active delivery</h2><p className="mt-2 text-sm leading-6 text-white/40">Go online and check the pool for your next order.</p><Link to="/rider/pool" className="mt-5 inline-flex min-h-12 items-center rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Check the Pool</Link></section> : <>
      <StatusProgress status={order.status} />

      {order.payment_method === 'cod' && <section className="mt-5 rounded-2xl border border-amber-300/25 bg-amber-300/[0.07] p-4"><p className="text-xs font-black uppercase tracking-[0.15em] text-amber-200">Cash on delivery</p><p className="mt-2 text-2xl font-black text-white">Collect {peso.format(Number(order.cash_to_collect))}</p><p className="mt-1 text-xs leading-5 text-white/45">Do not mark this delivery complete until the exact cash amount has been collected.</p></section>}

      <section className="mt-5 overflow-hidden rounded-3xl border border-white/10 bg-brand-surface">
        <div className="border-b border-white/[0.07] p-4"><p className="text-[10px] font-black uppercase tracking-[0.15em] text-white/30">Order {order.order_number}</p><h2 className="mt-1 text-xl font-black text-white">{order.restaurant.name}</h2><p className="mt-2 text-sm leading-5 text-white/45">{order.restaurant.address}</p><Directions latitude={routeTarget(order).latitude} longitude={routeTarget(order).longitude} label={isPickupLeg(order.status) ? 'Navigate to restaurant' : 'Navigate to customer'} /></div>
        <div className="p-4"><p className="text-[10px] font-black uppercase tracking-[0.14em] text-neon-green">Deliver to</p><p className="mt-2 text-sm font-bold leading-5 text-white/80">{order.delivery_address.address_line}</p>{order.delivery_address.landmark && <p className="mt-1 text-xs text-white/45">Landmark: {order.delivery_address.landmark}</p>}{order.delivery_address.instructions && <p className="mt-2 rounded-xl bg-white/[0.04] p-3 text-xs leading-5 text-white/55">{order.delivery_address.instructions}</p>}</div>
      </section>

      <section className="mt-4 rounded-3xl border border-white/10 bg-brand-surface p-4"><div className="flex items-center justify-between gap-3"><div><p className="text-[10px] font-black uppercase tracking-[0.14em] text-white/30">Customer</p><h2 className="mt-1 font-black text-white">{order.customer.name}</h2></div><div className="flex gap-2">{order.customer.phone && <a href={`tel:${order.customer.phone}`} className="inline-flex min-h-11 items-center rounded-xl border border-white/10 px-3 text-xs font-bold text-white/65">Call</a>}{conversationId ? <Link to={`/rider/messages/${conversationId}`} className="inline-flex min-h-11 items-center rounded-xl bg-neon-green px-3 text-xs font-black text-brand-black">Chat</Link> : <button type="button" disabled className="min-h-11 rounded-xl border border-white/10 px-3 text-xs font-bold text-white/25">Chat loading</button>}</div></div>{order.customer_notes && <p className="mt-3 rounded-xl bg-neon-green/[0.05] p-3 text-xs leading-5 text-white/60"><span className="font-black text-neon-green">Customer note: </span>{order.customer_notes}</p>}</section>

      <section className="mt-4 rounded-3xl border border-white/10 bg-brand-surface p-4"><h2 className="font-black text-white">Order items</h2><div className="mt-3 divide-y divide-white/[0.07]">{order.items.map((item, index) => <div key={`${item.name}-${index}`} className="py-3 first:pt-0 last:pb-0"><div className="flex gap-3"><span className="font-black text-neon-green">{item.quantity}×</span><div><p className="text-sm font-bold text-white/80">{item.name}</p>{item.special_instructions && <p className="mt-1 text-xs leading-5 text-amber-100/65">“{item.special_instructions}”</p>}</div></div></div>)}</div></section>

      <section className="sticky bottom-[calc(5.9rem+env(safe-area-inset-bottom))] z-20 mt-5 rounded-3xl border border-white/10 bg-brand-surface/95 p-4 shadow-[0_-10px_35px_rgba(0,0,0,0.3)] backdrop-blur">
        <StatusAction order={order} busy={busy} pickupCode={pickupCode} proof={proof} cashCollected={cashCollected} onPickupCode={setPickupCode} onProof={setProof} onCashCollected={setCashCollected} onConfirmPickup={confirmPickup} onConfirmDelivery={confirmDelivery} onAdvance={(action, message) => void advance(action, message)} onUnreachable={() => void customerUnreachable()} />
      </section>
    </>}
  </div>
}

function StatusAction({ order, busy, pickupCode, proof, cashCollected, onPickupCode, onProof, onCashCollected, onConfirmPickup, onConfirmDelivery, onAdvance, onUnreachable }: {
  order: RiderActiveOrder; busy: boolean; pickupCode: string; proof: File | null; cashCollected: boolean
  onPickupCode: (value: string) => void; onProof: (file: File | null) => void; onCashCollected: (value: boolean) => void
  onConfirmPickup: (event: FormEvent<HTMLFormElement>) => void; onConfirmDelivery: (event: FormEvent<HTMLFormElement>) => void
  onAdvance: (action: () => Promise<{ order: RiderActiveOrder }>, message: string) => void; onUnreachable: () => void
}) {
  if (order.status === 'rider_assigned') return <ActionButton busy={busy} label="I’ve arrived at the restaurant" onClick={() => onAdvance(riderArrivedAtRestaurant, 'Arrival at restaurant recorded.')} />
  if (order.status === 'at_restaurant') return <form onSubmit={onConfirmPickup}><label className="block text-xs font-bold text-white/55">Pickup code<input type="text" inputMode="numeric" pattern="[0-9]*" maxLength={4} value={pickupCode} onChange={(event) => onPickupCode(event.target.value.replace(/\D/g, '').slice(0, 4))} placeholder="4-digit code" className="mt-2 h-12 w-full rounded-xl border border-white/10 bg-brand-black px-4 text-center text-xl font-black tracking-[0.35em] text-white outline-none focus:border-neon-green" /></label>{order.estimated_ready_at && <p className="mt-2 text-xs text-white/35">Estimated ready: {new Date(order.estimated_ready_at).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}</p>}<button type="submit" disabled={busy || pickupCode.length !== 4} className="mt-3 min-h-12 w-full rounded-xl bg-neon-green text-sm font-black text-brand-black disabled:opacity-40">{busy ? 'Confirming…' : 'Confirm pickup'}</button></form>
  if (order.status === 'picked_up') return <ActionButton busy={busy} label="Start delivery" onClick={() => onAdvance(riderStartDelivery, 'Delivery started.')} />
  if (order.status === 'on_the_way') return <ActionButton busy={busy} label="I’ve arrived at the customer" onClick={() => onAdvance(riderArrivedAtCustomer, 'Arrival at customer recorded.')} />
  if (order.status === 'arrived') return <form onSubmit={onConfirmDelivery}><label className="flex min-h-12 cursor-pointer items-center justify-center rounded-xl border border-dashed border-neon-green/30 bg-neon-green/[0.04] px-3 text-center text-xs font-bold text-neon-green">{proof ? proof.name : 'Take or choose proof-of-delivery photo'}<input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" onChange={(event) => onProof(event.target.files?.[0] ?? null)} className="sr-only" /></label>{order.payment_method === 'cod' && <label className="mt-3 flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-amber-300/20 bg-amber-300/[0.05] px-3"><input type="checkbox" checked={cashCollected} onChange={(event) => onCashCollected(event.target.checked)} className="h-5 w-5 accent-[#39ff14]"/><span className="text-sm font-bold text-amber-100">I collected {peso.format(Number(order.cash_to_collect))}</span></label>}<button type="submit" disabled={busy || !proof || (order.payment_method === 'cod' && !cashCollected)} className="mt-3 min-h-12 w-full rounded-xl bg-neon-green text-sm font-black text-brand-black disabled:opacity-40">{busy ? 'Completing…' : 'Confirm delivery'}</button><button type="button" disabled={busy} onClick={onUnreachable} className="mt-2 min-h-11 w-full rounded-xl text-xs font-bold text-red-300">Customer unreachable</button></form>
  return <p className="text-center text-sm text-white/45">Waiting for the next available action.</p>
}

function ActionButton({ busy, label, onClick }: { busy: boolean; label: string; onClick: () => void }) { return <button type="button" disabled={busy} onClick={onClick} className="min-h-14 w-full rounded-2xl bg-neon-green text-base font-black text-brand-black shadow-[0_0_25px_rgba(57,255,20,0.1)] disabled:opacity-40">{busy ? 'Updating…' : label}</button> }

function StatusProgress({ status }: { status: RiderActiveOrder['status'] }) {
  const current = progress.indexOf(status as (typeof progress)[number])
  return <section className="mt-5 rounded-2xl border border-white/10 bg-brand-surface p-4"><div className="flex items-center justify-between gap-1">{progress.map((step, index) => <div key={step} className="flex flex-1 items-center last:flex-none"><span title={orderStatusLabel(step)} className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-black ${index <= current ? 'bg-neon-green text-brand-black' : 'bg-white/[0.07] text-white/30'}`}>{index < current ? '✓' : index + 1}</span>{index < progress.length - 1 && <span className={`h-0.5 flex-1 ${index < current ? 'bg-neon-green' : 'bg-white/[0.08]'}`}/>}</div>)}</div><p className="mt-3 text-center text-sm font-black text-white">{orderStatusLabel(status)}</p></section>
}

function Directions({ latitude, longitude, label }: { latitude: string; longitude: string; label: string }) { const destination = encodeURIComponent(`${latitude},${longitude}`); return <div className="mt-3 flex gap-2"><a href={`https://www.google.com/maps/dir/?api=1&destination=${destination}`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-xl bg-neon-green px-3 text-xs font-black text-brand-black">{label}</a><a href={`https://www.waze.com/ul?ll=${destination}&navigate=yes`} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-xl border border-white/10 px-3 text-xs font-bold text-white/60">Waze</a></div> }
function isPickupLeg(status: RiderActiveOrder['status']) { return status === 'rider_assigned' || status === 'at_restaurant' }
function routeTarget(order: RiderActiveOrder) { return isPickupLeg(order.status) ? order.restaurant : order.delivery_address }
function ActiveSkeleton() { return <div aria-label="Loading active delivery"><div className="h-8 w-48 animate-pulse rounded bg-white/[0.07]"/><div className="mt-6 h-28 animate-pulse rounded-2xl bg-brand-surface"/><div className="mt-4 h-64 animate-pulse rounded-3xl bg-brand-surface"/><div className="mt-4 h-40 animate-pulse rounded-3xl bg-brand-surface"/></div> }

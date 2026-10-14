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

  return <div className="fj-rider-page fj-rider-active-page">
    <header className="fj-rider-page-heading"><div><p className="fj-rider-eyebrow"><span /> CURRENT TASK</p><h1>Active delivery</h1><p>Everything you need for a smooth pickup and handoff, in one place.</p></div></header>
    {notice && <div role="status" className="fj-rider-alert fj-rider-alert--success">{notice}</div>}
    {error && <div role="alert" className="fj-rider-alert fj-rider-alert--error"><span>{error}</span><button type="button" onClick={() => { setLoading(true); void loadActive().finally(() => setLoading(false)) }}>Retry</button></div>}

    {!order ? <section className="fj-rider-empty-state"><span className="fj-rider-empty-icon is-ready">✓</span><h2>No active delivery</h2><p>Go online and check the order pool when you’re ready for your next run.</p><Link to="/rider/pool" className="fj-rider-primary-button">Check the order pool</Link></section> : <>
      <StatusProgress status={order.status} />

      {order.payment_method === 'cod' && <section className="fj-rider-cash-banner"><div><p>CASH ON DELIVERY</p><strong>Collect {peso.format(Number(order.cash_to_collect))}</strong></div><span>Confirm the exact amount before completing this delivery.</span></section>}

      <div className="fj-rider-active-grid">
        <div className="fj-rider-active-main">
          <section className="fj-rider-task-card">
            <header><div><p>ORDER {order.order_number}</p><h2>{order.restaurant.name}</h2></div><span>{orderStatusLabel(order.status)}</span></header>
            <div className="fj-rider-task-route">
              <span className="fj-rider-task-line" />
              <article><i className="pickup"/><div><small>PICKUP</small><h3>{order.restaurant.name}</h3><p>{order.restaurant.address}</p></div></article>
              <article><i className="dropoff"/><div><small>DELIVER TO</small><h3>{order.delivery_address.address_line}</h3>{order.delivery_address.landmark && <p>Landmark: {order.delivery_address.landmark}</p>}</div></article>
            </div>
            {order.delivery_address.instructions && <div className="fj-rider-instructions"><strong>Delivery note</strong><p>{order.delivery_address.instructions}</p></div>}
            <Directions latitude={routeTarget(order).latitude} longitude={routeTarget(order).longitude} label={isPickupLeg(order.status) ? 'Navigate to restaurant' : 'Navigate to customer'} />
          </section>

          <section className="fj-rider-items-card"><header><div><p>ORDER DETAILS</p><h2>Items to collect</h2></div><span>{order.items.reduce((total, item) => total + item.quantity, 0)} items</span></header><div>{order.items.map((item, index) => <article key={`${item.name}-${index}`}><span>{item.quantity}×</span><div><strong>{item.name}</strong>{item.special_instructions && <p>“{item.special_instructions}”</p>}</div></article>)}</div></section>
        </div>

        <aside className="fj-rider-active-side">
          <section className="fj-rider-customer-card"><p>CUSTOMER</p><div><span>{order.customer.name.charAt(0).toUpperCase()}</span><h2>{order.customer.name}</h2></div><div className="fj-rider-customer-actions">{order.customer.phone && <a href={`tel:${order.customer.phone}`} className="fj-rider-secondary-button">Call customer</a>}{conversationId ? <Link to={`/rider/messages/${conversationId}`} className="fj-rider-primary-button">Open chat</Link> : <button type="button" disabled className="fj-rider-secondary-button">Chat loading</button>}</div>{order.customer_notes && <div className="fj-rider-customer-note"><strong>Customer note</strong><p>{order.customer_notes}</p></div>}</section>

          <section className="fj-rider-action-card"><p>NEXT STEP</p><StatusAction order={order} busy={busy} pickupCode={pickupCode} proof={proof} cashCollected={cashCollected} onPickupCode={setPickupCode} onProof={setProof} onCashCollected={setCashCollected} onConfirmPickup={confirmPickup} onConfirmDelivery={confirmDelivery} onAdvance={(action, message) => void advance(action, message)} onUnreachable={() => void customerUnreachable()} /></section>
        </aside>
      </div>
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
  if (order.status === 'at_restaurant') return <form onSubmit={onConfirmPickup} className="fj-rider-action-form"><label>Pickup code<input type="text" inputMode="numeric" pattern="[0-9]*" maxLength={4} value={pickupCode} onChange={(event) => onPickupCode(event.target.value.replace(/\D/g, '').slice(0, 4))} placeholder="4-digit code" /></label>{order.estimated_ready_at && <p>Estimated ready: {new Date(order.estimated_ready_at).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}</p>}<button type="submit" disabled={busy || pickupCode.length !== 4} className="fj-rider-primary-button">{busy ? 'Confirming…' : 'Confirm pickup'}</button></form>
  if (order.status === 'picked_up') return <ActionButton busy={busy} label="Start delivery" onClick={() => onAdvance(riderStartDelivery, 'Delivery started.')} />
  if (order.status === 'on_the_way') return <ActionButton busy={busy} label="I’ve arrived at the customer" onClick={() => onAdvance(riderArrivedAtCustomer, 'Arrival at customer recorded.')} />
  if (order.status === 'arrived') return <form onSubmit={onConfirmDelivery} className="fj-rider-action-form"><label className="fj-rider-proof-upload">{proof ? proof.name : 'Take or choose proof-of-delivery photo'}<input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" onChange={(event) => onProof(event.target.files?.[0] ?? null)} className="sr-only" /></label>{order.payment_method === 'cod' && <label className="fj-rider-cash-check"><input type="checkbox" checked={cashCollected} onChange={(event) => onCashCollected(event.target.checked)}/><span>I collected {peso.format(Number(order.cash_to_collect))}</span></label>}<button type="submit" disabled={busy || !proof || (order.payment_method === 'cod' && !cashCollected)} className="fj-rider-primary-button">{busy ? 'Completing…' : 'Confirm delivery'}</button><button type="button" disabled={busy} onClick={onUnreachable} className="fj-rider-danger-button">Customer unreachable</button></form>
  return <p className="fj-rider-action-waiting">Waiting for the next available action.</p>
}

function ActionButton({ busy, label, onClick }: { busy: boolean; label: string; onClick: () => void }) { return <button type="button" disabled={busy} onClick={onClick} className="fj-rider-primary-button fj-rider-action-button">{busy ? 'Updating…' : label}</button> }

function StatusProgress({ status }: { status: RiderActiveOrder['status'] }) {
  const current = progress.indexOf(status as (typeof progress)[number])
  return <section className="fj-rider-status-progress"><div>{progress.map((step, index) => <div key={step} className={index <= current ? 'is-complete' : ''}><span title={orderStatusLabel(step)}>{index < current ? '✓' : index + 1}</span>{index < progress.length - 1 && <i />}</div>)}</div><p><span>Current status</span><strong>{orderStatusLabel(status)}</strong></p></section>
}

function Directions({ latitude, longitude, label }: { latitude: string; longitude: string; label: string }) { const destination = encodeURIComponent(`${latitude},${longitude}`); return <div className="fj-rider-directions"><a href={`https://www.google.com/maps/dir/?api=1&destination=${destination}`} target="_blank" rel="noreferrer" className="fj-rider-primary-button">{label} ↗</a><a href={`https://www.waze.com/ul?ll=${destination}&navigate=yes`} target="_blank" rel="noreferrer" className="fj-rider-secondary-button">Open in Waze</a></div> }
function isPickupLeg(status: RiderActiveOrder['status']) { return status === 'rider_assigned' || status === 'at_restaurant' }
function routeTarget(order: RiderActiveOrder) { return isPickupLeg(order.status) ? order.restaurant : order.delivery_address }
function ActiveSkeleton() { return <div className="fj-rider-active-skeleton" aria-label="Loading active delivery"><div/><div/><div/></div> }

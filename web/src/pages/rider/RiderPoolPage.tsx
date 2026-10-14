import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useOutletContext } from 'react-router-dom'
import type { RiderOutletContext } from '../../layouts/RiderLayout'
import { acceptRiderPoolOrder, authorizeMercure, declineRiderPoolOrder, getRiderPool, isApiError, refreshRiderLocation } from '../../lib/api'
import type { RiderPoolOffer } from '../../lib/api'
import { getBrowserLocation } from '../../lib/geolocation'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

function playOfferTone() {
  try {
    const context = new AudioContext()
    const oscillator = context.createOscillator()
    const gain = context.createGain()
    gain.gain.setValueAtTime(0.0001, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.35)
    oscillator.frequency.setValueAtTime(720, context.currentTime)
    oscillator.frequency.setValueAtTime(960, context.currentTime + 0.14)
    oscillator.connect(gain)
    gain.connect(context.destination)
    oscillator.start()
    oscillator.stop(context.currentTime + 0.38)
    oscillator.addEventListener('ended', () => void context.close(), { once: true })
  } catch {
    // The highlighted notice remains visible if the browser blocks audio.
  }
}

export function RiderPoolPage() {
  const navigate = useNavigate()
  const { profile, refreshProfile } = useOutletContext<RiderOutletContext>()
  const [offers, setOffers] = useState<RiderPoolOffer[]>([])
  const [loading, setLoading] = useState(profile.availability_status === 'available')
  const [error, setError] = useState('')
  const [reason, setReason] = useState('')
  const [reasonCode, setReasonCode] = useState<'outside_radius' | 'cash_limit' | ''>('')
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState('')
  const [connection, setConnection] = useState<'connecting' | 'live' | 'offline'>('offline')
  const [locationBusy, setLocationBusy] = useState(false)
  const sourceRef = useRef<EventSource | null>(null)

  const loadOffers = useCallback(async (signal?: AbortSignal, silent = false) => {
    if (!silent) setError('')
    try {
      const response = await getRiderPool(signal)
      setOffers(response.orders)
      setReason(response.reason ?? '')
      setReasonCode(response.reason_code ?? '')
    } catch (requestError) {
      if (signal?.aborted) return
      if (!silent) setError(isApiError(requestError) ? requestError.message : 'Unable to load nearby orders.')
    }
  }, [])

  useEffect(() => {
    if (profile.availability_status !== 'available') {
      queueMicrotask(() => {
        setOffers([])
        setLoading(false)
        setReason(profile.availability_status === 'busy' ? 'Finish your active delivery before returning to the pool.' : 'Go online to view available orders.')
      })
      return
    }
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true)
      void (async () => {
        const location = await getBrowserLocation()
        if (location && !controller.signal.aborted) await refreshRiderLocation(location)
        if (!controller.signal.aborted) await loadOffers(controller.signal)
      })().catch((requestError: unknown) => {
        if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to refresh your location.')
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [loadOffers, profile.availability_status])

  async function refreshLocationAndOffers() {
    setLocationBusy(true)
    setError('')
    setNotice('')
    try {
      const location = await getBrowserLocation()
      if (!location) {
        setError('Location access was unavailable. Allow location permission in your browser and try again.')
        return
      }
      await refreshRiderLocation(location)
      await refreshProfile()
      await loadOffers()
      setNotice('Location refreshed. Nearby offers are up to date.')
    } catch (requestError) {
      setError(isApiError(requestError) ? requestError.message : 'Unable to refresh your location.')
    } finally {
      setLocationBusy(false)
    }
  }

  useEffect(() => {
    sourceRef.current?.close()
    sourceRef.current = null
    if (profile.availability_status !== 'available') {
      queueMicrotask(() => setConnection('offline'))
      return
    }

    let cancelled = false
    let source: EventSource | null = null
    queueMicrotask(() => setConnection('connecting'))
    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      const topic = 'orders/pool'
      if (!authorization.topics.includes(topic)) throw new Error('Pool topic is not authorized.')
      const url = new URL(authorization.hub_url)
      url.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(url.toString(), { withCredentials: true })
      sourceRef.current = source
      source.onopen = () => setConnection('live')
      source.onerror = () => setConnection('offline')
      source.addEventListener('rider_pool.offer_created', () => {
        setNotice('A new delivery offer is available.')
        playOfferTone()
        void loadOffers(undefined, true)
      })
      source.addEventListener('rider_pool.offer_taken', (rawEvent) => {
        try {
          const payload = JSON.parse((rawEvent as MessageEvent<string>).data) as { order_id?: string }
          if (!payload.order_id) return
          setOffers((current) => current.filter((offer) => offer.order_id !== payload.order_id))
        } catch {
          void loadOffers(undefined, true)
        }
      })
    }).catch(() => { if (!cancelled) setConnection('offline') })

    return () => {
      cancelled = true
      source?.close()
      if (sourceRef.current === source) sourceRef.current = null
    }
  }, [loadOffers, profile.availability_status])

  async function accept(offer: RiderPoolOffer) {
    setBusyId(offer.order_id)
    setError('')
    try {
      await acceptRiderPoolOrder(offer.order_id)
      await refreshProfile()
      navigate('/rider/active')
    } catch (requestError) {
      if (isApiError(requestError) && requestError.status === 409) {
        setOffers((current) => current.filter((entry) => entry.order_id !== offer.order_id))
        setNotice('This order was already taken by another rider.')
      } else {
        setError(isApiError(requestError) ? requestError.message : 'The order could not be accepted.')
      }
    } finally {
      setBusyId('')
    }
  }

  async function decline(offer: RiderPoolOffer) {
    setBusyId(offer.order_id)
    setError('')
    try {
      await declineRiderPoolOrder(offer.order_id)
      setOffers((current) => current.filter((entry) => entry.order_id !== offer.order_id))
      setNotice(`Order ${offer.order_number} was skipped.`)
    } catch (requestError) {
      if (isApiError(requestError) && requestError.status === 409) {
        setOffers((current) => current.filter((entry) => entry.order_id !== offer.order_id))
        setNotice('This order is no longer available.')
      } else {
        setError(isApiError(requestError) ? requestError.message : 'The order could not be declined.')
      }
    } finally {
      setBusyId('')
    }
  }

  return (
    <div className="fj-rider-page fj-rider-pool-page">
      <header className="fj-rider-page-heading">
        <div><p className="fj-rider-eyebrow"><span /> NEARBY DELIVERIES</p><h1>Order pool</h1><p>Pick a run that works for you. The first rider to confirm gets it.</p></div>
        {profile.availability_status === 'available' && <div className="fj-rider-heading-actions"><span className={`fj-rider-live-pill fj-rider-live-pill--${connection}`}><i />{connection === 'live' ? 'Live offers' : connection === 'connecting' ? 'Connecting' : 'Reconnecting'}</span><button type="button" onClick={() => void refreshLocationAndOffers()} disabled={locationBusy} className="fj-rider-secondary-button">{locationBusy ? 'Locating…' : 'Refresh location'}</button></div>}
      </header>

      <section className="fj-rider-pool-banner">
        <div><span>GOOD ROUTES. GOOD DAYS.</span><h2>Your next delivery<br />could be right here.</h2><p>Offers are matched to your location and update live while you’re online.</p></div>
        <dl>
          <div><dt>Available now</dt><dd>{profile.availability_status === 'available' ? offers.length : '—'}</dd></div>
          <div><dt>Search radius</dt><dd>{offers[0] ? `${offers[0].search_radius_km} km` : 'Nearby'}</dd></div>
          <div><dt>Your status</dt><dd>{profile.availability_status === 'available' ? 'Online' : profile.availability_status === 'busy' ? 'Busy' : 'Offline'}</dd></div>
        </dl>
      </section>

      {notice && <div role="status" className="fj-rider-alert fj-rider-alert--success"><span>{notice}</span><button type="button" onClick={() => setNotice('')}>Dismiss</button></div>}
      {error && <div role="alert" className="fj-rider-alert fj-rider-alert--error"><span>{error}</span><button type="button" onClick={() => { setLoading(true); void loadOffers().finally(() => setLoading(false)) }}>Retry</button></div>}

      {loading ? <PoolSkeleton /> : profile.availability_status !== 'available' || reason ? (
        <section className="fj-rider-empty-state">
          <span className={`fj-rider-empty-icon${profile.availability_status === 'busy' ? ' is-busy' : ''}`}>{profile.availability_status === 'busy' ? '↗' : '○'}</span>
          <h2>{profile.availability_status === 'busy' ? 'Delivery in progress' : profile.availability_status === 'offline' ? 'You’re offline' : reasonCode === 'outside_radius' ? 'No offers in range' : reasonCode === 'cash_limit' ? 'Cash limit reached' : 'Location needed'}</h2>
          <p>{reason || 'Use the availability control to go online and receive nearby offers.'}</p>
          {profile.availability_status === 'busy' && <Link to="/rider/active" className="fj-rider-primary-button">View active delivery</Link>}
          {profile.availability_status === 'available' && <button type="button" onClick={() => void refreshLocationAndOffers()} disabled={locationBusy} className="fj-rider-primary-button">{locationBusy ? 'Refreshing…' : 'Refresh current location'}</button>}
        </section>
      ) : offers.length === 0 ? (
        <section className="fj-rider-empty-state"><span className="fj-rider-empty-icon is-ready">✓</span><h2>You’re all caught up</h2><p>Stay online—new nearby deliveries will appear here automatically.</p><button type="button" onClick={() => { setLoading(true); void loadOffers().finally(() => setLoading(false)) }} className="fj-rider-secondary-button">Refresh offers</button></section>
      ) : (
        <div className="fj-rider-offer-grid">
          {offers.map((offer) => <OfferCard key={offer.order_id} offer={offer} busy={busyId === offer.order_id} onAccept={() => void accept(offer)} onDecline={() => void decline(offer)} />)}
        </div>
      )}
    </div>
  )
}

function OfferCard({ offer, busy, onAccept, onDecline }: { offer: RiderPoolOffer; busy: boolean; onAccept: () => void; onDecline: () => void }) {
  const directions = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${offer.restaurant.latitude},${offer.restaurant.longitude}`)}`
  const escalated = offer.escalation_stage !== 'initial'
  return <article className="fj-rider-offer-card">
    <header><div><p>ORDER {offer.order_number}</p><h2>{offer.restaurant.name}</h2></div><div className="fj-rider-offer-pay"><strong>{peso.format(Number(offer.estimated_pay))}</strong><span>estimated pay</span></div></header>
    <div className="fj-rider-route-preview">
      <span className="fj-rider-route-line" />
      <div><i className="pickup" /><span><small>PICK UP</small><strong>{offer.restaurant.name}</strong></span></div>
      <div><i className="dropoff" /><span><small>DROP OFF</small><strong>Address shown after acceptance</strong></span></div>
      <a href={directions} target="_blank" rel="noreferrer">Directions ↗</a>
    </div>
    <div className="fj-rider-offer-metrics"><Metric label="To pickup" value={`${offer.pickup_distance_km} km`} /><Metric label="Delivery route" value={`${offer.delivery_distance_km} km`} /></div>
    <div className="fj-rider-offer-body">
      <div className="fj-rider-offer-tags"><span>{offer.payment_method === 'cod' ? 'Cash on delivery' : 'Prepaid'}</span>{escalated && <span className="warning">{offer.escalation_stage.replaceAll('_', ' ')}</span>}{Number(offer.pay_breakdown.incentive_pay) > 0 && <span className="accent">+{peso.format(Number(offer.pay_breakdown.incentive_pay))} incentive</span>}</div>
      <p>Customer details unlock after acceptance.{offer.estimated_ready_at ? ` Food ready around ${new Date(offer.estimated_ready_at).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}.` : ''}</p>
      <div className="fj-rider-offer-actions"><button type="button" disabled={busy} onClick={onDecline} className="fj-rider-secondary-button">Decline</button><button type="button" disabled={busy} onClick={onAccept} className="fj-rider-primary-button">{busy ? 'Confirming…' : 'Accept delivery'}</button></div>
    </div>
  </article>
}

function Metric({ label, value }: { label: string; value: string }) { return <div><span>{label}</span><strong>{value}</strong></div> }
function PoolSkeleton() { return <div className="fj-rider-skeleton-grid" aria-label="Loading available orders">{[0, 1, 2].map((item) => <div key={item} />)}</div> }

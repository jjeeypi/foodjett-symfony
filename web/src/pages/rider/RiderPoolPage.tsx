import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useOutletContext } from 'react-router-dom'
import type { RiderOutletContext } from '../../layouts/RiderLayout'
import { acceptRiderPoolOrder, authorizeMercure, declineRiderPoolOrder, getRiderPool, isApiError } from '../../lib/api'
import type { RiderPoolOffer } from '../../lib/api'

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
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState('')
  const [connection, setConnection] = useState<'connecting' | 'live' | 'offline'>('offline')
  const sourceRef = useRef<EventSource | null>(null)

  const loadOffers = useCallback(async (signal?: AbortSignal, silent = false) => {
    if (!silent) setError('')
    try {
      const response = await getRiderPool(signal)
      setOffers(response.orders)
      setReason(response.reason ?? '')
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
      void loadOffers(controller.signal).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [loadOffers, profile.availability_status])

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
    <div>
      <header className="flex items-end justify-between gap-3">
        <div><p className="text-xs font-black uppercase tracking-[0.18em] text-neon-green">Nearby deliveries</p><h1 className="mt-1 text-3xl font-black text-white">Order pool</h1><p className="mt-2 text-sm leading-5 text-white/40">Accept one delivery at a time. First rider to confirm gets it.</p></div>
        {profile.availability_status === 'available' && <span className={`mb-1 inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-black uppercase ${connection === 'live' ? 'border-neon-green/25 text-neon-green' : 'border-amber-300/20 text-amber-200'}`}><span className="h-1.5 w-1.5 rounded-full bg-current"/>{connection === 'live' ? 'Live' : connection === 'connecting' ? 'Connecting' : 'Reconnecting'}</span>}
      </header>

      {notice && <div role="status" className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-neon-green/25 bg-neon-green/[0.07] px-4 py-3 text-sm font-semibold text-neon-green"><span>{notice}</span><button type="button" onClick={() => setNotice('')} className="min-h-9 px-2 text-xs">Dismiss</button></div>}
      {error && <div role="alert" className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200"><span>{error}</span><button type="button" onClick={() => { setLoading(true); void loadOffers().finally(() => setLoading(false)) }} className="min-h-9 shrink-0 rounded-lg border border-red-300/20 px-3 text-xs font-bold">Retry</button></div>}

      {loading ? <PoolSkeleton /> : profile.availability_status !== 'available' || reason ? (
        <section className="mt-6 rounded-3xl border border-dashed border-white/10 bg-brand-surface px-6 py-14 text-center">
          <span className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full text-2xl ${profile.availability_status === 'busy' ? 'bg-amber-300/10 text-amber-200' : 'bg-white/[0.05] text-white/35'}`}>{profile.availability_status === 'busy' ? '↗' : '○'}</span>
          <h2 className="mt-5 text-xl font-black text-white">{profile.availability_status === 'busy' ? 'Delivery in progress' : profile.availability_status === 'offline' ? 'You’re offline' : 'Location needed'}</h2>
          <p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-white/40">{reason || 'Use the center button below to go online and receive nearby offers.'}</p>
          {profile.availability_status === 'busy' && <Link to="/rider/active" className="mt-5 inline-flex min-h-12 items-center rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">View active delivery</Link>}
        </section>
      ) : offers.length === 0 ? (
        <section className="mt-6 rounded-3xl border border-dashed border-white/10 bg-brand-surface px-6 py-14 text-center"><span className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-neon-green/[0.07] text-2xl text-neon-green">✓</span><h2 className="mt-5 text-xl font-black text-white">No offers right now</h2><p className="mt-2 text-sm leading-6 text-white/40">You’re online. New nearby deliveries will appear here automatically.</p><button type="button" onClick={() => { setLoading(true); void loadOffers().finally(() => setLoading(false)) }} className="mt-5 min-h-11 rounded-xl border border-neon-green/30 px-4 text-sm font-bold text-neon-green">Refresh</button></section>
      ) : (
        <div className="mt-5 space-y-4">
          {offers.map((offer) => <OfferCard key={offer.order_id} offer={offer} busy={busyId === offer.order_id} onAccept={() => void accept(offer)} onDecline={() => void decline(offer)} />)}
        </div>
      )}
    </div>
  )
}

function OfferCard({ offer, busy, onAccept, onDecline }: { offer: RiderPoolOffer; busy: boolean; onAccept: () => void; onDecline: () => void }) {
  const directions = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(`${offer.restaurant.latitude},${offer.restaurant.longitude}`)}`
  const escalated = offer.escalation_stage !== 'initial'
  return <article className="overflow-hidden rounded-3xl border border-white/10 bg-brand-surface shadow-lg">
    <div className="flex items-start justify-between gap-3 border-b border-white/[0.07] p-4">
      <div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-[0.15em] text-white/30">Order {offer.order_number}</p><h2 className="mt-1 truncate text-lg font-black text-white">{offer.restaurant.name}</h2><a href={directions} target="_blank" rel="noreferrer" className="mt-1 inline-flex min-h-9 items-center text-xs font-semibold text-neon-green">Pickup directions ↗</a></div>
      <div className="text-right"><p className="text-2xl font-black text-neon-green">{peso.format(Number(offer.estimated_pay))}</p><p className="mt-0.5 text-[10px] uppercase tracking-wider text-white/30">estimated pay</p></div>
    </div>
    <div className="grid grid-cols-2 gap-px bg-white/[0.07]"><Metric label="To pickup" value={`${offer.pickup_distance_km} km`} /><Metric label="Delivery route" value={`${offer.delivery_distance_km} km`} /></div>
    <div className="p-4">
      <div className="flex flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wide"><span className="rounded-full bg-white/[0.05] px-2.5 py-1 text-white/50">{offer.payment_method === 'cod' ? 'Cash on delivery' : 'Prepaid'}</span>{escalated && <span className="rounded-full bg-amber-300/10 px-2.5 py-1 text-amber-200">{offer.escalation_stage.replaceAll('_', ' ')}</span>}{Number(offer.pay_breakdown.incentive_pay) > 0 && <span className="rounded-full bg-neon-green/10 px-2.5 py-1 text-neon-green">+{peso.format(Number(offer.pay_breakdown.incentive_pay))} incentive</span>}</div>
      <p className="mt-3 text-xs leading-5 text-white/35">Customer’s exact delivery address is revealed after acceptance. Search radius: {offer.search_radius_km} km.{offer.estimated_ready_at ? ` Food ETA ${new Date(offer.estimated_ready_at).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}.` : ''}</p>
      <div className="mt-4 grid grid-cols-[0.8fr_1.2fr] gap-2"><button type="button" disabled={busy} onClick={onDecline} className="min-h-12 rounded-xl border border-white/10 text-sm font-bold text-white/50 disabled:opacity-40">Decline</button><button type="button" disabled={busy} onClick={onAccept} className="min-h-12 rounded-xl bg-neon-green text-sm font-black text-brand-black disabled:opacity-40">{busy ? 'Confirming…' : 'Accept delivery'}</button></div>
    </div>
  </article>
}

function Metric({ label, value }: { label: string; value: string }) { return <div className="bg-brand-surface px-4 py-3"><p className="text-[10px] font-bold uppercase tracking-wider text-white/30">{label}</p><p className="mt-1 text-sm font-black text-white/75">{value}</p></div> }
function PoolSkeleton() { return <div className="mt-5 space-y-4" aria-label="Loading available orders">{[0, 1, 2].map((item) => <div key={item} className="h-72 animate-pulse rounded-3xl border border-white/[0.06] bg-brand-surface" />)}</div> }

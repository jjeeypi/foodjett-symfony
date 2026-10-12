import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { AdminDocumentCard } from '../../components/admin/AdminDocumentCard'
import { humanize } from '../../components/admin/formatting'
import { AdminPagination } from '../../components/admin/AdminPagination'
import { apiAssetUrl, getAdminRestaurant, getAdminRestaurants, isApiError, reactivateAdminRestaurant, suspendAdminRestaurant } from '../../lib/api'
import type { AdminRestaurantApplication, AdminRestaurantOverview, PaginationMeta } from '../../lib/api'

const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })

export function AdminRestaurantsPage() {
  const { id } = useParams<{ id?: string }>()
  return id ? <RestaurantDetail id={id} /> : <RestaurantList />
}

function RestaurantList() {
  const [params, setParams] = useSearchParams()
  const page = positiveInt(params.get('page'))
  const search = params.get('search') ?? ''
  const [query, setQuery] = useState(search)
  const [restaurants, setRestaurants] = useState<AdminRestaurantOverview[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 20, total: 0, lastPage: 1 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getAdminRestaurants(page, search || undefined, controller.signal).then((response) => { setRestaurants(response.data); setMeta(response.meta) }).catch((requestError: unknown) => {
        if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load restaurants.')
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [page, retry, search])

  function navigate(nextPage: number, nextSearch = search) {
    const next = new URLSearchParams()
    if (nextSearch) next.set('search', nextSearch)
    if (nextPage > 1) next.set('page', String(nextPage))
    setParams(next)
  }

  return (
    <div>
      <Heading title="Approved restaurants" description="Review active restaurant profiles and manage platform access." />
      <form onSubmit={(event) => { event.preventDefault(); navigate(1, query.trim()) }} className="mt-6 flex max-w-xl gap-2 rounded-2xl border border-white/10 bg-brand-surface p-3">
        <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search restaurant name" className="min-h-11 min-w-0 flex-1 rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-neon-green" />
        <button className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Search</button>
      </form>
      {loading ? <Skeleton /> : error ? <ErrorState message={error} onRetry={() => setRetry((value) => value + 1)} /> : restaurants.length === 0 ? <Empty /> : <>
        <div className="mt-5 overflow-hidden rounded-2xl border border-white/10 bg-brand-surface">
          <div className="hidden overflow-x-auto md:block"><table className="w-full text-left text-sm"><thead className="border-b border-white/10 bg-white/[0.025] text-[10px] uppercase tracking-[0.13em] text-white/35"><tr><th className="px-5 py-4">Restaurant</th><th className="px-5 py-4">Cuisine</th><th className="px-5 py-4">Store</th><th className="px-5 py-4">Account</th><th className="px-5 py-4">Profile created</th></tr></thead><tbody className="divide-y divide-white/[0.07]">{restaurants.map((restaurant) => <tr key={restaurant.id} className="hover:bg-white/[0.025]"><td className="px-5 py-4"><Link to={`/admin/restaurants/${restaurant.id}`} className="font-black text-white hover:text-neon-green">{restaurant.name}</Link><p className="mt-1 text-xs text-white/35">{restaurant.owner.name}</p></td><td className="px-5 py-4 text-white/60">{restaurant.cuisine_type ?? '—'}</td><td className="px-5 py-4"><StateBadge value={restaurant.operating_status} /></td><td className="px-5 py-4"><StateBadge value={restaurant.owner.status} /></td><td className="px-5 py-4 text-xs text-white/40">{formatDate(restaurant.created_at)}</td></tr>)}</tbody></table></div>
          <div className="divide-y divide-white/[0.07] md:hidden">{restaurants.map((restaurant) => <Link key={restaurant.id} to={`/admin/restaurants/${restaurant.id}`} className="block p-4 hover:bg-white/[0.025]"><div className="flex items-start justify-between gap-3"><div><p className="font-black text-white">{restaurant.name}</p><p className="mt-1 text-xs text-white/40">{restaurant.cuisine_type ?? 'Cuisine not set'} · {restaurant.owner.name}</p></div><StateBadge value={restaurant.operating_status} /></div><div className="mt-4 flex justify-between text-xs text-white/35"><span>Account: {humanize(restaurant.owner.status)}</span><span>{formatDate(restaurant.created_at)}</span></div></Link>)}</div>
        </div>
        <AdminPagination meta={meta} onPage={(next) => navigate(next)} />
      </>}
    </div>
  )
}

function RestaurantDetail({ id }: { id: string }) {
  const [restaurant, setRestaurant] = useState<AdminRestaurantApplication | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getAdminRestaurant(id, controller.signal).then((response) => setRestaurant(response.restaurant)).catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load this restaurant.') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [id, retry])

  async function changeStatus() {
    if (!restaurant || busy) return
    setBusy(true); setError('')
    try {
      const response = restaurant.owner.status === 'active' ? await suspendAdminRestaurant(restaurant.id) : await reactivateAdminRestaurant(restaurant.id)
      setRestaurant(response.restaurant); setNotice(response.restaurant.owner.status === 'active' ? 'Restaurant account reactivated.' : 'Restaurant account suspended.'); setConfirming(false)
    } catch (requestError) { setError(isApiError(requestError) ? requestError.message : 'The account status could not be changed.') } finally { setBusy(false) }
  }

  if (loading) return <Skeleton />
  if (!restaurant) return <ErrorState message={error || 'Restaurant not found.'} onRetry={() => setRetry((value) => value + 1)} />
  const logo = apiAssetUrl(restaurant.logo_path ?? null)
  const suspending = restaurant.owner.status === 'active'

  return <div><Link to="/admin/restaurants" className="inline-flex min-h-10 items-center text-sm font-bold text-white/50 hover:text-neon-green">← Back to restaurants</Link>
    <div className="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div className="flex items-center gap-4"><span className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-neon-green/[0.06] text-2xl font-black text-neon-green">{logo ? <img src={logo} alt="" className="h-full w-full object-cover" /> : restaurant.name.charAt(0)}</span><div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Restaurant profile</p><h2 className="mt-1 text-3xl font-black text-white">{restaurant.name}</h2><p className="mt-1 text-sm text-white/40">{restaurant.cuisine_type ?? 'Cuisine not set'}</p></div></div><button type="button" onClick={() => setConfirming(true)} className={`min-h-11 rounded-xl border px-5 text-sm font-black ${suspending ? 'border-red-400/30 text-red-300 hover:bg-red-400/10' : 'border-neon-green/30 text-neon-green hover:bg-neon-green/10'}`}>{suspending ? 'Suspend account' : 'Reactivate account'}</button></div>
    {notice && <p role="status" className="mt-5 rounded-xl border border-neon-green/20 bg-neon-green/[0.06] px-4 py-3 text-sm font-semibold text-neon-green">{notice}</p>}{error && <p role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-4 py-3 text-sm text-red-200">{error}</p>}
    <div className="mt-6 grid gap-5 xl:grid-cols-2"><Panel title="Business profile"><Info label="Operating status" value={humanize(restaurant.operating_status)} /><Info label="Approval status" value={humanize(restaurant.approval_status)} /><Info label="Address" value={restaurant.address} /><Info label="Description" value={restaurant.description ?? 'Not provided'} /><Info label="Minimum order" value={`₱${Number(restaurant.min_order_amount).toFixed(2)}`} /><Info label="Default preparation" value={`${restaurant.default_prep_time_minutes} minutes`} /><Info label="Commission rate" value={`${restaurant.commission_rate}%`} /><Info label="Profile created" value={formatDate(restaurant.created_at)} /></Panel><Panel title="Owner account"><Info label="Name" value={restaurant.owner.name} /><Info label="Email" value={restaurant.owner.email ?? 'Not provided'} /><Info label="Phone" value={restaurant.owner.phone ?? 'Not provided'} /><Info label="Account status" value={humanize(restaurant.owner.status)} /></Panel></div>
    <section className="mt-5"><h3 className="text-xs font-black uppercase tracking-[0.15em] text-white/45">Documents</h3>{restaurant.documents.length > 0 ? <div className="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">{restaurant.documents.map((document) => <AdminDocumentCard key={document.id} document={document} />)}</div> : <p className="mt-3 rounded-xl border border-dashed border-white/10 p-5 text-sm text-white/40">No documents uploaded.</p>}</section>
    {confirming && <ConfirmDialog title={`${suspending ? 'Suspend' : 'Reactivate'} ${restaurant.name}?`} message={suspending ? 'The owner will be blocked from signing in until an admin reactivates the account.' : 'The owner will regain access to the restaurant dashboard.'} confirm={suspending ? 'Suspend account' : 'Reactivate account'} danger={suspending} busy={busy} onCancel={() => setConfirming(false)} onConfirm={() => void changeStatus()} />}
  </div>
}

function Heading({ title, description }: { title: string; description: string }) { return <header><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Restaurant management</p><h2 className="mt-2 text-3xl font-black text-white sm:text-4xl">{title}</h2><p className="mt-2 text-sm leading-6 text-white/45">{description}</p></header> }
function Panel({ title, children }: { title: string; children: ReactNode }) { return <section className="rounded-2xl border border-white/10 bg-brand-surface p-5"><h3 className="mb-4 text-xs font-black uppercase tracking-[0.15em] text-white/45">{title}</h3><dl className="grid gap-4 sm:grid-cols-2">{children}</dl></section> }
function Info({ label, value }: { label: string; value: string }) { return <div><dt className="text-[10px] font-black uppercase tracking-[0.12em] text-white/30">{label}</dt><dd className="mt-1 break-words text-sm font-semibold leading-6 text-white/75">{value}</dd></div> }
function StateBadge({ value }: { value: string }) { const positive = value === 'active' || value === 'open' || value === 'approved'; const style = positive ? 'border-neon-green/25 bg-neon-green/[0.08] text-neon-green' : value === 'suspended' ? 'border-red-400/25 bg-red-400/[0.08] text-red-300' : 'border-white/10 bg-white/[0.05] text-white/55'; return <span className={`inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wider ${style}`}>{humanize(value)}</span> }
function ConfirmDialog({ title, message, confirm, danger, busy, onCancel, onConfirm }: { title: string; message: string; confirm: string; danger: boolean; busy: boolean; onCancel: () => void; onConfirm: () => void }) { return <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center"><section role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-6"><h3 className="text-xl font-black text-white">{title}</h3><p className="mt-2 text-sm leading-6 text-white/45">{message}</p><div className="mt-6 grid grid-cols-2 gap-3"><button disabled={busy} onClick={onCancel} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65">Cancel</button><button disabled={busy} onClick={onConfirm} className={`min-h-12 rounded-xl text-sm font-black text-brand-black ${danger ? 'bg-red-400' : 'bg-neon-green'}`}>{busy ? 'Saving…' : confirm}</button></div></section></div> }
function Skeleton() { return <div className="mt-5 space-y-3">{[0, 1, 2, 3].map((item) => <div key={item} className="h-24 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}</div> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section className="mt-5 rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-12 text-center"><p className="text-sm text-red-100/75">{message}</p><button onClick={onRetry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section> }
function Empty() { return <section className="mt-5 rounded-2xl border border-dashed border-white/10 bg-brand-surface/60 px-5 py-14 text-center"><h3 className="font-black text-white">No approved restaurants</h3><p className="mt-2 text-sm text-white/40">Approved restaurant accounts will appear here.</p></section> }
function formatDate(value: string | null): string { if (!value) return 'Not recorded'; const date = new Date(value); return Number.isNaN(date.getTime()) ? 'Not recorded' : dateTime.format(date) }
function positiveInt(value: string | null): number { const parsed = Number(value ?? '1'); return Number.isInteger(parsed) && parsed > 0 ? parsed : 1 }

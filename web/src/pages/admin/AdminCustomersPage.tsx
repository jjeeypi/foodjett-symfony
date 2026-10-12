import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { AdminPagination } from '../../components/admin/AdminPagination'
import { AdminConfirmDialog, AdminEmptyState, AdminErrorState, AdminLoadingState, AdminPageHeader, AdminStatusBadge } from '../../components/admin/AdminPage'
import { AdminOrderStatusBadge } from '../../components/admin/AdminOrderStatusBadge'
import { apiAssetUrl, getAdminCustomer, getAdminCustomers, isApiError, reactivateAdminCustomer, suspendAdminCustomer } from '../../lib/api'
import type { AdminCustomerDetail, AdminCustomerOverview, PaginationMeta } from '../../lib/api'

const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const date = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' })
const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })

export function AdminCustomersPage() {
  const { id } = useParams<{ id?: string }>()
  return id ? <CustomerDetail id={id} /> : <CustomerList />
}

function CustomerList() {
  const [params, setParams] = useSearchParams()
  const page = positivePage(params.get('page'))
  const search = params.get('search') ?? ''
  const [query, setQuery] = useState(search)
  const [customers, setCustomers] = useState<AdminCustomerOverview[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 20, total: 0, lastPage: 1 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getAdminCustomers(page, search || undefined, controller.signal)
        .then((response) => { setCustomers(response.data); setMeta(response.meta) })
        .catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load customers.') })
        .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [page, retry, search])

  function navigate(nextPage: number, nextSearch = search) {
    const next = new URLSearchParams()
    if (nextSearch) next.set('search', nextSearch)
    if (nextPage > 1) next.set('page', String(nextPage))
    setParams(next)
  }

  return <div>
    <AdminPageHeader eyebrow="Customer management" title="Customers" description="Review customer accounts and their platform access." />
    <form onSubmit={(event) => { event.preventDefault(); navigate(1, query.trim()) }} className="mt-6 flex max-w-xl gap-2 rounded-2xl border border-white/10 bg-brand-surface p-3">
      <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search name, email, or phone" className="min-h-11 min-w-0 flex-1 rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-neon-green" />
      <button className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Search</button>
    </form>
    {loading ? <AdminLoadingState /> : error ? <AdminErrorState message={error} onRetry={() => setRetry((value) => value + 1)} /> : customers.length === 0 ? <AdminEmptyState title="No customers found" message="No customer accounts match this search." /> : <>
      <div className="mt-5 overflow-hidden rounded-2xl border border-white/10 bg-brand-surface">
        <div className="hidden overflow-x-auto md:block"><table className="w-full text-left text-sm"><thead className="border-b border-white/10 bg-white/[0.025] text-[10px] uppercase tracking-[0.13em] text-white/35"><tr><th className="px-5 py-4">Customer</th><th className="px-5 py-4">Email</th><th className="px-5 py-4">Phone</th><th className="px-5 py-4">Status</th><th className="px-5 py-4">Joined</th></tr></thead><tbody className="divide-y divide-white/[0.07]">{customers.map((customer) => <tr key={customer.id} className="hover:bg-white/[0.025]"><td className="px-5 py-4"><Link to={`/admin/customers/${customer.id}`} className="font-black text-white hover:text-neon-green">{customer.name}</Link></td><td className="px-5 py-4 text-white/60">{customer.email ?? '—'}</td><td className="px-5 py-4 text-white/60">{customer.phone ?? '—'}</td><td className="px-5 py-4"><AdminStatusBadge value={customer.status} /></td><td className="px-5 py-4 text-xs text-white/40">{formatDate(customer.created_at)}</td></tr>)}</tbody></table></div>
        <div className="divide-y divide-white/[0.07] md:hidden">{customers.map((customer) => <Link key={customer.id} to={`/admin/customers/${customer.id}`} className="block p-4 hover:bg-white/[0.025]"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate font-black text-white">{customer.name}</p><p className="mt-1 truncate text-xs text-white/40">{customer.email ?? customer.phone ?? 'No contact information'}</p></div><AdminStatusBadge value={customer.status} /></div><p className="mt-3 text-xs text-white/30">Joined {formatDate(customer.created_at)}</p></Link>)}</div>
      </div>
      <AdminPagination meta={meta} onPage={(next) => navigate(next)} />
    </>}
  </div>
}

function CustomerDetail({ id }: { id: string }) {
  const [customer, setCustomer] = useState<AdminCustomerDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [retry, setRetry] = useState(0)
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getAdminCustomer(id, controller.signal).then((response) => setCustomer(response.customer)).catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load this customer.') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [id, retry])

  async function changeStatus() {
    if (!customer) return
    setBusy(true); setError('')
    try {
      const response = customer.status === 'active' ? await suspendAdminCustomer(customer.id) : await reactivateAdminCustomer(customer.id)
      setCustomer((current) => current ? { ...current, ...response.customer } : current)
      setNotice(response.customer.status === 'active' ? 'Customer account reactivated.' : 'Customer account suspended.')
      setConfirming(false)
    } catch (requestError) { setError(isApiError(requestError) ? requestError.message : 'The account status could not be changed.') } finally { setBusy(false) }
  }

  if (loading) return <AdminLoadingState />
  if (!customer) return <AdminErrorState message={error || 'Customer not found.'} onRetry={() => setRetry((value) => value + 1)} />
  const avatar = apiAssetUrl(customer.avatar_path)
  const suspending = customer.status === 'active'

  return <div>
    <Link to="/admin/customers" className="inline-flex min-h-10 items-center text-sm font-bold text-white/50 hover:text-neon-green">← Back to customers</Link>
    <div className="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div className="flex items-center gap-4"><span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-neon-green/[0.06] text-2xl font-black text-neon-green">{avatar ? <img src={avatar} alt="" className="h-full w-full object-cover" /> : customer.name.charAt(0).toUpperCase()}</span><div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Customer profile</p><h2 className="mt-1 text-3xl font-black text-white">{customer.name}</h2><div className="mt-2"><AdminStatusBadge value={customer.status} /></div></div></div><button type="button" onClick={() => setConfirming(true)} className={`min-h-11 rounded-xl border px-5 text-sm font-black ${suspending ? 'border-red-400/30 text-red-300 hover:bg-red-400/10' : 'border-neon-green/30 text-neon-green hover:bg-neon-green/10'}`}>{suspending ? 'Suspend account' : 'Reactivate account'}</button></div>
    {notice && <p role="status" className="mt-5 rounded-xl border border-neon-green/20 bg-neon-green/[0.06] px-4 py-3 text-sm font-semibold text-neon-green">{notice}</p>}
    {error && <p role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-4 py-3 text-sm text-red-200">{error}</p>}
    <div className="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <section className="rounded-2xl border border-white/10 bg-brand-surface p-5"><h3 className="text-xs font-black uppercase tracking-[0.15em] text-white/45">Recent orders</h3>{customer.recent_orders.length === 0 ? <p className="mt-4 text-sm text-white/40">This customer has not placed an order.</p> : <div className="mt-4 divide-y divide-white/[0.07]">{customer.recent_orders.map((order) => <Link key={order.id} to={`/admin/orders/${order.id}`} className="grid gap-2 py-4 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center"><div><p className="font-black text-white hover:text-neon-green">#{order.order_number}</p><p className="mt-1 text-xs text-white/35">{order.restaurant_name} · {formatDateTime(order.placed_at)}</p></div><AdminOrderStatusBadge status={order.status} /><p className="font-black text-white">{money.format(Number(order.total_amount))}</p></Link>)}</div>}</section>
      <div className="space-y-5"><section className="rounded-2xl border border-white/10 bg-brand-surface p-5"><h3 className="text-xs font-black uppercase tracking-[0.15em] text-white/45">Account</h3><Info label="Email" value={customer.email ?? 'Not provided'} /><Info label="Phone" value={customer.phone ?? 'Not provided'} /><Info label="Joined" value={formatDate(customer.created_at)} /></section><section className="rounded-2xl border border-neon-green/15 bg-neon-green/[0.04] p-5"><p className="text-xs font-black uppercase tracking-[0.15em] text-neon-green">Order summary</p><p className="mt-4 text-3xl font-black text-white">{customer.order_summary.order_count}</p><p className="text-xs text-white/40">orders placed</p><p className="mt-4 text-xl font-black text-white">{money.format(Number(customer.order_summary.lifetime_spend))}</p><p className="text-xs text-white/40">lifetime spend</p></section></div>
    </div>
    {confirming && <AdminConfirmDialog title={`${suspending ? 'Suspend' : 'Reactivate'} ${customer.name}?`} message={suspending ? 'This customer will be blocked from signing in until reactivated.' : 'This customer will regain access to their account.'} confirmLabel={suspending ? 'Suspend account' : 'Reactivate account'} danger={suspending} busy={busy} onCancel={() => setConfirming(false)} onConfirm={() => void changeStatus()} />}
  </div>
}

function Info({ label, value }: { label: string; value: string }) { return <div className="mt-4"><p className="text-[10px] font-black uppercase tracking-[0.12em] text-white/30">{label}</p><p className="mt-1 break-words text-sm font-semibold text-white/70">{value}</p></div> }
function formatDate(value: string | null) { if (!value) return 'Not recorded'; const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? 'Not recorded' : date.format(parsed) }
function formatDateTime(value: string) { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? 'Not recorded' : dateTime.format(parsed) }
function positivePage(value: string | null) { const parsed = Number(value ?? '1'); return Number.isInteger(parsed) && parsed > 0 ? parsed : 1 }

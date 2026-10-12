import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { AdminOrderStatusBadge } from '../../components/admin/AdminOrderStatusBadge'
import { AdminPagination } from '../../components/admin/AdminPagination'
import { humanize } from '../../components/admin/formatting'
import { getAdminOrder, getAdminOrders, isApiError } from '../../lib/api'
import type { AdminOrderDetail, AdminOrderSummary, OrderStatus, PaginationMeta } from '../../lib/api'

const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
const statuses: OrderStatus[] = ['placed', 'accepted', 'preparing', 'ready', 'finding_rider', 'rider_assigned', 'at_restaurant', 'picked_up', 'on_the_way', 'arrived', 'delivered', 'rejected_by_restaurant', 'cancelled_by_customer', 'cancelled_by_restaurant', 'cancelled_no_rider', 'cancelled_by_admin', 'failed_delivery']

export function AdminOrdersPage() {
  const { id } = useParams<{ id?: string }>()
  return id ? <OrderDetail id={id} /> : <OrdersList />
}

function OrdersList() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = positiveInt(searchParams.get('page'))
  const status = searchParams.get('status') ?? ''
  const [orders, setOrders] = useState<AdminOrderSummary[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 20, total: 0, lastPage: 1 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true)
      setError('')
      void getAdminOrders(page, status || undefined, controller.signal).then((response) => {
        setOrders(response.data)
        setMeta(response.meta)
      }).catch((requestError: unknown) => {
        if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load platform orders.')
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [page, retry, status])

  function updateFilters(nextStatus: string, nextPage = 1) {
    const params = new URLSearchParams()
    if (nextStatus) params.set('status', nextStatus)
    if (nextPage > 1) params.set('page', String(nextPage))
    setSearchParams(params)
  }

  return (
    <div>
      <PageHeading eyebrow="Platform oversight" title="All orders" description="Review orders across every restaurant. Status changes remain with the restaurant and rider workflows." />
      <div className="mt-6 rounded-2xl border border-white/10 bg-brand-surface p-4">
        <label className="block max-w-sm text-xs font-black uppercase tracking-[0.14em] text-white/40">Status
          <select value={status} onChange={(event) => updateFilters(event.target.value)} className="mt-2 min-h-11 w-full rounded-xl border border-white/10 bg-brand-black px-3 text-sm font-semibold text-white outline-none focus:border-neon-green">
            <option value="">All statuses</option>
            {statuses.map((value) => <option key={value} value={value}>{humanize(value)}</option>)}
          </select>
        </label>
      </div>

      {loading ? <ListSkeleton /> : error ? <ErrorState message={error} onRetry={() => setRetry((value) => value + 1)} /> : orders.length === 0 ? <EmptyState title="No orders found" message="No platform orders match the selected status." /> : (
        <>
          <div className="mt-5 overflow-hidden rounded-2xl border border-white/10 bg-brand-surface">
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-white/10 bg-white/[0.025] text-[10px] uppercase tracking-[0.13em] text-white/35"><tr><th className="px-5 py-4">Order</th><th className="px-5 py-4">Customer</th><th className="px-5 py-4">Restaurant</th><th className="px-5 py-4">Status</th><th className="px-5 py-4">Total</th><th className="px-5 py-4">Placed</th></tr></thead>
                <tbody className="divide-y divide-white/[0.07]">{orders.map((order) => <OrderRow key={order.id} order={order} />)}</tbody>
              </table>
            </div>
            <div className="divide-y divide-white/[0.07] md:hidden">{orders.map((order) => <OrderCard key={order.id} order={order} />)}</div>
          </div>
          <AdminPagination meta={meta} onPage={(next) => updateFilters(status, next)} />
        </>
      )}
    </div>
  )
}

function OrderRow({ order }: { order: AdminOrderSummary }) {
  return <tr className="transition hover:bg-white/[0.025]"><td className="px-5 py-4"><Link to={`/admin/orders/${order.id}`} className="font-black text-white hover:text-neon-green">#{order.order_number}</Link><p className="mt-1 text-xs uppercase text-white/30">{order.payment_method}</p></td><td className="px-5 py-4 text-white/65">{order.customer.name}</td><td className="px-5 py-4 text-white/65">{order.restaurant.name}</td><td className="px-5 py-4"><AdminOrderStatusBadge status={order.status} /></td><td className="px-5 py-4 font-black text-white">{money.format(Number(order.total_amount))}</td><td className="px-5 py-4 text-xs text-white/40">{formatDate(order.placed_at)}</td></tr>
}

function OrderCard({ order }: { order: AdminOrderSummary }) {
  return <Link to={`/admin/orders/${order.id}`} className="block p-4 transition hover:bg-white/[0.03]"><div className="flex items-start justify-between gap-3"><div><p className="font-black text-white">#{order.order_number}</p><p className="mt-1 text-xs text-white/40">{order.restaurant.name} · {order.customer.name}</p></div><AdminOrderStatusBadge status={order.status} /></div><div className="mt-4 flex items-end justify-between gap-3"><span className="text-xs text-white/35">{formatDate(order.placed_at)}</span><strong className="text-white">{money.format(Number(order.total_amount))}</strong></div></Link>
}

function OrderDetail({ id }: { id: string }) {
  const [order, setOrder] = useState<AdminOrderDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true)
      setError('')
      void getAdminOrder(id, controller.signal).then((response) => setOrder(response.order)).catch((requestError: unknown) => {
        if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load this order.')
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [id, retry])

  if (loading) return <ListSkeleton />
  if (error || !order) return <ErrorState message={error || 'Order not found.'} onRetry={() => setRetry((value) => value + 1)} />

  return (
    <div>
      <Link to="/admin/orders" className="inline-flex min-h-10 items-center text-sm font-bold text-white/50 hover:text-neon-green">← Back to orders</Link>
      <div className="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Order detail</p><h2 className="mt-2 text-3xl font-black text-white">#{order.order_number}</h2><p className="mt-2 text-sm text-white/45">Placed {formatDate(order.placed_at)}</p></div><AdminOrderStatusBadge status={order.status} /></div>

      <div className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,.6fr)]">
        <div className="space-y-5">
          <Panel title="Items">
            <div className="divide-y divide-white/[0.07]">{order.items.map((item) => <div key={item.id} className="py-4 first:pt-0 last:pb-0"><div className="flex justify-between gap-4"><div><p className="font-bold text-white">{item.quantity}× {item.name}</p>{item.variant && <p className="mt-1 text-xs text-white/45">{item.variant.name}</p>}{item.addons.length > 0 && <p className="mt-1 text-xs text-white/35">{item.addons.map((addon) => addon.name).join(', ')}</p>}{item.special_instructions && <p className="mt-2 rounded-lg bg-amber-300/[0.06] px-3 py-2 text-xs text-amber-100">{item.special_instructions}</p>}</div><strong className="shrink-0 text-white">{money.format(Number(item.unit_price) * item.quantity)}</strong></div></div>)}</div>
          </Panel>
          <Panel title="Status timeline">
            {order.status_history.length > 0 ? <ol className="space-y-0">{order.status_history.map((event) => <li key={event.id} className="relative grid grid-cols-[1.25rem_minmax(0,1fr)] gap-3 pb-5 last:pb-0"><span className="relative mt-1 h-3 w-3 rounded-full bg-neon-green shadow-[0_0_12px_rgba(57,255,20,.45)] after:absolute after:left-[5px] after:top-3 after:h-[calc(100%+1.25rem)] after:w-px after:bg-white/10 last:after:hidden" /><div><div className="flex flex-wrap items-center gap-2"><strong className="text-sm text-white">{humanize(event.status)}</strong><span className="text-[10px] uppercase tracking-wider text-white/30">by {humanize(event.changed_by)}</span></div><p className="mt-1 text-xs text-white/35">{formatDate(event.created_at)}</p>{event.note && <p className="mt-2 text-sm leading-6 text-white/55">{event.note}</p>}</div></li>)}</ol> : <p className="text-sm text-white/40">No status history was recorded.</p>}
          </Panel>
        </div>

        <div className="space-y-5">
          <Panel title="Parties"><Info label="Customer" value={order.customer.name} /><Info label="Restaurant" value={order.restaurant.name} /><Info label="Rider" value={order.rider?.name ?? 'Not assigned'} /></Panel>
          <Panel title="Delivery"><Info label={order.delivery_address.label} value={order.delivery_address.address_line} />{order.delivery_address.landmark && <Info label="Landmark" value={order.delivery_address.landmark} />}{order.delivery_address.delivery_instructions && <Info label="Instructions" value={order.delivery_address.delivery_instructions} />}{order.customer_notes && <Info label="Customer notes" value={order.customer_notes} />}</Panel>
          <Panel title="Payment"><Info label="Method" value={order.payment_method.toUpperCase()} /><Info label="Status" value={humanize(order.payment?.status ?? 'No payment record')} />{order.payment?.transaction_reference && <Info label="Reference" value={order.payment.transaction_reference} />}</Panel>
          <Panel title="Totals"><MoneyLine label="Subtotal" value={order.subtotal} /><MoneyLine label="Delivery fee" value={order.delivery_fee} /><MoneyLine label="Service fee" value={order.service_fee} /><MoneyLine label="Discount" value={`-${order.discount_amount}`} /><MoneyLine label="Tip" value={order.tip_amount} /><div className="mt-3 flex justify-between border-t border-white/10 pt-3 text-lg font-black text-white"><span>Total</span><span>{money.format(Number(order.total_amount))}</span></div></Panel>
          {(order.cancellation_reason || order.rejection_reason) && <Panel title="Exception"><Info label="Reason" value={order.cancellation_reason ?? order.rejection_reason ?? ''} /><Info label="Recorded by" value={humanize(order.cancelled_by)} /></Panel>}
        </div>
      </div>
    </div>
  )
}

function PageHeading({ eyebrow, title, description }: { eyebrow: string; title: string; description: string }) { return <header><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">{eyebrow}</p><h2 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">{title}</h2><p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">{description}</p></header> }
function Panel({ title, children }: { title: string; children: ReactNode }) { return <section className="rounded-2xl border border-white/10 bg-brand-surface p-5"><h3 className="mb-4 text-xs font-black uppercase tracking-[0.15em] text-white/45">{title}</h3>{children}</section> }
function Info({ label, value }: { label: string; value: string }) { return <div className="mb-3 last:mb-0"><p className="text-[10px] font-black uppercase tracking-[0.12em] text-white/30">{label}</p><p className="mt-1 break-words text-sm font-semibold leading-6 text-white/75">{value}</p></div> }
function MoneyLine({ label, value }: { label: string; value: string }) { return <div className="mb-2 flex justify-between gap-4 text-sm"><span className="text-white/45">{label}</span><span className="font-semibold text-white/75">{money.format(Number(value))}</span></div> }
function ListSkeleton() { return <div className="mt-5 space-y-3" aria-busy="true">{[0, 1, 2, 3].map((item) => <div key={item} className="h-24 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}</div> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section role="alert" className="mt-5 rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-12 text-center"><h3 className="font-black text-white">Could not load this view</h3><p className="mt-2 text-sm text-red-100/70">{message}</p><button type="button" onClick={onRetry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section> }
function EmptyState({ title, message }: { title: string; message: string }) { return <section className="mt-5 rounded-2xl border border-dashed border-white/10 bg-brand-surface/60 px-5 py-14 text-center"><h3 className="font-black text-white">{title}</h3><p className="mt-2 text-sm text-white/40">{message}</p></section> }
function formatDate(value: string | null): string { if (!value) return 'Not recorded'; const date = new Date(value); return Number.isNaN(date.getTime()) ? 'Not recorded' : dateTime.format(date) }
function positiveInt(value: string | null): number { const parsed = Number(value ?? '1'); return Number.isInteger(parsed) && parsed > 0 ? parsed : 1 }

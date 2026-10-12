import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { OrderStatusBadge } from '../components/customer/OrderStatusBadge'
import { apiAssetUrl, getCustomerOrders, isApiError } from '../lib/api'
import type { CustomerOrderSummary, PaginationMeta } from '../lib/api'

type OrdersTab = 'active' | 'past'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })

function OrdersSkeleton() {
  return (
    <div className="mt-6 grid gap-3" aria-label="Loading orders" aria-busy="true">
      {[0, 1, 2, 3].map((item) => <div key={item} className="h-32 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}
    </div>
  )
}

function RestaurantLogo({ order }: { order: CustomerOrderSummary }) {
  const image = apiAssetUrl(order.restaurant.logo_path)
  return (
    <span className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-neon-green/[0.06] text-xl font-black text-neon-green sm:h-16 sm:w-16">
      {image ? <img src={image} alt="" className="h-full w-full object-cover" /> : order.restaurant.name.charAt(0).toUpperCase()}
    </span>
  )
}

export default function CustomerOrdersPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const requestedTab = searchParams.get('tab')
  const tab: OrdersTab = requestedTab === 'past' ? 'past' : 'active'
  const requestedPage = Number(searchParams.get('page') ?? '1')
  const page = Number.isInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1
  const [orders, setOrders] = useState<CustomerOrderSummary[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 10, total: 0, lastPage: 1 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    setLoading(true)
    setError('')

    void getCustomerOrders(tab, page, controller.signal).then((response) => {
      setOrders(response.data)
      setMeta(response.meta)
    }).catch((requestError: unknown) => {
      if (controller.signal.aborted) return
      setError(isApiError(requestError) ? requestError.message : 'We could not load your orders right now.')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })

    return () => controller.abort()
  }, [page, reloadKey, tab])

  function selectTab(nextTab: OrdersTab) {
    setSearchParams({ tab: nextTab }, { replace: true })
  }

  function selectPage(nextPage: number) {
    const params = new URLSearchParams({ tab, page: String(nextPage) })
    setSearchParams(params)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  return (
    <div className="mx-auto w-full max-w-5xl">
      <header>
        <p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Your deliveries</p>
        <h1 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">Orders</h1>
        <p className="mt-2 text-sm leading-6 text-white/45">Track orders in progress or revisit your completed order history.</p>
      </header>

      <div className="mt-6 grid grid-cols-2 gap-1 rounded-xl border border-white/10 bg-brand-surface p-1 sm:w-fit" role="tablist" aria-label="Order history filter">
        <button type="button" role="tab" aria-selected={tab === 'active'} onClick={() => selectTab('active')} className={`min-h-11 rounded-lg px-5 text-sm font-bold transition ${tab === 'active' ? 'bg-neon-green text-brand-black' : 'text-white/50 hover:text-white'}`}>Active</button>
        <button type="button" role="tab" aria-selected={tab === 'past'} onClick={() => selectTab('past')} className={`min-h-11 rounded-lg px-5 text-sm font-bold transition ${tab === 'past' ? 'bg-neon-green text-brand-black' : 'text-white/50 hover:text-white'}`}>Past</button>
      </div>

      {loading ? <OrdersSkeleton /> : error ? (
        <section role="alert" className="mt-6 rounded-2xl border border-red-400/20 bg-red-400/[0.07] px-5 py-12 text-center">
          <h2 className="text-lg font-black text-white">Orders could not be loaded</h2>
          <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-red-100/75">{error}</p>
          <button type="button" onClick={() => setReloadKey((key) => key + 1)} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button>
        </section>
      ) : orders.length === 0 ? (
        <section className="mt-6 rounded-2xl border border-dashed border-white/10 bg-brand-surface/60 px-5 py-16 text-center">
          <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neon-green/[0.08] text-2xl text-neon-green" aria-hidden="true">#</span>
          <h2 className="mt-4 text-lg font-black text-white">{tab === 'active' ? 'No active orders' : 'No past orders yet'}</h2>
          <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-white/40">{tab === 'active' ? 'When you place an order, its live progress will appear here.' : 'Completed and cancelled orders will remain available here.'}</p>
          {tab === 'active' && <Link to="/" className="mt-5 inline-flex min-h-11 items-center rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Browse restaurants</Link>}
        </section>
      ) : (
        <section className="mt-6 grid gap-3" aria-live="polite">
          {orders.map((order) => (
            <Link key={order.id} to={`/orders/${order.id}`} className="group rounded-2xl border border-white/10 bg-brand-surface p-4 transition hover:border-neon-green/30 hover:bg-white/[0.045] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green sm:p-5">
              <div className="flex items-start gap-3 sm:gap-4">
                <RestaurantLogo order={order} />
                <div className="min-w-0 flex-1">
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                      <p className="truncate text-base font-black text-white transition group-hover:text-neon-green">{order.restaurant.name}</p>
                      <p className="mt-1 text-xs font-bold uppercase tracking-[0.12em] text-white/35">Order #{order.order_number}</p>
                    </div>
                    <OrderStatusBadge status={order.status} />
                  </div>
                  <div className="mt-4 flex flex-wrap items-end justify-between gap-x-5 gap-y-2 border-t border-white/8 pt-3">
                    <div className="text-xs leading-5 text-white/40">
                      <p>{dateTime.format(new Date(order.placed_at))}</p>
                      <p>{order.item_count} item{order.item_count === 1 ? '' : 's'} · {order.payment_method.toUpperCase()}</p>
                    </div>
                    <strong className="text-base text-white">{currency.format(Number(order.total_amount))}</strong>
                  </div>
                </div>
              </div>
            </Link>
          ))}
        </section>
      )}

      {!loading && !error && meta.lastPage > 1 && (
        <nav className="mt-6 flex items-center justify-between gap-3" aria-label="Orders pagination">
          <button type="button" disabled={meta.page <= 1} onClick={() => selectPage(meta.page - 1)} className="min-h-11 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/65 disabled:cursor-not-allowed disabled:opacity-30">Previous</button>
          <span className="text-xs font-bold uppercase tracking-[0.12em] text-white/35">Page {meta.page} of {meta.lastPage}</span>
          <button type="button" disabled={meta.page >= meta.lastPage} onClick={() => selectPage(meta.page + 1)} className="min-h-11 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/65 disabled:cursor-not-allowed disabled:opacity-30">Next</button>
        </nav>
      )}
    </div>
  )
}

import { useEffect, useState } from 'react'
import { AdminPagination } from '../../components/admin/AdminPagination'
import { getRestaurantEarningsSummary, getRestaurantPayouts, isApiError } from '../../lib/api'
import type { PaginationMeta, RestaurantEarningsPeriod, RestaurantPayout } from '../../lib/api'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const date = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' })

export function RestaurantEarningsPage() {
  const [summary, setSummary] = useState<{ today: RestaurantEarningsPeriod; week: RestaurantEarningsPeriod; month: RestaurantEarningsPeriod } | null>(null)
  const [payouts, setPayouts] = useState<RestaurantPayout[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 20, total: 0, lastPage: 1 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void Promise.all([getRestaurantEarningsSummary(controller.signal), getRestaurantPayouts(page, controller.signal)])
        .then(([summaryResponse, payoutResponse]) => { setSummary(summaryResponse.summary); setPayouts(payoutResponse.data); setMeta(payoutResponse.meta) })
        .catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load earnings.') })
        .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [page, retry])

  return <div className="fj-restaurant-earnings-page">
    <header className="fj-restaurant-page-heading"><p>FINANCE</p><h1>Earnings & payouts</h1><span>Monitor delivered-order revenue and admin-processed payouts. Payout records are read-only here.</span></header>
    {loading ? <Loading /> : error ? <ErrorState message={error} onRetry={() => setRetry((value) => value + 1)} /> : <>
      {summary && <section className="mt-6 grid gap-4 md:grid-cols-3"><SummaryCard label="Today" period={summary.today} /><SummaryCard label="This week" period={summary.week} /><SummaryCard label="This month" period={summary.month} /></section>}
      <section className="mt-7"><h2 className="text-xl font-black text-white">Payout history</h2><p className="mt-1 text-sm text-white/40">Generated and marked paid by a platform administrator.</p>
        {payouts.length === 0 ? <div className="mt-4 rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 px-5 py-12 text-center"><p className="font-black text-white">No payouts yet</p><p className="mt-2 text-sm text-white/40">Payout periods will appear after an admin generates them.</p></div> : <><div className="mt-4 overflow-hidden rounded-2xl border border-white/10 bg-brand-surface"><div className="hidden overflow-x-auto md:block"><table className="w-full text-left text-sm"><thead className="border-b border-white/10 text-[10px] uppercase tracking-[0.12em] text-white/35"><tr><th className="px-5 py-4">Period</th><th className="px-5 py-4">Gross</th><th className="px-5 py-4">Commission</th><th className="px-5 py-4">Net payout</th><th className="px-5 py-4">Status</th><th className="px-5 py-4">Paid</th></tr></thead><tbody className="divide-y divide-white/[0.07]">{payouts.map((payout) => <tr key={payout.id}><td className="px-5 py-4 font-semibold text-white">{formatDate(payout.period_start)} – {formatDate(payout.period_end)}</td><td className="px-5 py-4 text-white/60">{money(payout.gross_sales)}</td><td className="px-5 py-4 text-white/60">{money(payout.commission_deducted)}</td><td className="px-5 py-4 font-black text-white">{money(payout.net_amount)}</td><td className="px-5 py-4"><Status value={payout.status} /></td><td className="px-5 py-4 text-xs text-white/40">{payout.paid_at ? formatDate(payout.paid_at) : '—'}</td></tr>)}</tbody></table></div><div className="divide-y divide-white/[0.07] md:hidden">{payouts.map((payout) => <article key={payout.id} className="p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-black text-white">{money(payout.net_amount)}</p><p className="mt-1 text-xs text-white/40">{formatDate(payout.period_start)} – {formatDate(payout.period_end)}</p></div><Status value={payout.status} /></div><div className="mt-4 grid grid-cols-2 gap-3 text-xs"><p className="text-white/40">Gross <strong className="block text-white/70">{money(payout.gross_sales)}</strong></p><p className="text-white/40">Commission <strong className="block text-white/70">{money(payout.commission_deducted)}</strong></p></div></article>)}</div></div><AdminPagination meta={meta} onPage={setPage} /></>}
      </section>
    </>}
  </div>
}

function SummaryCard({ label, period }: { label: string; period: RestaurantEarningsPeriod }) { return <article className="fj-earnings-card"><p>{label}</p><strong>{money(period.net_revenue)}</strong><span>{period.order_count} delivered order{period.order_count === 1 ? '' : 's'}</span><div><p>Gross <strong>{money(period.gross_revenue)}</strong></p><p>Commission <strong>{money(period.commission)}</strong></p></div></article> }
function Status({ value }: { value: string }) { const paid = value === 'paid'; return <span className={`fj-payout-status ${paid ? 'is-paid' : 'is-pending'}`}>{value}</span> }
function money(value: string) { return peso.format(Number(value)) }
function formatDate(value: string) { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? 'Not recorded' : date.format(parsed) }
function Loading() { return <div className="fj-earnings-loading" aria-busy="true">{[0, 1, 2].map((row) => <div key={row} />)}</div> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section role="alert" className="fj-restaurant-error-state"><p>{message}</p><button type="button" onClick={onRetry}>Try again</button></section> }

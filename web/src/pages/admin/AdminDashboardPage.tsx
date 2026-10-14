import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getAdminDashboard, isApiError } from '../../lib/api'
import type { AdminDashboardKpis } from '../../lib/api'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

type Accent = 'green' | 'blue' | 'amber' | 'purple'

type Kpi = {
  label: string
  value: string
  helper: string
  accent: Accent
  to?: string
}

export function AdminDashboardPage() {
  const [dashboard, setDashboard] = useState<AdminDashboardKpis | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retryKey, setRetryKey] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      void getAdminDashboard(controller.signal)
        .then(setDashboard)
        .catch((requestError: unknown) => {
          if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load the admin dashboard.')
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false)
        })
    })

    return () => controller.abort()
  }, [retryKey])

  function retry() {
    setLoading(true)
    setError('')
    setRetryKey((key) => key + 1)
  }

  const sections = dashboard ? dashboardSections(dashboard) : []

  return (
    <div>
      <header className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Platform overview</p>
          <h2 className="mt-2 text-3xl font-black tracking-[-0.03em] text-white sm:text-4xl">Good morning, admin.</h2>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">A live operational snapshot built from Foodjett&apos;s orders, accounts, support, and finance records.</p>
        </div>
        {dashboard && <p className="text-xs text-white/30">Updated {formatUpdatedAt(dashboard.generated_at)}</p>}
      </header>

      {loading ? <DashboardSkeleton /> : error ? (
        <section role="alert" className="rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-16 text-center">
          <h2 className="text-lg font-black text-white">Dashboard unavailable</h2>
          <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-red-100/65">{error}</p>
          <button type="button" onClick={retry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button>
        </section>
      ) : dashboard ? (
        <div className="space-y-9">
          {sections.map((section) => <KpiSection key={section.title} {...section} />)}
        </div>
      ) : null}
    </div>
  )
}

function dashboardSections(dashboard: AdminDashboardKpis): Array<{ title: string; description: string; kpis: Kpi[] }> {
  return [
    {
      title: 'Operations',
      description: 'Today’s order flow and fulfillment outcomes.',
      kpis: [
        { label: 'Today’s orders', value: String(dashboard.operations.today_orders), helper: 'Placed since midnight', accent: 'green', to: '/admin/orders' },
        { label: 'Today’s revenue', value: peso.format(Number(dashboard.operations.today_revenue)), helper: 'Delivered orders only', accent: 'green', to: '/admin/payments' },
        { label: 'Orders in progress', value: String(dashboard.operations.orders_in_progress), helper: 'Across every active stage', accent: 'blue', to: '/admin/orders' },
        { label: 'Completed / cancelled', value: `${dashboard.operations.completed_today} / ${dashboard.operations.cancelled_today}`, helper: 'Today’s final outcomes', accent: 'amber', to: '/admin/orders' },
      ],
    },
    {
      title: 'Platform Health',
      description: 'Who is operating now and what needs attention.',
      kpis: [
        { label: 'Restaurants open', value: String(dashboard.platform_health.restaurants_open), helper: 'Approved, active accounts', accent: 'green', to: '/admin/restaurants' },
        { label: 'Riders online', value: String(dashboard.platform_health.riders_online), helper: 'Available or completing a job', accent: 'blue', to: '/admin/riders' },
        { label: 'Pending approvals', value: String(dashboard.platform_health.pending_approvals), helper: `${dashboard.platform_health.pending_restaurants} restaurants · ${dashboard.platform_health.pending_riders} riders`, accent: 'amber', to: '/admin/approvals' },
        { label: 'Open support tickets', value: String(dashboard.platform_health.open_support_tickets), helper: 'Open or in progress', accent: 'purple', to: '/admin/support' },
      ],
    },
    {
      title: 'Financial',
      description: 'Current-month revenue, remittances, and promotion use.',
      kpis: [
        { label: 'Revenue this month', value: peso.format(Number(dashboard.financial.total_revenue)), helper: `Since ${formatPeriodStart(dashboard.financial.period_start)}`, accent: 'green', to: '/admin/payments' },
        { label: 'Pending remittances', value: peso.format(Number(dashboard.financial.pending_remittances_amount)), helper: 'Awaiting admin confirmation', accent: 'amber', to: '/admin/remittances' },
        { label: 'Active vouchers', value: String(dashboard.financial.active_vouchers), helper: 'Currently enabled and valid', accent: 'purple', to: '/admin/vouchers' },
        { label: 'Voucher redemptions', value: String(dashboard.financial.voucher_redemptions), helper: 'Redeemed this month', accent: 'blue', to: '/admin/vouchers' },
      ],
    },
    {
      title: 'Growth',
      description: 'Registered supply, demand, and this week’s additions.',
      kpis: [
        { label: 'Registered customers', value: String(dashboard.growth.total_customers), helper: 'All customer profiles', accent: 'green', to: '/admin/customers' },
        { label: 'Approved restaurants', value: String(dashboard.growth.approved_restaurants), helper: 'Eligible to operate', accent: 'purple', to: '/admin/restaurants' },
        { label: 'Approved riders', value: String(dashboard.growth.approved_riders), helper: 'Eligible to deliver', accent: 'blue', to: '/admin/riders' },
        { label: 'New signups this week', value: String(dashboard.growth.new_signups_this_week.total), helper: `${dashboard.growth.new_signups_this_week.customers} customers · ${dashboard.growth.new_signups_this_week.restaurants} restaurants · ${dashboard.growth.new_signups_this_week.riders} riders`, accent: 'amber' },
      ],
    },
  ]
}

function KpiSection({ title, description, kpis }: { title: string; description: string; kpis: Kpi[] }) {
  return (
    <section aria-labelledby={`kpi-${title.toLowerCase().replace(/\s+/g, '-')}`}>
      <div className="mb-4">
        <h2 id={`kpi-${title.toLowerCase().replace(/\s+/g, '-')}`} className="text-xl font-black text-white">{title}</h2>
        <p className="mt-1 text-sm text-white/40">{description}</p>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map((kpi) => <KpiCard key={kpi.label} {...kpi} />)}
      </div>
    </section>
  )
}

function KpiCard({ label, value, helper, accent, to }: Kpi) {
  const content = (
    <>
      <span className={`absolute inset-y-0 left-0 w-1 ${accentClass(accent)}`} aria-hidden="true" />
      <p className="text-xs font-black uppercase tracking-[0.14em] text-white/38">{label}</p>
      <strong className="mt-4 block text-3xl font-black tracking-[-0.04em] text-white">{value}</strong>
      <p className="mt-2 min-h-10 text-xs leading-5 text-white/40">{helper}</p>
      {to && <span className="mt-4 inline-flex items-center gap-2 text-xs font-bold text-neon-green">View details <span aria-hidden="true">→</span></span>}
    </>
  )
  const classes = 'group relative min-h-48 overflow-hidden rounded-2xl border border-white/9 bg-brand-surface p-5 shadow-[0_18px_50px_rgba(0,0,0,0.12)] transition hover:-translate-y-0.5 hover:border-neon-green/25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green'

  return to ? <Link to={to} className={classes}>{content}</Link> : <article className={classes}>{content}</article>
}

function accentClass(accent: Accent): string {
  if (accent === 'blue') return 'bg-sky-400'
  if (accent === 'amber') return 'bg-amber-400'
  if (accent === 'purple') return 'bg-violet-400'
  return 'bg-neon-green'
}

function DashboardSkeleton() {
  return (
    <div className="space-y-9" aria-busy="true" aria-label="Loading dashboard">
      {Array.from({ length: 4 }, (_, section) => (
        <section key={section}>
          <div className="mb-4 h-12 w-56 animate-pulse rounded-xl bg-white/[0.06]" />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {Array.from({ length: 4 }, (__, card) => <div key={card} className="h-48 animate-pulse rounded-2xl border border-white/8 bg-brand-surface" />)}
          </div>
        </section>
      ))}
    </div>
  )
}

function formatUpdatedAt(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'just now' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function formatPeriodStart(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

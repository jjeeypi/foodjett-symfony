import { useEffect, useState } from 'react'
import { getRiderEarningsSummary, isApiError } from '../../lib/api'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function RiderEarningsPage() {
  const [summary, setSummary] = useState<{ today: string; week: string; month: string } | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => void getRiderEarningsSummary(controller.signal).then((response) => setSummary(response.summary)).catch((requestError) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load earnings.') }))
    return () => controller.abort()
  }, [])
  return <div className="fj-rider-page fj-rider-earnings-page">
    <header className="fj-rider-page-heading"><div><p className="fj-rider-eyebrow"><span /> YOUR PAY</p><h1>Earnings</h1><p>A simple view of what you’ve earned across today, this week, and this month.</p></div></header>
    {error ? <div role="alert" className="fj-rider-alert fj-rider-alert--error">{error}</div> : <>
      <section className="fj-rider-earnings-hero">
        <div><p>TODAY’S EARNINGS</p><strong>{summary ? peso.format(Number(summary.today)) : '—'}</strong><span>Completed delivery pay recorded today</span></div>
        <div className="fj-rider-earnings-chart" aria-hidden="true"><i/><i/><i/><i/><i/><i/><i/></div>
        <p><span>KEEP IT MOVING</span><strong>Every completed run adds up.</strong><small>Your totals refresh automatically as deliveries are completed.</small></p>
      </section>
      <div className="fj-rider-earning-grid"><EarningCard label="This week" value={summary?.week} caption="Your rolling weekly total"/><EarningCard label="This month" value={summary?.month} caption="Your earnings this calendar month"/></div>
      <section className="fj-rider-payout-note"><span>₱</span><div><p>PAYOUTS</p><h2>Detailed payout history is on the way.</h2><small>Your confirmed delivery totals are already tracked above; remittance and payout details will appear here in a future update.</small></div></section>
    </>}
  </div>
}

function EarningCard({ label, value, caption }: { label: string; value?: string; caption: string }) { return <article className="fj-rider-earning-card"><p>{label}</p><strong>{value === undefined ? '—' : peso.format(Number(value))}</strong><span>{caption}</span></article> }

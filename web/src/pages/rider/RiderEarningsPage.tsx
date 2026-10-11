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
  return <div><p className="text-xs font-black uppercase tracking-[0.18em] text-neon-green">Your pay</p><h1 className="mt-1 text-3xl font-black text-white">Earnings</h1><p className="mt-2 text-sm text-white/40">A lightweight summary for now; detailed payouts arrive in a later rider UI phase.</p>{error ? <p role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.07] p-4 text-sm text-red-200">{error}</p> : <div className="mt-6 grid gap-3"><EarningCard label="Today" value={summary?.today}/><EarningCard label="This week" value={summary?.week}/><EarningCard label="This month" value={summary?.month}/></div>}</div>
}

function EarningCard({ label, value }: { label: string; value?: string }) { return <div className="rounded-2xl border border-white/10 bg-brand-surface p-5"><p className="text-xs font-bold uppercase tracking-[0.14em] text-white/35">{label}</p><p className="mt-2 text-3xl font-black text-white">{value === undefined ? '—' : peso.format(Number(value))}</p></div> }

import { Link, useLocation } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { Logo } from '../components/Logo'

interface PendingState {
  role?: 'restaurant' | 'rider'
  name?: string
  businessName?: string
}

export default function PendingReviewPage() {
  const state = (useLocation().state ?? {}) as PendingState
  const role = state.role === 'rider' ? 'rider' : 'restaurant'
  const subject = role === 'restaurant' ? state.businessName || 'Your restaurant' : state.name || 'Your rider account'

  return (
    <AuthLayout showStandaloneLogo={false}>
      <section className="rounded-2xl border border-white/10 bg-brand-surface/95 p-6 text-center shadow-[0_24px_70px_rgba(0,0,0,0.5)] sm:p-9">
        <div className="flex justify-center"><Logo /></div>
        <div className="mx-auto mt-8 flex h-16 w-16 items-center justify-center rounded-full border border-neon-green/40 bg-neon-green/10 text-neon-green shadow-[0_0_28px_rgba(57,255,20,0.16)]">
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-8 w-8"><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
        </div>
        <p className="mt-6 text-sm font-bold uppercase tracking-[0.18em] text-neon-green">Application received</p>
        <h1 className="mt-2 text-3xl font-black text-white">Your application is under review</h1>
        <p className="mt-4 text-base leading-7 text-white/60">{subject} and its submitted documents are pending admin verification. You can use the login page after your account has been approved.</p>
        <div className="mt-7 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-4 text-left text-sm leading-6 text-white/55">
          Approval is handled through the admin API. Your {role} profile stays {role === 'restaurant' ? 'closed' : 'offline'} until approval.
        </div>
        <Link to="/login" className="mt-7 flex min-h-12 w-full items-center justify-center rounded-xl bg-neon-green px-5 py-3 font-bold text-brand-black transition hover:bg-[#55ff35]">Back to login</Link>
      </section>
    </AuthLayout>
  )
}

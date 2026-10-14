import { Link, useLocation } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'

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
    <AuthLayout>
      <section className="fj-auth-card fj-auth-card--compact fj-auth-pending">
        <div className="fj-auth-pending-icon">
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
        </div>
        <p className="fj-auth-pending-eyebrow">Application received</p>
        <h1>Your application is under review</h1>
        <p>{subject} and its submitted documents are pending admin verification. You can use the login page after your account has been approved.</p>
        <div className="fj-auth-pending-note">
          Approval is handled through the admin API. Your {role} profile stays {role === 'restaurant' ? 'closed' : 'offline'} until approval.
        </div>
        <Link to="/login" className="fj-auth-primary-action">Back to login</Link>
      </section>
    </AuthLayout>
  )
}

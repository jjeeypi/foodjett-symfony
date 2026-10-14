import { useNavigate, useOutletContext } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import type { RiderOutletContext } from '../../layouts/RiderLayout'

export function RiderAccountPage() {
  const navigate = useNavigate()
  const { logout } = useAuth()
  const { profile } = useOutletContext<RiderOutletContext>()
  function signOut() { logout(); navigate('/login', { replace: true }) }
  return <div className="fj-rider-page fj-rider-account-page">
    <header className="fj-rider-page-heading"><div><p className="fj-rider-eyebrow"><span /> RIDER PROFILE</p><h1>My account</h1><p>Your rider identity, vehicle, and approval information at a glance.</p></div></header>
    <section className="fj-rider-account-hero"><span>{initials(profile.name)}</span><div><p>FOODJETT RIDER PARTNER</p><h2>{profile.name}</h2><strong>{profile.email}</strong></div><i>{profile.approval_status.replaceAll('_', ' ')}</i></section>
    <section className="fj-rider-account-details"><header><div><p>PARTNER DETAILS</p><h2>Rider information</h2></div><span>Profile editing coming soon</span></header><div className="fj-rider-detail-grid"><Detail label="Vehicle" value={profile.vehicle_type}/><Detail label="Plate number" value={profile.plate_number ?? 'Not set'}/><Detail label="Phone number" value={profile.phone ?? 'Not set'}/><Detail label="Approval status" value={profile.approval_status}/></div></section>
    <section className="fj-rider-account-actions"><div><p>SESSION</p><h2>Finished for the day?</h2><span>Signing out does not change your rider approval or earnings history.</span></div><button type="button" onClick={signOut}>Sign out</button></section>
  </div>
}
function Detail({ label, value }: { label: string; value: string }) { return <div><p>{label}</p><strong>{value.replaceAll('_', ' ')}</strong></div> }
function initials(name: string) { return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'FJ' }

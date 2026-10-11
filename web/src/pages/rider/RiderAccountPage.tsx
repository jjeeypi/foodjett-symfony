import { useNavigate, useOutletContext } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import type { RiderOutletContext } from '../../layouts/RiderLayout'

export function RiderAccountPage() {
  const navigate = useNavigate()
  const { logout } = useAuth()
  const { profile } = useOutletContext<RiderOutletContext>()
  function signOut() { logout(); navigate('/login', { replace: true }) }
  return <div><p className="text-xs font-black uppercase tracking-[0.18em] text-neon-green">Rider profile</p><h1 className="mt-1 text-3xl font-black text-white">Account</h1><section className="mt-6 rounded-3xl border border-white/10 bg-brand-surface p-5"><h2 className="text-xl font-black text-white">{profile.name}</h2><p className="mt-1 text-sm text-white/45">{profile.email}</p><div className="mt-5 grid grid-cols-2 gap-3"><Detail label="Vehicle" value={profile.vehicle_type}/><Detail label="Plate" value={profile.plate_number ?? 'Not set'}/><Detail label="Phone" value={profile.phone ?? 'Not set'}/><Detail label="Approval" value={profile.approval_status}/></div></section><p className="mt-4 text-xs leading-5 text-white/35">Full profile, document, and payout editing will be added in the dedicated rider-account phase.</p><button type="button" onClick={signOut} className="mt-6 min-h-12 w-full rounded-xl border border-red-400/20 text-sm font-bold text-red-300">Sign out</button></div>
}
function Detail({ label, value }: { label: string; value: string }) { return <div className="rounded-xl bg-white/[0.035] p-3"><p className="text-[10px] font-bold uppercase tracking-wider text-white/30">{label}</p><p className="mt-1 truncate text-sm font-bold capitalize text-white/70">{value.replaceAll('_', ' ')}</p></div> }

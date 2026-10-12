import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { NeonInput } from '../components/NeonInput'
import { apiAssetUrl, getCustomerProfile, isApiError, updateCustomerProfile } from '../lib/api'
import type { CustomerProfile } from '../lib/api'

export default function CustomerProfilePage() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [profile, setProfile] = useState<CustomerProfile | null>(null)
  const [fields, setFields] = useState({ name: '', email: '', phone: '' })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getCustomerProfile(controller.signal).then(({ profile: loaded }) => { setProfile(loaded); setFields({ name: loaded.name, email: loaded.email ?? '', phone: loaded.phone ?? '' }) }).catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load your profile.') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [retry])

  async function save(event: React.FormEvent) {
    event.preventDefault(); setBusy(true); setError(''); setNotice('')
    try {
      const response = await updateCustomerProfile(fields)
      setProfile(response.profile)
      setFields({ name: response.profile.name, email: response.profile.email ?? '', phone: response.profile.phone ?? '' })
      setNotice(response.email_verification_reset ? 'Profile saved. Your updated email is now unverified, but verification remains optional.' : 'Profile saved.')
    } catch (requestError) { setError(isApiError(requestError) ? requestError.message : 'Your profile could not be saved.') } finally { setBusy(false) }
  }

  function signOut() { logout(); navigate('/login', { replace: true }) }
  if (loading) return <div className="space-y-4" aria-busy="true"><div className="h-28 animate-pulse rounded-2xl bg-brand-surface" /><div className="h-80 animate-pulse rounded-2xl bg-brand-surface" /></div>
  if (!profile) return <ErrorState message={error || 'Profile unavailable.'} onRetry={() => setRetry((value) => value + 1)} />
  const avatar = apiAssetUrl(profile.avatar_path)

  return <div className="mx-auto max-w-4xl"><header className="flex items-center gap-4"><span className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-2xl border border-neon-green/20 bg-neon-green/[0.06] text-2xl font-black text-neon-green">{avatar ? <img src={avatar} alt="" className="h-full w-full object-cover" /> : profile.name.charAt(0).toUpperCase()}</span><div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Your account</p><h1 className="mt-1 text-3xl font-black text-white">Profile</h1></div></header>
    {notice && <p role="status" className="mt-5 rounded-xl border border-neon-green/20 bg-neon-green/[0.06] px-4 py-3 text-sm font-semibold text-neon-green">{notice}</p>}{error && <p role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-4 py-3 text-sm text-red-200">{error}</p>}
    <form onSubmit={save} className="mt-6 rounded-2xl border border-white/10 bg-brand-surface p-5 sm:p-7"><h2 className="text-lg font-black text-white">Personal information</h2><p className="mt-1 text-sm text-white/40">Used for order updates and delivery contact.</p><div className="mt-5 grid gap-4 sm:grid-cols-2"><NeonInput id="customer-name" label="Name" value={fields.name} onChange={(event) => setFields((current) => ({ ...current, name: event.target.value }))} disabled={busy} /><NeonInput id="customer-phone" label="Phone" type="tel" value={fields.phone} onChange={(event) => setFields((current) => ({ ...current, phone: event.target.value }))} disabled={busy} /><div className="sm:col-span-2"><NeonInput id="customer-email" label="Email" type="email" value={fields.email} onChange={(event) => setFields((current) => ({ ...current, email: event.target.value }))} disabled={busy} /></div></div><div className="mt-5 flex justify-end"><button disabled={busy} className="min-h-11 rounded-xl bg-neon-green px-6 text-sm font-black text-brand-black disabled:opacity-50">{busy ? 'Saving…' : 'Save changes'}</button></div></form>
    <section className="mt-5 grid gap-3 sm:grid-cols-2"><Link to="/addresses" className="rounded-2xl border border-white/10 bg-brand-surface p-5 transition hover:border-neon-green/30"><p className="font-black text-white">Saved addresses</p><p className="mt-1 text-sm text-white/40">Add or update delivery locations.</p></Link><Link to="/orders" className="rounded-2xl border border-white/10 bg-brand-surface p-5 transition hover:border-neon-green/30"><p className="font-black text-white">Order history</p><p className="mt-1 text-sm text-white/40">Review active and past orders.</p></Link></section>
    <section className="mt-5 rounded-2xl border border-white/10 bg-brand-surface p-5"><h2 className="font-black text-white">Session</h2><p className="mt-1 text-sm text-white/40">Sign out of Foodjett on this browser.</p><button type="button" onClick={signOut} className="mt-4 min-h-11 rounded-xl border border-red-400/30 px-5 text-sm font-black text-red-300 hover:bg-red-400/10">Logout</button></section>
  </div>
}

function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section role="alert" className="mx-auto max-w-xl rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-14 text-center"><p className="text-sm text-red-100/75">{message}</p><button type="button" onClick={onRetry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section> }

import { useCallback, useEffect, useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Logo } from '../components/Logo'
import { apiAssetUrl, getRiderProfile, isApiError, toggleRiderAvailability } from '../lib/api'
import type { RiderAvailabilityStatus, RiderProfile } from '../lib/api'

export interface RiderOutletContext {
  profile: RiderProfile
  refreshProfile: () => Promise<void>
}

const navigation = [
  { label: 'Pool', to: '/rider/pool', icon: PoolIcon },
  { label: 'Active', to: '/rider/active', icon: ActiveIcon },
  { label: 'Earnings', to: '/rider/earnings', icon: EarningsIcon },
  { label: 'Account', to: '/rider/account', icon: AccountIcon },
]

function currentLocation(): Promise<{ latitude: number; longitude: number } | undefined> {
  if (!navigator.geolocation) return Promise.resolve(undefined)
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => resolve({ latitude: position.coords.latitude, longitude: position.coords.longitude }),
      () => resolve(undefined),
      { enableHighAccuracy: true, timeout: 10_000, maximumAge: 30_000 },
    )
  })
}

export function RiderLayout() {
  const [profile, setProfile] = useState<RiderProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [toggleBusy, setToggleBusy] = useState(false)
  const [notice, setNotice] = useState('')

  const refreshProfile = useCallback(async () => {
    const response = await getRiderProfile()
    setProfile(response.profile)
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      void getRiderProfile(controller.signal)
        .then((response) => setProfile(response.profile))
        .catch((requestError: unknown) => {
          if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load your rider profile.')
        })
        .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [])

  async function toggleAvailability() {
    if (!profile || toggleBusy) return
    if (profile.availability_status === 'busy') {
      setNotice('Finish your current delivery first.')
      return
    }
    setToggleBusy(true)
    setNotice(profile.availability_status === 'offline' ? 'Getting your location…' : '')
    try {
      const location = profile.availability_status === 'offline' ? await currentLocation() : undefined
      const response = await toggleRiderAvailability(location)
      setProfile((current) => current ? { ...current, availability_status: response.availability_status } : current)
      setNotice(response.availability_status === 'available'
        ? location ? 'You’re online and ready for offers.' : 'You’re online. The last saved location will be used.'
        : 'You’re offline. New offers are paused.')
    } catch (requestError) {
      setNotice(isApiError(requestError) ? requestError.message : 'Availability could not be changed.')
    } finally {
      setToggleBusy(false)
    }
  }

  if (loading) return <RiderShellState message="Loading your rider workspace…" spinning />
  if (!profile) return <RiderShellState message={error || 'Your rider profile is unavailable.'} onRetry={() => { setLoading(true); setError(''); void getRiderProfile().then((response) => setProfile(response.profile)).catch((requestError) => setError(isApiError(requestError) ? requestError.message : 'Unable to load your rider profile.')).finally(() => setLoading(false)) }} />

  const avatar = apiAssetUrl(profile.avatar_path)

  return (
    <div className="min-h-svh bg-brand-black text-white">
      <div className="mx-auto min-h-svh w-full max-w-[34rem] border-x border-white/[0.05] bg-brand-black shadow-2xl">
        <header className="rider-safe-top sticky top-0 z-30 border-b border-white/10 bg-brand-black/95 px-4 pb-3 pt-3 backdrop-blur-xl">
          <div className="flex min-h-12 items-center gap-3">
            <Logo className="!w-28" />
            <div className="ml-auto min-w-0 text-right">
              <p className="truncate text-sm font-black text-white">{profile.name}</p>
              <p className={`mt-0.5 text-[10px] font-bold uppercase tracking-[0.13em] ${profile.availability_status === 'available' ? 'text-neon-green' : profile.availability_status === 'busy' ? 'text-amber-200' : 'text-white/35'}`}>{availabilityLabel(profile.availability_status)}</p>
            </div>
            {avatar ? <img src={avatar} alt="" className="h-10 w-10 rounded-full border border-white/10 object-cover" /> : <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-neon-green/10 text-sm font-black text-neon-green">{profile.name.charAt(0).toUpperCase()}</span>}
          </div>
        </header>

        {notice && <div role="status" className="sticky top-[calc(4.6rem+env(safe-area-inset-top))] z-20 flex items-center justify-between gap-3 border-b border-neon-green/15 bg-[#10220d] px-4 py-2.5 text-xs font-semibold text-neon-green"><span>{notice}</span><button type="button" onClick={() => setNotice('')} className="min-h-8 px-2">Dismiss</button></div>}

        <main className="px-4 pb-[calc(7.5rem+env(safe-area-inset-bottom))] pt-5 sm:px-5 sm:pt-6">
          <Outlet context={{ profile, refreshProfile } satisfies RiderOutletContext} />
        </main>

        <nav aria-label="Rider navigation" className="rider-safe-bottom fixed inset-x-0 bottom-0 z-40 mx-auto w-full max-w-[34rem] border-t border-white/10 bg-brand-surface/95 px-2 pt-2 shadow-[0_-16px_45px_rgba(0,0,0,0.42)] backdrop-blur-xl">
          <div className="grid grid-cols-5 items-end">
            <NavItem {...navigation[0]} />
            <NavItem {...navigation[1]} />
            <div className="flex justify-center">
              <button type="button" onClick={() => void toggleAvailability()} aria-label={availabilityAction(profile.availability_status)} aria-pressed={profile.availability_status !== 'offline'} className={`relative -mt-8 flex h-[4.5rem] w-[4.5rem] flex-col items-center justify-center rounded-full border-[5px] border-brand-black shadow-[0_10px_30px_rgba(0,0,0,0.55)] transition active:scale-95 ${profile.availability_status === 'available' ? 'bg-neon-green text-brand-black' : profile.availability_status === 'busy' ? 'bg-amber-300 text-brand-black' : 'bg-brand-surface-2 text-white/55 ring-1 ring-white/15'}`}>
                {toggleBusy ? <span className="h-6 w-6 animate-spin rounded-full border-2 border-current border-t-transparent" /> : profile.availability_status === 'busy' ? <LockIcon className="h-6 w-6" /> : <PowerIcon className="h-7 w-7" />}
                <span className="mt-0.5 text-[9px] font-black uppercase tracking-wide">{profile.availability_status === 'available' ? 'Online' : profile.availability_status === 'busy' ? 'Busy' : 'Offline'}</span>
              </button>
            </div>
            <NavItem {...navigation[2]} />
            <NavItem {...navigation[3]} />
          </div>
        </nav>
      </div>
    </div>
  )
}

function NavItem({ label, to, icon: Icon }: (typeof navigation)[number]) {
  return <NavLink to={to} className={({ isActive }) => `flex min-h-[3.75rem] flex-col items-center justify-center gap-1 rounded-xl text-[10px] font-bold transition ${isActive ? 'text-neon-green' : 'text-white/35 hover:text-white/70'}`}><Icon className="h-5 w-5"/><span>{label}</span></NavLink>
}

function RiderShellState({ message, spinning = false, onRetry }: { message: string; spinning?: boolean; onRetry?: () => void }) {
  return <div className="flex min-h-svh items-center justify-center bg-brand-black px-5 text-center text-white"><div>{spinning && <span className="mx-auto mb-4 block h-7 w-7 animate-spin rounded-full border-2 border-white/15 border-t-neon-green"/>}<p className="text-sm text-white/55">{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-4 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Retry</button>}</div></div>
}

function availabilityLabel(status: RiderAvailabilityStatus): string {
  return status === 'available' ? 'Online · accepting offers' : status === 'busy' ? 'Busy · active delivery' : 'Offline'
}

function availabilityAction(status: RiderAvailabilityStatus): string {
  return status === 'available' ? 'Go offline' : status === 'busy' ? 'Busy: finish your current delivery first' : 'Go online'
}

type IconProps = { className?: string }
function PoolIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9h13v-9M9 19v-5h6v5"/></svg> }
function ActiveIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg> }
function EarningsIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/><path d="m4 7 6-4 6 7 4-3"/></svg> }
function AccountIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg> }
function PowerIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" aria-hidden="true"><path d="M12 2v10"/><path d="M6.3 5.7a9 9 0 1 0 11.4 0"/></svg> }
function LockIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> }

import { useCallback, useEffect, useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { apiAssetUrl, getRiderProfile, isApiError, toggleRiderAvailability } from '../lib/api'
import type { RiderAvailabilityStatus, RiderProfile } from '../lib/api'
import { getBrowserLocation } from '../lib/geolocation'
import '../styles/rider-experience.css'

export interface RiderOutletContext {
  profile: RiderProfile
  refreshProfile: () => Promise<void>
}

const navigation = [
  { label: 'Order pool', shortLabel: 'Pool', to: '/rider/pool', icon: PoolIcon },
  { label: 'Active delivery', shortLabel: 'Active', to: '/rider/active', icon: ActiveIcon },
  { label: 'Earnings', shortLabel: 'Earnings', to: '/rider/earnings', icon: EarningsIcon },
  { label: 'My account', shortLabel: 'Account', to: '/rider/account', icon: AccountIcon },
]

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
      const location = profile.availability_status === 'offline' ? await getBrowserLocation() : undefined
      const response = await toggleRiderAvailability(location)
      setProfile((current) => current ? {
        ...current,
        availability_status: response.availability_status,
        current_latitude: response.latitude ?? current.current_latitude,
        current_longitude: response.longitude ?? current.current_longitude,
        last_location_at: response.last_location_at ?? current.last_location_at,
      } : current)
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
  const firstName = profile.name.trim().split(/\s+/)[0] || 'Rider'

  return (
    <div className={`fj-rider fj-rider--${profile.availability_status}`}>
      <aside className="fj-rider-sidebar">
        <NavLink to="/rider/pool" className="fj-rider-wordmark" aria-label="Foodjett rider home">
          <span>foodjett</span><BoltIcon className="fj-rider-wordmark-mark" />
        </NavLink>
        <p className="fj-rider-kicker">RIDER PARTNER</p>

        <nav className="fj-rider-sidebar-nav" aria-label="Rider navigation">
          {navigation.map(({ label, to, icon: Icon }) => (
            <NavLink key={to} to={to} className={({ isActive }) => `fj-rider-nav-link${isActive ? ' active' : ''}`}>
              <Icon className="fj-rider-nav-icon" />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="fj-rider-sidebar-bottom">
          <section className="fj-rider-shift-card">
            <span className="fj-rider-shift-label"><i /> {availabilityLabel(profile.availability_status)}</span>
            <h2>{profile.availability_status === 'available' ? 'Ready for the next run.' : profile.availability_status === 'busy' ? 'Delivery underway.' : 'Start when you’re ready.'}</h2>
            <p>{profile.availability_status === 'available' ? 'New nearby offers will arrive automatically.' : profile.availability_status === 'busy' ? 'Complete your current order before going offline.' : 'Go online to start receiving delivery offers.'}</p>
            <button type="button" onClick={() => void toggleAvailability()} disabled={toggleBusy || profile.availability_status === 'busy'} className="fj-rider-shift-button">
              {toggleBusy ? <span className="fj-rider-spinner" /> : profile.availability_status === 'busy' ? <LockIcon /> : <PowerIcon />}
              {toggleBusy ? 'Updating…' : availabilityAction(profile.availability_status)}
            </button>
          </section>

          <NavLink to="/rider/account" className="fj-rider-profile-link">
            <RiderAvatar name={profile.name} avatar={avatar} />
            <span><strong>{profile.name}</strong><small>{humanize(profile.vehicle_type)} rider</small></span>
          </NavLink>
        </div>
      </aside>

      <div className="fj-rider-workspace">
        <header className="fj-rider-topbar">
          <div className="fj-rider-location">
            <span className="fj-rider-location-icon"><PinIcon /></span>
            <span><small>YOUR DELIVERY AREA</small><strong>{profile.last_location_at ? `Location updated ${relativeLocationTime(profile.last_location_at)}` : 'Location not shared yet'}</strong></span>
          </div>
          <div className="fj-rider-topbar-profile">
            <span className="fj-rider-drive-mode"><CarIcon /> Car display</span>
            <span className="fj-rider-topbar-status"><i /> {availabilityLabel(profile.availability_status)}</span>
            <span className="fj-rider-greeting"><small>WELCOME BACK</small><strong>Hey, {firstName}</strong></span>
            <RiderAvatar name={profile.name} avatar={avatar} />
          </div>
        </header>

        {notice && <div role="status" className="fj-rider-notice"><span>{notice}</span><button type="button" onClick={() => setNotice('')}>Dismiss</button></div>}

        <main className="fj-rider-content">
          <Outlet context={{ profile, refreshProfile } satisfies RiderOutletContext} />
          <footer className="fj-rider-footer"><strong>Every delivery moves the neighborhood.</strong><span>Ride safe, powered by Foodjett.</span></footer>
        </main>
      </div>

      <nav aria-label="Rider mobile navigation" className="fj-rider-mobile-nav">
        <MobileNavItem {...navigation[0]} />
        <MobileNavItem {...navigation[1]} />
        <button type="button" onClick={() => void toggleAvailability()} disabled={toggleBusy || profile.availability_status === 'busy'} aria-label={availabilityAction(profile.availability_status)} aria-pressed={profile.availability_status !== 'offline'} className="fj-rider-mobile-power">
          {toggleBusy ? <span className="fj-rider-spinner" /> : profile.availability_status === 'busy' ? <LockIcon /> : <PowerIcon />}
          <span>{profile.availability_status === 'available' ? 'Online' : profile.availability_status === 'busy' ? 'Busy' : 'Offline'}</span>
        </button>
        <MobileNavItem {...navigation[2]} />
        <MobileNavItem {...navigation[3]} />
      </nav>
    </div>
  )
}

function RiderAvatar({ name, avatar }: { name: string; avatar: string | null }) {
  return avatar
    ? <img src={avatar} alt="" className="fj-rider-avatar" />
    : <span className="fj-rider-avatar fj-rider-avatar--initials">{initials(name)}</span>
}

function MobileNavItem({ shortLabel, to, icon: Icon }: (typeof navigation)[number]) {
  return <NavLink to={to} className={({ isActive }) => `fj-rider-mobile-link${isActive ? ' active' : ''}`}><Icon /><span>{shortLabel}</span></NavLink>
}

function RiderShellState({ message, spinning = false, onRetry }: { message: string; spinning?: boolean; onRetry?: () => void }) {
  return <div className="fj-rider-shell-state">{spinning && <span className="fj-rider-spinner" />}<p>{message}</p>{onRetry && <button type="button" onClick={onRetry}>Retry</button>}</div>
}

function initials(name: string) {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'FJ'
}

function humanize(value: string) {
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function relativeLocationTime(value: string) {
  const timestamp = new Date(value).getTime()
  if (!Number.isFinite(timestamp)) return 'recently'
  const minutes = Math.max(0, Math.round((Date.now() - timestamp) / 60_000))
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.round(minutes / 60)
  return hours < 24 ? `${hours}h ago` : new Date(value).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

function availabilityLabel(status: RiderAvailabilityStatus): string {
  return status === 'available' ? 'Online · accepting offers' : status === 'busy' ? 'Busy · active delivery' : 'Offline'
}

function availabilityAction(status: RiderAvailabilityStatus): string {
  return status === 'available' ? 'Go offline' : status === 'busy' ? 'Delivery in progress' : 'Go online'
}

type IconProps = { className?: string }
function PoolIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M4 7.5h16M6.5 4h11l1.5 3.5v12H5v-12L6.5 4Z"/><path d="M9 11h6M9 15h4"/></svg> }
function ActiveIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M6 19.5C3.5 17 3.5 13.5 6 11s6-2.5 8.5 0 2.5 6 0 8.5"/><path d="m10 15 2.2 2.2L20 9.5"/><circle cx="6" cy="6" r="2.5"/></svg> }
function EarningsIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/><path d="m4 7 6-4 6 7 4-3"/></svg> }
function AccountIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg> }
function PowerIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 2v10"/><path d="M6.3 5.7a9 9 0 1 0 11.4 0"/></svg> }
function LockIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> }
function PinIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg> }
function CarIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m5 11 1.8-5h10.4l1.8 5"/><path d="M3 11h18v7H3z"/><path d="M6 18v2m12-2v2"/><circle cx="7" cy="14.5" r="1" fill="currentColor" stroke="none"/><circle cx="17" cy="14.5" r="1" fill="currentColor" stroke="none"/></svg> }
function BoltIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m13 2-9 12h7l-1 8 10-13h-7Z"/></svg> }

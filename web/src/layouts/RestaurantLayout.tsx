import { useCallback, useEffect, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { Logo } from '../components/Logo'
import {
  apiAssetUrl,
  getRestaurantProfile,
  isApiError,
  updateRestaurantOperatingStatus,
} from '../lib/api'
import type { RestaurantOperatingStatus, RestaurantProfile } from '../lib/api'
import '../styles/restaurant-experience.css'

export interface RestaurantOutletContext {
  restaurant: RestaurantProfile
  updateRestaurant: (restaurant: RestaurantProfile) => void
}

const navigation = [
  { label: 'Orders', to: '/restaurant/dashboard', icon: OrdersIcon },
  { label: 'Menu', to: '/restaurant/menu', icon: MenuIcon },
  { label: 'Earnings', to: '/restaurant/earnings', icon: EarningsIcon },
  { label: 'Profile', to: '/restaurant/profile', icon: ProfileIcon },
  { label: 'Reviews', to: '/restaurant/reviews', icon: ReviewsIcon },
]

function statusLabel(status: RestaurantOperatingStatus): string {
  if (status === 'open') return 'Open for orders'
  if (status === 'temporarily_closed') return 'Temporarily closed'
  return 'Closed'
}

function StoreStatusControl({ status, busy, onToggle }: { status: RestaurantOperatingStatus; busy: boolean; onToggle: () => void }) {
  const open = status === 'open'
  return (
    <button
      type="button"
      onClick={onToggle}
      disabled={busy}
      aria-pressed={open}
      className={`fj-restaurant-status ${open ? 'is-open' : 'is-closed'}`}
    >
      <span className="fj-restaurant-status__switch" aria-hidden="true">
        <span />
      </span>
      <span className="fj-restaurant-status__copy">
        <strong>{busy ? 'Updating…' : statusLabel(status)}</strong>
        <small>Tap to {open ? 'close' : 'open'} store</small>
      </span>
    </button>
  )
}

export function RestaurantLayout() {
  const navigate = useNavigate()
  const { user, logout } = useAuth()
  const [restaurant, setRestaurant] = useState<RestaurantProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [statusBusy, setStatusBusy] = useState(false)
  const [statusMessage, setStatusMessage] = useState('')

  const loadProfile = useCallback((signal?: AbortSignal) => {
    return getRestaurantProfile(signal)
      .then((response) => setRestaurant(response.restaurant))
      .catch((requestError: unknown) => {
        if (signal?.aborted) return
        setError(isApiError(requestError) ? requestError.message : 'Unable to load your restaurant profile.')
      })
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    void loadProfile(controller.signal).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [loadProfile])

  async function toggleStore() {
    if (!restaurant || statusBusy) return
    setStatusBusy(true)
    setStatusMessage('')
    const nextStatus: RestaurantOperatingStatus = restaurant.operating_status === 'open' ? 'closed' : 'open'
    try {
      const response = await updateRestaurantOperatingStatus(nextStatus)
      setRestaurant((current) => current ? { ...current, operating_status: response.operating_status } : current)
      setStatusMessage(response.operating_status === 'open' ? 'Your store is now accepting orders.' : 'Your store is now closed to new orders.')
    } catch (requestError) {
      setStatusMessage(isApiError(requestError) ? requestError.message : 'The store status could not be updated.')
    } finally {
      setStatusBusy(false)
    }
  }

  function signOut() {
    logout()
    navigate('/login', { replace: true })
  }

  if (loading) {
    return (
      <div className="fj-restaurant-state">
        <span className="fj-restaurant-spinner" aria-label="Loading restaurant dashboard" />
      </div>
    )
  }

  if (!restaurant) {
    return (
      <div className="fj-restaurant-state">
        <section className="fj-restaurant-state__card">
          <span className="fj-restaurant-state__icon" aria-hidden="true">!</span>
          <h1>Restaurant unavailable</h1>
          <p>{error || 'Your restaurant profile is unavailable.'}</p>
          <div className="fj-restaurant-state__actions">
            <button type="button" onClick={() => { setLoading(true); setError(''); void loadProfile().finally(() => setLoading(false)) }} className="fj-restaurant-button fj-restaurant-button--primary">Try again</button>
            <button type="button" onClick={signOut} className="fj-restaurant-button fj-restaurant-button--secondary">Sign out</button>
          </div>
        </section>
      </div>
    )
  }

  const logo = apiAssetUrl(restaurant.logo_path)

  return (
    <div className="fj-restaurant">
      <aside className="fj-restaurant-sidebar">
        <div className="fj-restaurant-sidebar__brand">
          <Logo tone="light-surface" className="fj-restaurant-wordmark" />
          <p>RESTAURANT PARTNER</p>
        </div>

        <div className="fj-restaurant-identity">
          {logo ? <img src={logo} alt="" /> : <span>{restaurant.name.charAt(0).toUpperCase()}</span>}
          <div>
            <strong>{restaurant.name}</strong>
            <small>{restaurant.cuisine_type || 'Restaurant workspace'}</small>
          </div>
        </div>

        <nav aria-label="Restaurant navigation" className="fj-restaurant-sidebar__nav">
          {navigation.map(({ label, to, icon: Icon }) => (
            <NavLink key={to} to={to} className={({ isActive }) => `fj-restaurant-nav-link ${isActive ? 'is-active' : ''}`}>
              <Icon className="fj-restaurant-nav-link__icon" />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="fj-restaurant-sidebar__footer">
          <div className="fj-restaurant-user">
            <span>{user?.name?.charAt(0).toUpperCase() || 'R'}</span>
            <div><strong>{user?.name}</strong><small>Store owner</small></div>
          </div>
          <button type="button" onClick={signOut} className="fj-restaurant-signout"><LogoutIcon />Sign out</button>
        </div>
      </aside>

      <div className="fj-restaurant-workspace">
        <header className="fj-restaurant-topbar">
          <div className="fj-restaurant-topbar__identity">
            {logo ? <img src={logo} alt="" /> : <span>{restaurant.name.charAt(0).toUpperCase()}</span>}
            <div>
              <strong>{restaurant.name}</strong>
              <small>Kitchen command centre</small>
            </div>
          </div>
          <div className="fj-restaurant-topbar__copy">
            <p>RESTAURANT OPERATIONS</p>
            <span>Keep every order moving from kitchen to doorstep.</span>
          </div>
          <StoreStatusControl status={restaurant.operating_status} busy={statusBusy} onToggle={() => void toggleStore()} />
          <button type="button" onClick={signOut} className="fj-restaurant-mobile-signout" aria-label="Sign out"><LogoutIcon /></button>
        </header>

        {statusMessage && <div role="status" className="fj-restaurant-status-message">{statusMessage}</div>}

        <main className="fj-restaurant-main">
          <Outlet context={{ restaurant, updateRestaurant: setRestaurant } satisfies RestaurantOutletContext} />
        </main>

        <nav aria-label="Restaurant navigation" className="fj-restaurant-bottom-nav">
          {navigation.map(({ label, to, icon: Icon }) => (
            <NavLink key={to} to={to} className={({ isActive }) => `fj-restaurant-bottom-link ${isActive ? 'is-active' : ''}`}>
              <Icon />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>
      </div>
    </div>
  )
}

type IconProps = { className?: string }

function OrdersIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M6 3h12a2 2 0 0 1 2 2v16l-3-2-3 2-3-2-3 2-3-2-3 2V5a2 2 0 0 1 2-2h2Z"/><path d="M7 8h10M7 12h10M7 16h6"/></svg> }
function MenuIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="7" cy="6" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="10" cy="18" r="1" fill="currentColor"/></svg> }
function EarningsIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/><path d="m4 7 6-4 6 7 4-3"/></svg> }
function ProfileIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg> }
function ReviewsIcon({ className }: IconProps) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/></svg> }
function LogoutIcon() { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M13 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg> }

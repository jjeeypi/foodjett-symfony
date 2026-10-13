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
      className={`group flex min-h-12 items-center gap-3 rounded-xl border px-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green disabled:cursor-wait disabled:opacity-60 ${open ? 'border-neon-green/40 bg-neon-green/10' : 'border-white/10 bg-white/[0.04] hover:border-white/20'}`}
    >
      <span className={`relative h-7 w-12 shrink-0 rounded-full transition ${open ? 'bg-neon-green' : 'bg-white/15'}`} aria-hidden="true">
        <span className={`absolute top-1 h-5 w-5 rounded-full bg-brand-black shadow transition-transform ${open ? 'translate-x-6' : 'translate-x-1'}`} />
      </span>
      <span>
        <span className={`block text-xs font-black uppercase tracking-[0.14em] ${open ? 'text-neon-green' : 'text-white/65'}`}>{busy ? 'Updating…' : statusLabel(status)}</span>
        <span className="mt-0.5 block text-[11px] text-white/40">Tap to {open ? 'close' : 'open'} store</span>
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
      <div className="flex min-h-svh items-center justify-center bg-brand-black text-white">
        <span className="h-7 w-7 animate-spin rounded-full border-2 border-white/15 border-t-neon-green" aria-label="Loading restaurant dashboard" />
      </div>
    )
  }

  if (!restaurant) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-brand-black px-5 text-white">
        <section className="w-full max-w-lg rounded-2xl border border-red-400/20 bg-brand-surface p-6 text-center">
          <p className="text-sm leading-6 text-red-200">{error || 'Your restaurant profile is unavailable.'}</p>
          <div className="mt-5 flex flex-wrap justify-center gap-3">
            <button type="button" onClick={() => { setLoading(true); setError(''); void loadProfile().finally(() => setLoading(false)) }} className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-bold text-brand-black">Try again</button>
            <button type="button" onClick={signOut} className="min-h-11 rounded-xl border border-white/15 px-5 text-sm font-semibold text-white">Sign out</button>
          </div>
        </section>
      </div>
    )
  }

  const logo = apiAssetUrl(restaurant.logo_path)

  return (
    <div className="min-h-svh text-white lg:grid lg:grid-cols-[18rem_minmax(0,1fr)]">
      <a href="#restaurant-content" className="app-skip-link">Skip to content</a>
      <aside className="restaurant-safe-top hidden h-svh flex-col border-r border-white/10 bg-brand-surface lg:sticky lg:top-0 lg:flex">
        <div className="border-b border-white/10 px-5 pb-5 pt-6">
          <Logo className="!w-36" />
          <div className="mt-6 flex items-center gap-3">
            {logo ? <img src={logo} alt="" className="h-11 w-11 rounded-xl border border-white/10 object-cover" /> : <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-neon-green/10 text-lg font-black text-neon-green">{restaurant.name.charAt(0).toUpperCase()}</span>}
            <div className="min-w-0">
              <p className="truncate text-sm font-bold">{restaurant.name}</p>
              <p className="mt-0.5 text-xs text-white/40">Restaurant workspace</p>
            </div>
          </div>
        </div>

        <nav aria-label="Restaurant navigation" className="flex-1 space-y-1 px-3 py-5">
          {navigation.map(({ label, to, icon: Icon }) => (
            <NavLink key={to} to={to} className={({ isActive }) => `flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition ${isActive ? 'bg-neon-green text-brand-black shadow-[0_0_22px_rgba(57,255,20,0.14)]' : 'text-white/55 hover:bg-white/[0.05] hover:text-white'}`}>
              <Icon className="h-5 w-5" />
              {label}
            </NavLink>
          ))}
        </nav>

        <div className="space-y-3 border-t border-white/10 p-4">
          <button type="button" onClick={signOut} className="flex min-h-11 w-full items-center justify-center rounded-xl text-sm font-semibold text-white/45 transition hover:bg-white/[0.04] hover:text-white">Sign out</button>
        </div>
      </aside>

      <div className="min-w-0">
        <header className="restaurant-safe-top sticky top-0 z-30 border-b border-white/10 bg-brand-black/95 backdrop-blur-xl">
          <div className="flex min-h-16 items-center gap-3 px-4 sm:px-6 lg:min-h-20 lg:px-8">
            <div className="flex min-w-0 flex-1 items-center gap-3 lg:hidden">
              {logo ? <img src={logo} alt="" className="h-10 w-10 rounded-xl border border-white/10 object-cover" /> : <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-neon-green/10 font-black text-neon-green">{restaurant.name.charAt(0).toUpperCase()}</span>}
              <div className="min-w-0">
                <p className="truncate text-sm font-bold">{restaurant.name}</p>
                <p className="text-[11px] text-white/40">Signed in as {user?.name}</p>
              </div>
            </div>
            <div className="hidden min-w-0 flex-1 lg:block">
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-neon-green">Restaurant operations</p>
              <p className="mt-1 text-sm text-white/45">Keep orders moving from kitchen to pickup.</p>
            </div>
            <StoreStatusControl status={restaurant.operating_status} busy={statusBusy} onToggle={() => void toggleStore()} />
          </div>
          <nav aria-label="Restaurant navigation" className="flex gap-1 overflow-x-auto border-t border-white/[0.06] px-3 py-2 lg:hidden">
            {navigation.map(({ label, to, icon: Icon }) => (
              <NavLink key={to} to={to} className={({ isActive }) => `flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3 text-xs font-bold transition ${isActive ? 'bg-neon-green text-brand-black' : 'text-white/50 hover:bg-white/[0.05] hover:text-white'}`}>
                <Icon className="h-4 w-4" />
                {label}
              </NavLink>
            ))}
            <button type="button" onClick={signOut} className="min-h-11 shrink-0 rounded-xl px-3 text-xs font-bold text-red-300">Sign out</button>
          </nav>
        </header>

        {statusMessage && <div role="status" className="border-b border-neon-green/15 bg-neon-green/[0.06] px-4 py-2 text-center text-xs font-semibold text-neon-green sm:px-6 lg:px-8">{statusMessage}</div>}

        <main id="restaurant-content" tabIndex={-1} className="app-main mx-auto w-full max-w-[100rem] px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">
          <div className="page-enter">
            <Outlet context={{ restaurant, updateRestaurant: setRestaurant } satisfies RestaurantOutletContext} />
          </div>
        </main>
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

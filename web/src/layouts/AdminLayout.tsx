import { useEffect, useMemo, useState } from 'react'
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { Logo } from '../components/Logo'

type AdminNavItem = {
  label: string
  to: string
  icon: IconName
}

type IconName = 'approvals' | 'orders' | 'restaurants' | 'riders' | 'customers' | 'payments' | 'remittances' | 'vouchers' | 'reviews' | 'support' | 'audit' | 'settings'

const navigation: AdminNavItem[] = [
  { label: 'Approvals', to: '/admin/approvals', icon: 'approvals' },
  { label: 'Orders', to: '/admin/orders', icon: 'orders' },
  { label: 'Restaurants', to: '/admin/restaurants', icon: 'restaurants' },
  { label: 'Riders', to: '/admin/riders', icon: 'riders' },
  { label: 'Customers', to: '/admin/customers', icon: 'customers' },
  { label: 'Payments', to: '/admin/payments', icon: 'payments' },
  { label: 'Remittances', to: '/admin/remittances', icon: 'remittances' },
  { label: 'Vouchers', to: '/admin/vouchers', icon: 'vouchers' },
  { label: 'Reviews', to: '/admin/reviews', icon: 'reviews' },
  { label: 'Support', to: '/admin/support', icon: 'support' },
  { label: 'Audit Log', to: '/admin/audit-log', icon: 'audit' },
  { label: 'Platform Settings', to: '/admin/platform-settings', icon: 'settings' },
]

function isActivePath(pathname: string, item: AdminNavItem): boolean {
  if (item.icon === 'approvals') {
    return pathname === '/admin/dashboard' || pathname.startsWith('/admin/approvals')
  }

  return pathname === item.to || pathname.startsWith(`${item.to}/`)
}

function Navigation({ onNavigate }: { onNavigate?: () => void }) {
  const location = useLocation()

  return (
    <nav aria-label="Admin navigation" className="space-y-1 px-3 py-4">
      {navigation.map((item) => {
        const active = isActivePath(location.pathname, item)
        return (
          <Link
            key={item.to}
            to={item.to}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={`flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green ${active ? 'bg-neon-green text-brand-black shadow-[0_0_22px_rgba(57,255,20,0.12)]' : 'text-white/55 hover:bg-white/[0.05] hover:text-white'}`}
          >
            <AdminNavIcon name={item.icon} className="h-5 w-5 shrink-0" />
            <span>{item.label}</span>
          </Link>
        )
      })}
    </nav>
  )
}

export function AdminLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [drawerOpen, setDrawerOpen] = useState(false)

  useEffect(() => {
    if (!drawerOpen) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setDrawerOpen(false)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [drawerOpen])

  const pageTitle = useMemo(
    () => navigation.find((item) => isActivePath(location.pathname, item))?.label ?? 'Admin',
    [location.pathname],
  )

  function signOut() {
    logout()
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-svh bg-brand-black text-white lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
      <aside className="hidden h-svh flex-col border-r border-white/10 bg-brand-surface lg:sticky lg:top-0 lg:flex">
        <div className="border-b border-white/10 px-5 pb-5 pt-6">
          <Logo className="!w-36" />
          <div className="mt-6 rounded-xl border border-neon-green/15 bg-neon-green/[0.05] px-3 py-3">
            <p className="text-[10px] font-black uppercase tracking-[0.18em] text-neon-green">Admin workspace</p>
            <p className="mt-1 truncate text-sm font-bold text-white">{user?.name}</p>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto">
          <Navigation />
        </div>

        <div className="border-t border-white/10 p-4">
          <button type="button" onClick={signOut} className="flex min-h-11 w-full items-center justify-center rounded-xl text-sm font-semibold text-white/45 transition hover:bg-white/[0.04] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green">
            Sign out
          </button>
        </div>
      </aside>

      {drawerOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button type="button" aria-label="Close admin menu" className="absolute inset-0 bg-black/75 backdrop-blur-sm" onClick={() => setDrawerOpen(false)} />
          <aside className="admin-safe-top relative flex h-full w-[min(20rem,88vw)] flex-col border-r border-white/10 bg-brand-surface shadow-2xl">
            <div className="flex min-h-16 items-center justify-between border-b border-white/10 px-4">
              <Logo className="!w-32" />
              <button type="button" aria-label="Close menu" onClick={() => setDrawerOpen(false)} className="flex h-11 w-11 items-center justify-center rounded-xl text-2xl text-white/60 hover:bg-white/[0.05] hover:text-white">&times;</button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
              <Navigation onNavigate={() => setDrawerOpen(false)} />
            </div>
            <div className="border-t border-white/10 p-4">
              <p className="truncate px-2 text-sm font-bold text-white">{user?.name}</p>
              <button type="button" onClick={signOut} className="mt-3 min-h-11 w-full rounded-xl border border-white/10 text-sm font-semibold text-white/60">Sign out</button>
            </div>
          </aside>
        </div>
      )}

      <div className="min-w-0">
        <header className="admin-safe-top sticky top-0 z-40 border-b border-white/10 bg-brand-black/95 backdrop-blur-xl">
          <div className="flex min-h-16 items-center gap-3 px-4 sm:px-6 lg:min-h-20 lg:px-8">
            <button type="button" aria-label="Open admin menu" aria-expanded={drawerOpen} onClick={() => setDrawerOpen(true)} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-white/10 text-white/70 hover:border-neon-green/30 hover:text-neon-green lg:hidden">
              <span className="space-y-1.5" aria-hidden="true"><span className="block h-0.5 w-5 bg-current" /><span className="block h-0.5 w-5 bg-current" /><span className="block h-0.5 w-5 bg-current" /></span>
            </button>
            <div className="min-w-0 flex-1">
              <p className="text-[10px] font-black uppercase tracking-[0.18em] text-neon-green lg:text-xs">Foodjett operations</p>
              <h1 className="mt-0.5 truncate text-lg font-black text-white lg:text-xl">{pageTitle}</h1>
            </div>
            <div className="hidden text-right sm:block">
              <p className="text-sm font-bold text-white">{user?.name}</p>
              <p className="text-xs text-white/35">Administrator</p>
            </div>
          </div>
        </header>

        <main className="mx-auto w-full max-w-[100rem] px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function AdminNavIcon({ name, className }: { name: IconName; className?: string }) {
  const common = { className, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, 'aria-hidden': true as const }
  if (name === 'approvals') return <svg {...common}><path d="M12 3 4.5 6v5.4c0 4.8 3.2 8.1 7.5 9.6 4.3-1.5 7.5-4.8 7.5-9.6V6L12 3Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg>
  if (name === 'orders') return <svg {...common}><path d="M6 3h12a2 2 0 0 1 2 2v16l-3-2-3 2-3-2-3 2-3-2-3 2V5a2 2 0 0 1 2-2h2Z"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>
  if (name === 'restaurants') return <svg {...common}><path d="M4 21V9l8-5 8 5v12M9 21v-7h6v7M2 21h20"/></svg>
  if (name === 'riders') return <svg {...common}><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M9 17h6l-3-7H8m4 0 3-3h3"/></svg>
  if (name === 'customers') return <svg {...common}><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2"/><path d="M3 20a6 6 0 0 1 12 0M14 15a5 5 0 0 1 7 4.6"/></svg>
  if (name === 'payments') return <svg {...common}><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/></svg>
  if (name === 'remittances') return <svg {...common}><circle cx="12" cy="12" r="9"/><path d="M15.5 9.2c-.7-.8-1.8-1.2-3-1.2-1.7 0-3 .8-3 2s1 1.7 3 2 3 .9 3 2.1-1.3 2-3 2c-1.3 0-2.5-.5-3.2-1.4M12.5 6v12"/></svg>
  if (name === 'vouchers') return <svg {...common}><path d="m3 12 9-9h7a2 2 0 0 1 2 2v7l-9 9-9-9Z"/><circle cx="16.5" cy="7.5" r="1" fill="currentColor"/></svg>
  if (name === 'reviews') return <svg {...common}><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/></svg>
  if (name === 'support') return <svg {...common}><path d="M4 5h16v12H8l-4 4V5Z"/><path d="M8 9h8M8 13h5"/></svg>
  if (name === 'audit') return <svg {...common}><path d="M9 5H5v14h14v-4M9 9h6M9 13h3"/><path d="M15 3h6v6M21 3l-8 8"/></svg>
  return <svg {...common}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg>
}

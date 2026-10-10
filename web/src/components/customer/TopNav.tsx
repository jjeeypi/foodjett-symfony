import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import { useCart } from '../../cart/CartContext'
import { clearToken } from '../../lib/api'
import { Logo } from '../Logo'
import { CartIcon, CloseIcon, FoodIcon, HomeIcon, MessageIcon, OrdersIcon, SearchIcon, UserIcon } from './Icons'

function CartCount({ count }: { count: number }) {
  if (count < 1) return null

  return <span className="absolute -right-2 -top-2 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-neon-green px-1 text-[10px] font-black text-brand-black">{count > 99 ? '99+' : count}</span>
}

export function TopNav({ searchQuery, onSearchQueryChange }: { searchQuery: string; onSearchQueryChange: (query: string) => void }) {
  const navigate = useNavigate()
  const accountRef = useRef<HTMLDivElement>(null)
  const { itemCount, isCartOpen, openCart } = useCart()
  const [mobileSearchOpen, setMobileSearchOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)

  useEffect(() => {
    function handleOutsideClick(event: PointerEvent) {
      if (accountRef.current && !accountRef.current.contains(event.target as Node)) setAccountOpen(false)
    }
    function handleEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setAccountOpen(false)
        setMobileSearchOpen(false)
      }
    }

    document.addEventListener('pointerdown', handleOutsideClick)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('pointerdown', handleOutsideClick)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [])

  function logout() {
    clearToken()
    setAccountOpen(false)
    navigate('/login', { replace: true })
  }

  const desktopLinkClass = ({ isActive }: { isActive: boolean }) => `relative flex min-h-11 items-center px-2 text-sm font-semibold transition-colors after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full ${isActive ? 'text-neon-green after:bg-neon-green' : 'text-white/65 after:bg-transparent hover:text-white'}`
  const mobileLinkClass = ({ isActive }: { isActive: boolean }) => `flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg text-[10px] font-semibold transition-colors ${isActive ? 'text-neon-green' : 'text-white/55 hover:text-white'}`

  return (
    <header className="customer-top-nav sticky top-0 z-40 border-b border-white/10 bg-brand-black/95 backdrop-blur-xl">
      <div className="mx-auto grid min-h-16 max-w-[90rem] grid-cols-[auto_1fr_auto] items-center gap-3 px-4 sm:px-6 lg:px-8">
        <Link to="/" aria-label="Foodjett home" className="rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green">
          <Logo className="!w-32 md:!w-36 xl:!w-40" />
        </Link>

        <nav aria-label="Primary navigation" className="hidden items-stretch justify-center gap-1 md:flex lg:gap-3">
          <NavLink to="/" end className={desktopLinkClass}>Home</NavLink>
          <NavLink to="/foods" className={desktopLinkClass}>Foods</NavLink>
          <button type="button" onClick={openCart} className={`relative flex min-h-11 items-center px-2 text-sm font-semibold transition-colors after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full ${isCartOpen ? 'text-neon-green after:bg-neon-green' : 'text-white/65 after:bg-transparent hover:text-white'}`}>
            Cart<CartCount count={itemCount} />
          </button>
          <NavLink to="/messages" className={desktopLinkClass}>Messages</NavLink>
          <NavLink to="/orders" className={desktopLinkClass}>Orders</NavLink>
        </nav>

        <div className="flex items-center justify-end gap-2">
          <label className="relative hidden md:block">
            <span className="sr-only">Search restaurants</span>
            <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/35" />
            <input
              type="search"
              value={searchQuery}
              onChange={(event) => onSearchQueryChange(event.target.value)}
              placeholder="Search"
              className="h-11 w-32 rounded-xl border border-white/10 bg-white/[0.045] pl-9 pr-3 text-sm text-white outline-none transition placeholder:text-white/30 hover:border-white/20 focus:border-neon-green focus:ring-2 focus:ring-neon-green/15 lg:w-44 xl:w-56"
            />
          </label>

          <button type="button" onClick={() => setMobileSearchOpen((open) => !open)} className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 text-white/70 transition hover:border-neon-green/40 hover:text-neon-green focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green md:hidden" aria-label={mobileSearchOpen ? 'Close search' : 'Open search'} aria-expanded={mobileSearchOpen}>
            {mobileSearchOpen ? <CloseIcon className="h-5 w-5" /> : <SearchIcon className="h-5 w-5" />}
          </button>

          <div ref={accountRef} className="relative">
            <button type="button" onClick={() => setAccountOpen((open) => !open)} className={`flex h-11 w-11 items-center justify-center rounded-full border transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green ${accountOpen ? 'border-neon-green bg-neon-green text-brand-black' : 'border-white/10 bg-white/[0.04] text-white/70 hover:border-neon-green/40 hover:text-neon-green'}`} aria-label="Open account menu" aria-expanded={accountOpen} aria-haspopup="menu">
              <UserIcon className="h-5 w-5" />
            </button>
            {accountOpen && (
              <div role="menu" className="absolute right-0 top-[calc(100%+0.65rem)] w-52 overflow-hidden rounded-xl border border-white/10 bg-brand-surface p-1.5 shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
                <Link role="menuitem" to="/profile" onClick={() => setAccountOpen(false)} className="flex min-h-11 items-center rounded-lg px-3 text-sm text-white/70 transition hover:bg-white/[0.05] hover:text-white">Profile</Link>
                <Link role="menuitem" to="/addresses" onClick={() => setAccountOpen(false)} className="flex min-h-11 items-center rounded-lg px-3 text-sm text-white/70 transition hover:bg-white/[0.05] hover:text-white">Addresses</Link>
                <div className="my-1 border-t border-white/10" />
                <button role="menuitem" type="button" onClick={logout} className="flex min-h-11 w-full items-center rounded-lg px-3 text-left text-sm text-red-300 transition hover:bg-red-500/10 hover:text-red-200">Logout</button>
              </div>
            )}
          </div>
        </div>
      </div>

      {mobileSearchOpen && (
        <div className="border-t border-white/8 px-4 py-3 md:hidden">
          <label className="relative mx-auto block max-w-xl">
            <span className="sr-only">Search restaurants</span>
            <SearchIcon className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-white/35" />
            <input autoFocus type="search" value={searchQuery} onChange={(event) => onSearchQueryChange(event.target.value)} placeholder="Search restaurants or cuisines" className="h-12 w-full rounded-xl border border-neon-green/60 bg-brand-surface-2 pl-11 pr-4 text-base text-white outline-none placeholder:text-white/30 focus:border-neon-green focus:ring-2 focus:ring-neon-green/20" />
          </label>
        </div>
      )}

      <nav aria-label="Mobile navigation" className="grid grid-cols-5 border-t border-white/8 px-2 md:hidden">
        <NavLink to="/" end className={mobileLinkClass}><HomeIcon className="h-5 w-5" /><span>Home</span></NavLink>
        <NavLink to="/foods" className={mobileLinkClass}><FoodIcon className="h-5 w-5" /><span>Foods</span></NavLink>
        <button type="button" onClick={openCart} className={`relative flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg text-[10px] font-semibold transition-colors ${isCartOpen ? 'text-neon-green' : 'text-white/55 hover:text-white'}`}>
          <span className="relative"><CartIcon className="h-5 w-5" /><CartCount count={itemCount} /></span><span>Cart</span>
        </button>
        <NavLink to="/messages" className={mobileLinkClass}><MessageIcon className="h-5 w-5" /><span>Messages</span></NavLink>
        <NavLink to="/orders" className={mobileLinkClass}><OrdersIcon className="h-5 w-5" /><span>Orders</span></NavLink>
      </nav>
    </header>
  )
}

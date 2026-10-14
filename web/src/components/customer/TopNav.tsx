import { useEffect, useMemo, useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { useCart } from '../../cart/CartContext'
import { getCustomerAddresses } from '../../lib/api'
import {
  ArrowIcon,
  BikeIcon,
  BoltIcon,
  CartIcon,
  FoodIcon,
  HomeIcon,
  MessageIcon,
  OrdersIcon,
  PinIcon,
  UserIcon,
} from './Icons'

const navItems = [
  { to: '/', label: 'Explore', icon: HomeIcon, end: true },
  { to: '/foods', label: 'Foods', icon: FoodIcon },
  { to: '/orders', label: 'My orders', icon: OrdersIcon },
  { to: '/messages', label: 'Messages', icon: MessageIcon },
  { to: '/profile', label: 'My account', icon: UserIcon },
]

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || 'FJ'
}

export function TopNav() {
  const { user } = useAuth()
  const { itemCount, openCart } = useCart()
  const [deliveryAddress, setDeliveryAddress] = useState('Add a delivery address')

  useEffect(() => {
    const controller = new AbortController()
    void getCustomerAddresses(controller.signal)
      .then(({ addresses }) => {
        const selected = addresses.find((address) => address.is_default) ?? addresses[0]
        if (selected) setDeliveryAddress(selected.address_line)
      })
      .catch(() => undefined)

    return () => controller.abort()
  }, [])

  const firstName = useMemo(() => user?.name.trim().split(/\s+/)[0] || 'there', [user?.name])

  return (
    <>
      <aside className="fj-sidebar">
        <Link to="/" className="fj-wordmark" aria-label="Foodjett home">
          foodjett <BoltIcon width="22" height="22" />
        </Link>
        <p className="fj-sidebar-kicker">YOUR DAILY GOOD FOOD</p>

        <nav className="fj-sidebar-nav" aria-label="Customer navigation">
          {navItems.map(({ to, label, icon: Icon, end }) => (
            <NavLink key={to} to={to} end={end} className={({ isActive }) => `fj-nav-link${isActive ? ' active' : ''}`}>
              <Icon />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="fj-sidebar-bottom">
          <div className="fj-sidebar-promo">
            <BikeIcon width="30" height="30" />
            <strong>Good food. Great perks.</strong>
            <p>Your next favorite meal comes with a little extra.</p>
            <Link to="/foods">Explore good food <ArrowIcon width="16" height="16" /></Link>
          </div>
          <Link className="fj-profile-link" to="/profile">
            <span className="fj-avatar">{initials(user?.name ?? '')}</span>
            <span>
              <strong>Hey, {firstName}</strong>
              <small>Let&apos;s eat something good</small>
            </span>
          </Link>
        </div>
      </aside>

      <header className="fj-topbar">
        <Link to="/addresses" className="fj-delivery-address" aria-label={`Delivery address: ${deliveryAddress}`}>
          <span className="fj-pin-box"><PinIcon width="18" height="18" /></span>
          <span className="fj-address-copy">
            <small>DELIVER TO</small>
            <strong>{deliveryAddress}</strong>
          </span>
        </Link>

        <div className="fj-header-actions">
          <span className="fj-delivery-status"><i /> Delivering good food, every day</span>
          <button type="button" onClick={openCart} className="fj-cart-button" aria-label={`Open cart with ${itemCount} items`}>
            <CartIcon width="18" height="18" />
            <span className="fj-cart-label">Your cart</span>
            <span className="fj-cart-count">{itemCount}</span>
          </button>
        </div>
      </header>
    </>
  )
}

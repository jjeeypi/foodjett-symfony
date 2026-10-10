import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useCart } from '../cart/CartContext'
import type { AddCartItem, CartItem } from '../cart/CartContext'
import { ItemOptionsModal } from '../components/customer/ItemOptionsModal'
import type { ItemConfiguration } from '../components/customer/ItemOptionsModal'
import { MenuItemCard } from '../components/customer/MenuItemCard'
import { CartIcon, CloseIcon } from '../components/customer/Icons'
import { apiAssetUrl, getRestaurant, isApiError } from '../lib/api'
import type { CustomerMenuItem, MenuItemAddon, MenuItemVariant, RestaurantDetail } from '../lib/api'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

type RestaurantRequestState =
  | { key: string; status: 'loading' }
  | { key: string; status: 'success'; restaurant: RestaurantDetail }
  | { key: string; status: 'error'; message: string; statusCode: number }

function lineId(item: CustomerMenuItem, variant: MenuItemVariant | null = null, addons: MenuItemAddon[] = []): string {
  return [item.id, variant?.id ?? 'base', ...addons.map((addon) => addon.id).sort()].join(':')
}

function isAvailableNow(item: CustomerMenuItem): boolean {
  if (!item.is_available) return false
  if (!item.available_from && !item.available_until) return true
  const now = new Date()
  const time = now.getHours() * 60 + now.getMinutes()
  const minutes = (value: string) => {
    const [hours, mins] = value.split(':').map(Number)
    return hours * 60 + mins
  }
  const start = item.available_from ? minutes(item.available_from) : null
  const end = item.available_until ? minutes(item.available_until) : null
  if (start !== null && end !== null && start > end) return time >= start || time <= end

  return (start === null || time >= start) && (end === null || time <= end)
}

function RestaurantPageSkeleton() {
  return (
    <div aria-label="Loading restaurant menu" aria-busy="true">
      <div className="h-52 animate-pulse rounded-3xl bg-white/[0.06] sm:h-72" />
      <div className="mx-4 -mt-10 h-28 animate-pulse rounded-2xl border border-white/8 bg-brand-surface sm:mx-8" />
      <div className="mt-10 h-7 w-40 animate-pulse rounded bg-white/[0.07]" />
      <div className="mt-5 grid gap-4 md:grid-cols-2">
        {Array.from({ length: 6 }, (_, index) => <div key={index} className="h-44 animate-pulse rounded-2xl border border-white/8 bg-brand-surface" />)}
      </div>
    </div>
  )
}

export default function RestaurantPage() {
  const { id = '' } = useParams()
  const [attempt, setAttempt] = useState(0)
  const requestKey = `${id}:${attempt}`
  const [requestState, setRequestState] = useState<RestaurantRequestState>({ key: '', status: 'loading' })
  const [selectedItem, setSelectedItem] = useState<CustomerMenuItem | null>(null)
  const [pendingReplacement, setPendingReplacement] = useState<AddCartItem | null>(null)
  const { items, itemCount, subtotal, cartRestaurantId, addItem, replaceCart, updateQuantity, openCart } = useCart()

  useEffect(() => {
    const controller = new AbortController()
    getRestaurant(id, controller.signal)
      .then(({ restaurant }) => setRequestState({ key: requestKey, status: 'success', restaurant }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        setRequestState({
          key: requestKey,
          status: 'error',
          message: isApiError(error) ? error.message : 'We could not load this restaurant.',
          statusCode: isApiError(error) ? error.status : 0,
        })
      })
    return () => controller.abort()
  }, [id, requestKey])

  const activeState: RestaurantRequestState = requestState.key === requestKey
    ? requestState
    : { key: requestKey, status: 'loading' }
  const restaurant = activeState.status === 'success' ? activeState.restaurant : null
  const restaurantCartItems = useMemo(() => restaurant ? items.filter((item) => item.restaurantId === restaurant.id) : [], [items, restaurant])

  if (activeState.status === 'loading') return <RestaurantPageSkeleton />

  if (activeState.status === 'error') {
    const notFound = activeState.statusCode === 404
    return (
      <section className="mx-auto max-w-2xl rounded-2xl border border-red-400/25 bg-red-500/[0.07] px-6 py-12 text-center">
        <h1 className="text-2xl font-black text-white">{notFound ? 'Restaurant not found' : 'Restaurant could not be loaded'}</h1>
        <p className="mx-auto mt-3 max-w-lg text-sm leading-6 text-white/55">{notFound ? 'This restaurant may no longer be available.' : activeState.message}</p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          {!notFound && <button type="button" onClick={() => setAttempt((current) => current + 1)} className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-bold text-brand-black">Try again</button>}
          <Link to="/" className="inline-flex min-h-11 items-center rounded-xl border border-white/15 px-5 text-sm font-semibold text-white/70 transition hover:border-neon-green/40 hover:text-white">Back to home</Link>
        </div>
      </section>
    )
  }

  if (!restaurant) return null

  const coverUrl = apiAssetUrl(restaurant.cover_photo_path)
  const logoUrl = apiAssetUrl(restaurant.logo_path)
  const orderingDisabled = !restaurant.is_open_now
  const totalMenuItems = restaurant.menu_categories.reduce((total, category) => total + category.items.length, 0)
  const restaurantId = restaurant.id
  const restaurantName = restaurant.name

  function configuredCartItem(item: CustomerMenuItem, configuration: ItemConfiguration): AddCartItem {
    const addons = [...configuration.addons].sort((left, right) => left.id.localeCompare(right.id))
    const unitPrice = Number(item.base_price)
      + Number(configuration.variant?.price_delta ?? 0)
      + addons.reduce((total, addon) => total + Number(addon.price), 0)

    return {
      id: lineId(item, configuration.variant, addons),
      restaurantId,
      restaurantName,
      menuItemId: item.id,
      name: item.name,
      quantity: configuration.quantity,
      unitPrice,
      thumbnailUrl: apiAssetUrl(item.photo_path),
      variant: configuration.variant ? { id: configuration.variant.id, name: configuration.variant.name } : null,
      addons: addons.map((addon) => ({ id: addon.id, name: addon.name, price: Number(addon.price) })),
    }
  }

  function attemptAdd(item: AddCartItem) {
    if (addItem(item) === 'restaurant_conflict') setPendingReplacement(item)
  }

  function addSimpleItem(item: CustomerMenuItem) {
    attemptAdd(configuredCartItem(item, { variant: null, addons: [], quantity: 1 }))
  }

  function itemLines(item: CustomerMenuItem): CartItem[] {
    return restaurantCartItems.filter((cartItem) => cartItem.menuItemId === item.id)
  }

  return (
    <div className={cartRestaurantId === restaurant.id && itemCount > 0 ? 'pb-24 md:pb-0' : ''}>
      <Link to="/" className="mb-4 inline-flex min-h-11 items-center text-sm font-semibold text-white/55 transition hover:text-neon-green">← Back to restaurants</Link>

      <section className="overflow-hidden rounded-3xl border border-white/10 bg-brand-surface">
        <div className="relative flex h-48 items-center justify-center overflow-hidden bg-[radial-gradient(circle_at_30%_15%,rgba(57,255,20,0.2),transparent_48%),linear-gradient(135deg,#181818,#0b0b0b)] sm:h-64 lg:h-72">
          {coverUrl && <img src={coverUrl} alt={`${restaurant.name} cover`} className="h-full w-full object-cover" />}
          <div className="absolute inset-0 bg-gradient-to-t from-brand-black/70 via-transparent to-transparent" />
        </div>
        <div className="relative px-5 pb-6 sm:px-8 sm:pb-8">
          <div className="-mt-12 mb-4 flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border-4 border-brand-surface bg-brand-surface-2 shadow-xl sm:h-28 sm:w-28">
            {logoUrl ? <img src={logoUrl} alt={`${restaurant.name} logo`} className="h-full w-full object-cover" /> : <span className="text-4xl font-black text-neon-green">{restaurant.name.charAt(0).toUpperCase()}</span>}
          </div>
          <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div>
              <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-black tracking-tight text-white sm:text-3xl">{restaurant.name}</h1>
                <span className={`rounded-full border px-3 py-1 text-xs font-bold ${restaurant.is_open_now ? 'border-neon-green/25 bg-neon-green/10 text-neon-green' : 'border-red-400/20 bg-red-500/10 text-red-300'}`}>{restaurant.is_open_now ? 'Open now' : 'Closed'}</span>
              </div>
              <p className="mt-2 text-sm text-white/45">{restaurant.cuisine_type || 'Local favorites'} · <span className="text-neon-green">★</span> {restaurant.review_count > 0 ? `${restaurant.rating.toFixed(1)} (${restaurant.review_count})` : 'New'}</p>
              {restaurant.description && <p className="mt-4 max-w-3xl text-sm leading-6 text-white/55">{restaurant.description}</p>}
              <p className="mt-3 text-sm text-white/40">{restaurant.address}</p>
            </div>
            <div className="flex shrink-0 gap-4 text-xs text-white/45 sm:text-right">
              <div><span className="block font-bold text-white">{restaurant.default_prep_time_minutes} min</span>Prep time</div>
              <div><span className="block font-bold text-white">{currency.format(Number(restaurant.min_order_amount))}</span>Minimum</div>
            </div>
          </div>
        </div>
      </section>

      {orderingDisabled && (
        <div role="status" className="mt-5 rounded-2xl border border-red-400/25 bg-red-500/[0.08] px-5 py-4 text-sm font-semibold text-red-200">This restaurant is currently closed — ordering is disabled.</div>
      )}

      <section className="mt-10" aria-labelledby="menu-heading">
        <div className="mb-6">
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-neon-green">Browse the menu</p>
          <h2 id="menu-heading" className="mt-2 text-2xl font-black text-white">Menu</h2>
        </div>

        {totalMenuItems === 0 ? (
          <div className="rounded-2xl border border-white/10 bg-brand-surface px-6 py-14 text-center">
            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-neon-green/[0.07] text-2xl">🍽</div>
            <h3 className="text-lg font-bold text-white">Menu coming soon</h3>
            <p className="mt-2 text-sm text-white/45">This restaurant has not published any menu items yet.</p>
          </div>
        ) : (
          <div className="space-y-10">
            {restaurant.menu_categories.filter((category) => category.items.length > 0).map((category) => (
              <section key={category.id} aria-labelledby={`category-${category.id}`}>
                <div className="mb-4 flex items-end justify-between border-b border-white/8 pb-3">
                  <h3 id={`category-${category.id}`} className="text-lg font-bold text-white sm:text-xl">{category.name}</h3>
                  <span className="text-xs text-white/35">{category.items.length} item{category.items.length === 1 ? '' : 's'}</span>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                  {category.items.map((item) => {
                    const hasOptions = item.variants.length > 0 || item.addons.some((addon) => addon.is_available)
                    const lines = itemLines(item)
                    const simpleId = lineId(item)
                    const simpleLine = lines.find((line) => line.id === simpleId)
                    return (
                      <MenuItemCard
                        key={item.id}
                        item={item}
                        available={isAvailableNow(item)}
                        orderingDisabled={orderingDisabled}
                        quantity={simpleLine?.quantity ?? 0}
                        configuredCount={lines.reduce((total, line) => total + line.quantity, 0)}
                        onAdd={() => hasOptions ? setSelectedItem(item) : addSimpleItem(item)}
                        onDecrease={() => simpleLine && updateQuantity(simpleLine.id, simpleLine.quantity - 1)}
                      />
                    )
                  })}
                </div>
              </section>
            ))}
          </div>
        )}
      </section>

      {selectedItem && (
        <ItemOptionsModal
          key={selectedItem.id}
          item={selectedItem}
          onClose={() => setSelectedItem(null)}
          onConfirm={(configuration) => {
            attemptAdd(configuredCartItem(selectedItem, configuration))
            setSelectedItem(null)
          }}
        />
      )}

      {pendingReplacement && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="replace-cart-title">
          <button type="button" className="absolute inset-0 bg-black/80 backdrop-blur-[2px]" onClick={() => setPendingReplacement(null)} aria-label="Keep existing cart" />
          <section className="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-6 shadow-2xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Different restaurant</p>
                <h2 id="replace-cart-title" className="mt-2 text-xl font-bold text-white">Replace your current cart?</h2>
              </div>
              <button type="button" onClick={() => setPendingReplacement(null)} className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-white/10 text-white/60" aria-label="Close"><CloseIcon className="h-5 w-5" /></button>
            </div>
            <p className="mt-4 text-sm leading-6 text-white/55">Your cart has items from <strong className="text-white">{items[0]?.restaurantName || 'another restaurant'}</strong>. Foodjett checkout supports one restaurant per order, so those items must be cleared first.</p>
            <div className="mt-6 grid gap-3 sm:grid-cols-2">
              <button type="button" onClick={() => setPendingReplacement(null)} className="min-h-11 rounded-xl border border-white/15 px-4 text-sm font-semibold text-white/70 transition hover:border-white/30 hover:text-white">Keep current cart</button>
              <button type="button" onClick={() => { replaceCart(pendingReplacement); setPendingReplacement(null) }} className="min-h-11 rounded-xl bg-neon-green px-4 text-sm font-bold text-brand-black transition hover:bg-[#55ff35]">Clear and add item</button>
            </div>
          </section>
        </div>
      )}

      {cartRestaurantId === restaurant.id && itemCount > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-neon-green/20 bg-brand-black/95 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-xl md:hidden">
          <button type="button" onClick={openCart} className="mx-auto flex min-h-12 w-full max-w-md items-center justify-between rounded-xl bg-neon-green px-5 font-bold text-brand-black shadow-[0_0_24px_rgba(57,255,20,0.16)]">
            <span className="flex items-center gap-2"><CartIcon className="h-5 w-5" />View cart ({itemCount})</span>
            <span>{currency.format(subtotal)}</span>
          </button>
        </div>
      )}
    </div>
  )
}

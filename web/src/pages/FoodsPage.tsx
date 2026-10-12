import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useCart } from '../cart/CartContext'
import type { AddCartItem } from '../cart/CartContext'
import { ItemOptionsModal } from '../components/customer/ItemOptionsModal'
import type { ItemConfiguration } from '../components/customer/ItemOptionsModal'
import { apiAssetUrl, getAvailableMenuItems, isApiError } from '../lib/api'
import type { FoodMenuItem, PaginationMeta } from '../lib/api'

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export default function FoodsPage() {
  const [params, setParams] = useSearchParams()
  const page = positivePage(params.get('page'))
  const q = params.get('q') ?? ''
  const category = params.get('category') ?? ''
  const minPrice = params.get('min_price') ?? ''
  const maxPrice = params.get('max_price') ?? ''
  const [query, setQuery] = useState(q)
  const [items, setItems] = useState<FoodMenuItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 24, total: 0, lastPage: 1 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [configuring, setConfiguring] = useState<FoodMenuItem | null>(null)
  const [pendingReplacement, setPendingReplacement] = useState<AddCartItem | null>(null)
  const { addItem, replaceCart, openCart } = useCart()

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getAvailableMenuItems({ q, category, minPrice, maxPrice, page }, controller.signal).then((response) => { setItems(response.data); setMeta(response.meta) }).catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load foods.') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [category, maxPrice, minPrice, page, q, retry])

  const categories = useMemo(() => Array.from(new Set(items.map((item) => item.category.name))).sort(), [items])

  function apply(next: { q?: string; category?: string; minPrice?: string; maxPrice?: string; page?: number }) {
    const result = new URLSearchParams()
    const values = { q, category, minPrice, maxPrice, ...next }
    if (values.q) result.set('q', values.q)
    if (values.category) result.set('category', values.category)
    if (values.minPrice) result.set('min_price', values.minPrice)
    if (values.maxPrice) result.set('max_price', values.maxPrice)
    if ((values.page ?? 1) > 1) result.set('page', String(values.page))
    setParams(result)
  }

  function configured(item: FoodMenuItem, configuration: ItemConfiguration): AddCartItem {
    const addons = [...configuration.addons].sort((left, right) => left.id.localeCompare(right.id))
    return { id: `${item.id}:${configuration.variant?.id ?? 'base'}:${addons.map((addon) => addon.id).join(',')}`, restaurantId: item.restaurant.id, restaurantName: item.restaurant.name, menuItemId: item.id, name: item.name, quantity: configuration.quantity, unitPrice: Number(item.base_price) + Number(configuration.variant?.price_delta ?? 0) + addons.reduce((sum, addon) => sum + Number(addon.price), 0), thumbnailUrl: apiAssetUrl(item.photo_path), variant: configuration.variant ? { id: configuration.variant.id, name: configuration.variant.name } : null, addons: addons.map((addon) => ({ id: addon.id, name: addon.name, price: Number(addon.price) })) }
  }

  function attempt(item: AddCartItem) { if (addItem(item) === 'restaurant_conflict') setPendingReplacement(item); else openCart() }
  function add(item: FoodMenuItem) { const hasOptions = item.variants.length > 0 || item.addons.some((addon) => addon.is_available); if (hasOptions) setConfiguring(item); else attempt(configured(item, { variant: null, addons: [], quantity: 1 })) }

  return <div><header><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Browse dishes</p><h1 className="mt-2 text-3xl font-black text-white sm:text-4xl">Foods</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">Available dishes from approved restaurants that are open right now.</p></header>
    <form onSubmit={(event) => { event.preventDefault(); apply({ q: query.trim(), page: 1 }) }} className="mt-6 grid gap-3 rounded-2xl border border-white/10 bg-brand-surface p-4 sm:grid-cols-[minmax(0,1fr)_9rem_9rem_auto]"><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search dishes" className="min-h-11 rounded-xl border border-white/10 bg-brand-black px-4 text-sm text-white outline-none focus:border-neon-green" /><input value={minPrice} onChange={(event) => apply({ minPrice: event.target.value, page: 1 })} inputMode="decimal" placeholder="Min price" className="min-h-11 rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none focus:border-neon-green" /><input value={maxPrice} onChange={(event) => apply({ maxPrice: event.target.value, page: 1 })} inputMode="decimal" placeholder="Max price" className="min-h-11 rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none focus:border-neon-green" /><button className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Search</button></form>
    {categories.length > 0 && <div className="mt-4 flex gap-2 overflow-x-auto pb-1"><button type="button" onClick={() => apply({ category: '', page: 1 })} className={`min-h-10 shrink-0 rounded-full border px-4 text-xs font-bold ${!category ? 'border-neon-green bg-neon-green text-brand-black' : 'border-white/10 text-white/55'}`}>All</button>{categories.map((name) => <button key={name} type="button" onClick={() => apply({ category: name, page: 1 })} className={`min-h-10 shrink-0 rounded-full border px-4 text-xs font-bold ${category === name ? 'border-neon-green bg-neon-green text-brand-black' : 'border-white/10 text-white/55'}`}>{name}</button>)}</div>}
    {loading ? <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4" aria-busy="true">{Array.from({ length: 8 }, (_, index) => <div key={index} className="h-80 animate-pulse rounded-2xl bg-brand-surface" />)}</div> : error ? <section role="alert" className="mt-6 rounded-2xl border border-red-400/20 bg-red-400/[0.06] p-10 text-center"><p className="text-sm text-red-100/75">{error}</p><button onClick={() => setRetry((value) => value + 1)} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section> : items.length === 0 ? <section className="mt-6 rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 p-12 text-center"><h2 className="font-black text-white">No dishes found</h2><p className="mt-2 text-sm text-white/40">Try another search or price range.</p></section> : <><div className="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4">{items.map((item) => <article key={item.id} className="overflow-hidden rounded-2xl border border-white/10 bg-brand-surface"><div className="aspect-square bg-white/[0.03]">{apiAssetUrl(item.photo_path) ? <img src={apiAssetUrl(item.photo_path) ?? ''} alt="" className="h-full w-full object-cover" /> : <span className="flex h-full items-center justify-center text-4xl font-black text-neon-green/60">{item.name.charAt(0)}</span>}</div><div className="p-3 sm:p-4"><h2 className="line-clamp-1 font-black text-white">{item.name}</h2><Link to={`/restaurants/${item.restaurant.id}`} className="mt-1 block truncate text-xs text-neon-green/75 hover:text-neon-green">{item.restaurant.name}</Link><p className="mt-1 text-xs text-white/35">{item.category.name}</p><div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><strong className="text-sm text-white">{peso.format(Number(item.base_price))}</strong><button type="button" onClick={() => add(item)} className="min-h-10 rounded-xl bg-neon-green px-4 text-xs font-black text-brand-black">Add</button></div></div></article>)}</div><nav className="mt-6 flex items-center justify-between"><button disabled={meta.page <= 1} onClick={() => apply({ page: meta.page - 1 })} className="min-h-11 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/60 disabled:opacity-30">Previous</button><span className="text-xs text-white/35">Page {meta.page} of {meta.lastPage}</span><button disabled={meta.page >= meta.lastPage} onClick={() => apply({ page: meta.page + 1 })} className="min-h-11 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/60 disabled:opacity-30">Next</button></nav></>}
    {configuring && <ItemOptionsModal item={configuring} onClose={() => setConfiguring(null)} onConfirm={(configuration) => { attempt(configured(configuring, configuration)); setConfiguring(null) }} />}
    {pendingReplacement && <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center"><section role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-6"><h2 className="text-xl font-black text-white">Start a new cart?</h2><p className="mt-2 text-sm leading-6 text-white/45">Your cart contains items from another restaurant. Clear it and add this dish instead?</p><div className="mt-6 grid grid-cols-2 gap-3"><button onClick={() => setPendingReplacement(null)} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/60">Keep cart</button><button onClick={() => { replaceCart(pendingReplacement); setPendingReplacement(null); openCart() }} className="min-h-12 rounded-xl bg-neon-green text-sm font-black text-brand-black">Replace cart</button></div></section></div>}
  </div>
}

function positivePage(value: string | null) { const parsed = Number(value ?? '1'); return Number.isInteger(parsed) && parsed > 0 ? parsed : 1 }

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { ConfirmDialog } from '../../components/restaurant/ConfirmDialog'
import { MenuItemDialog } from '../../components/restaurant/MenuItemDialog'
import { MenuOptionsDialog } from '../../components/restaurant/MenuOptionsDialog'
import {
  apiAssetUrl,
  createRestaurantMenuCategory,
  deleteRestaurantMenuCategory,
  deleteRestaurantMenuItem,
  getRestaurantMenuCategories,
  getRestaurantMenuItems,
  isApiError,
  toggleRestaurantMenuItemAvailability,
  updateRestaurantMenuCategory,
} from '../../lib/api'
import type { RestaurantMenuCategory, RestaurantMenuItem } from '../../lib/api'

type CategoryDelete = RestaurantMenuCategory | null
type ItemDelete = RestaurantMenuItem | null

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function RestaurantMenuPage() {
  const [categories, setCategories] = useState<RestaurantMenuCategory[]>([])
  const [items, setItems] = useState<RestaurantMenuItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [actionError, setActionError] = useState('')
  const [busy, setBusy] = useState('')
  const [categoryName, setCategoryName] = useState('')
  const [editingCategory, setEditingCategory] = useState<RestaurantMenuCategory | null>(null)
  const [categoryDelete, setCategoryDelete] = useState<CategoryDelete>(null)
  const [itemDelete, setItemDelete] = useState<ItemDelete>(null)
  const [itemDialog, setItemDialog] = useState<RestaurantMenuItem | 'new' | null>(null)
  const [optionsItem, setOptionsItem] = useState<RestaurantMenuItem | null>(null)
  const [selectedCategory, setSelectedCategory] = useState('all')

  const loadMenu = useCallback(async (signal?: AbortSignal) => {
    setError('')
    try {
      const [categoryResponse, itemResponse] = await Promise.all([
        getRestaurantMenuCategories(signal),
        getRestaurantMenuItems(signal),
      ])
      setCategories(categoryResponse.data)
      setItems(itemResponse.data)
    } catch (requestError) {
      if (signal?.aborted) return
      setError(isApiError(requestError) ? requestError.message : 'Unable to load your menu.')
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      void loadMenu(controller.signal).finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    })
    return () => controller.abort()
  }, [loadMenu])

  const visibleCategories = useMemo(() => selectedCategory === 'all'
    ? categories
    : categories.filter((category) => category.id === selectedCategory), [categories, selectedCategory])

  function showNotice(message: string) {
    setNotice(message)
    setActionError('')
    window.setTimeout(() => setNotice((current) => current === message ? '' : current), 5000)
  }

  function updateItem(next: RestaurantMenuItem, message: string) {
    setItems((current) => current.map((entry) => entry.id === next.id ? next : entry))
    setOptionsItem((current) => current?.id === next.id ? next : current)
    showNotice(message)
  }

  async function saveCategory(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const name = categoryName.trim()
    if (!name) {
      setActionError('Enter a category name.')
      return
    }
    setBusy('category-save')
    setActionError('')
    try {
      if (editingCategory) {
        const response = await updateRestaurantMenuCategory(editingCategory.id, { name })
        setCategories((current) => current.map((entry) => entry.id === response.category.id ? response.category : entry))
        showNotice(response.message)
      } else {
        const nextOrder = categories.length === 0 ? 0 : Math.max(...categories.map((category) => category.sort_order)) + 10
        const response = await createRestaurantMenuCategory(name, nextOrder)
        setCategories((current) => [...current, response.category])
        showNotice(response.message)
      }
      setCategoryName('')
      setEditingCategory(null)
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'The category could not be saved.')
    } finally {
      setBusy('')
    }
  }

  async function moveCategory(index: number, direction: -1 | 1) {
    const nextIndex = index + direction
    if (nextIndex < 0 || nextIndex >= categories.length) return
    const reordered = [...categories]
    const [moved] = reordered.splice(index, 1)
    reordered.splice(nextIndex, 0, moved)
    const normalized = reordered.map((category, position) => ({ ...category, sort_order: position * 10 }))
    setCategories(normalized)
    setBusy('category-reorder')
    setActionError('')
    try {
      await Promise.all(normalized.map((category, position) => {
        const original = categories.find((entry) => entry.id === category.id)
        return original?.sort_order === position * 10
          ? Promise.resolve(null)
          : updateRestaurantMenuCategory(category.id, { sort_order: position * 10 })
      }))
      showNotice('Category order updated.')
    } catch (requestError) {
      await loadMenu()
      setActionError(isApiError(requestError) ? requestError.message : 'Category order could not be updated.')
    } finally {
      setBusy('')
    }
  }

  async function confirmCategoryDelete() {
    if (!categoryDelete || categoryDelete.item_count > 0) return
    setBusy('category-delete')
    setActionError('')
    try {
      const response = await deleteRestaurantMenuCategory(categoryDelete.id)
      setCategories((current) => current.filter((entry) => entry.id !== categoryDelete.id))
      if (selectedCategory === categoryDelete.id) setSelectedCategory('all')
      setCategoryDelete(null)
      showNotice(response.message)
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'The category could not be deleted.')
      setCategoryDelete(null)
    } finally {
      setBusy('')
    }
  }

  async function toggleItem(item: RestaurantMenuItem) {
    setBusy(`item-toggle-${item.id}`)
    setActionError('')
    try {
      const response = await toggleRestaurantMenuItemAvailability(item.id, !item.is_available)
      setItems((current) => current.map((entry) => entry.id === item.id ? { ...entry, is_available: response.is_available } : entry))
      showNotice(response.message)
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'Item availability could not be changed.')
    } finally {
      setBusy('')
    }
  }

  async function confirmItemDelete() {
    if (!itemDelete) return
    const deleting = itemDelete
    setBusy('item-delete')
    setActionError('')
    try {
      const response = await deleteRestaurantMenuItem(deleting.id)
      setItems((current) => current.filter((entry) => entry.id !== deleting.id))
      setCategories((current) => current.map((category) => category.id === deleting.category.id ? { ...category, item_count: Math.max(0, category.item_count - 1) } : category))
      setItemDelete(null)
      showNotice(response.message)
    } catch (requestError) {
      if (isApiError(requestError) && requestError.status === 409) {
        setItems((current) => current.map((entry) => entry.id === deleting.id ? { ...entry, is_available: false } : entry))
        showNotice(requestError.message)
      } else {
        setActionError(isApiError(requestError) ? requestError.message : 'The item could not be deleted.')
      }
      setItemDelete(null)
    } finally {
      setBusy('')
    }
  }

  function savedItem(saved: RestaurantMenuItem, message: string) {
    const previous = items.find((entry) => entry.id === saved.id)
    setItems((current) => previous ? current.map((entry) => entry.id === saved.id ? saved : entry) : [...current, saved])
    setCategories((current) => current.map((category) => {
      if (!previous && category.id === saved.category.id) return { ...category, item_count: category.item_count + 1 }
      if (previous && previous.category.id !== saved.category.id && category.id === previous.category.id) return { ...category, item_count: Math.max(0, category.item_count - 1) }
      if (previous && previous.category.id !== saved.category.id && category.id === saved.category.id) return { ...category, item_count: category.item_count + 1 }
      return category
    }))
    setItemDialog(null)
    showNotice(message)
  }

  return (
    <div>
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Store catalogue</p><h1 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">Menu</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">Keep dishes, prices, availability, and customer choices current.</p></div>
        <button type="button" disabled={categories.length === 0} onClick={() => setItemDialog('new')} className="min-h-12 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black shadow-[0_0_24px_rgba(57,255,20,0.12)] transition hover:bg-[#55ff35] disabled:cursor-not-allowed disabled:opacity-35">+ Add menu item</button>
      </header>

      {notice && <div role="status" className="mt-5 flex items-center justify-between gap-3 rounded-xl border border-neon-green/25 bg-neon-green/[0.07] px-4 py-3 text-sm font-semibold text-neon-green"><span>{notice}</span><button type="button" onClick={() => setNotice('')} className="min-h-9 rounded-lg px-2 text-xs">Dismiss</button></div>}
      {actionError && <div role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200">{actionError}</div>}

      <section className="mt-7 rounded-2xl border border-white/10 bg-brand-surface p-4 sm:p-5">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="text-lg font-black text-white">Categories</h2><p className="mt-1 text-xs text-white/35">Use the arrow buttons to control display order.</p></div><span className="text-xs font-bold text-white/35">{categories.length} total</span></div>
        <form onSubmit={saveCategory} className="mt-4 flex flex-col gap-2 sm:flex-row">
          <input value={categoryName} onChange={(event) => setCategoryName(event.target.value)} maxLength={255} placeholder="Category name" className="h-12 min-w-0 flex-1 rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-neon-green focus:ring-2 focus:ring-neon-green/15" />
          <button type="submit" disabled={busy === 'category-save'} className="min-h-12 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black disabled:opacity-40">{busy === 'category-save' ? 'Saving…' : editingCategory ? 'Save name' : 'Add category'}</button>
          {editingCategory && <button type="button" onClick={() => { setEditingCategory(null); setCategoryName('') }} className="min-h-12 rounded-xl border border-white/10 px-4 text-sm font-bold text-white/55">Cancel</button>}
        </form>

        <div className="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
          {categories.map((category, index) => (
            <div key={category.id} className="flex min-h-16 items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.025] p-2">
              <div className="flex shrink-0 flex-col">
                <button type="button" aria-label={`Move ${category.name} up`} disabled={index === 0 || busy === 'category-reorder'} onClick={() => void moveCategory(index, -1)} className="flex h-7 w-9 items-center justify-center rounded text-xs text-white/45 hover:bg-white/[0.06] hover:text-white disabled:opacity-20">▲</button>
                <button type="button" aria-label={`Move ${category.name} down`} disabled={index === categories.length - 1 || busy === 'category-reorder'} onClick={() => void moveCategory(index, 1)} className="flex h-7 w-9 items-center justify-center rounded text-xs text-white/45 hover:bg-white/[0.06] hover:text-white disabled:opacity-20">▼</button>
              </div>
              <button type="button" onClick={() => setSelectedCategory(category.id)} className="min-w-0 flex-1 rounded-lg px-2 py-2 text-left"><span className="block truncate text-sm font-bold text-white/80">{category.name}</span><span className="mt-0.5 block text-xs text-white/35">{category.item_count} {category.item_count === 1 ? 'item' : 'items'}</span></button>
              <button type="button" onClick={() => { setEditingCategory(category); setCategoryName(category.name); setActionError('') }} className="min-h-11 rounded-lg px-2 text-xs font-bold text-white/55 hover:text-white">Rename</button>
              <button type="button" onClick={() => setCategoryDelete(category)} className="min-h-11 rounded-lg px-2 text-xs font-bold text-red-300 hover:bg-red-400/10">Delete</button>
            </div>
          ))}
          {!loading && categories.length === 0 && <div className="rounded-xl border border-dashed border-white/10 px-4 py-8 text-center text-sm text-white/35 md:col-span-2 xl:col-span-3">Create a category before adding your first menu item.</div>}
        </div>
      </section>

      <div className="mt-7 flex gap-2 overflow-x-auto pb-1">
        <CategoryChip active={selectedCategory === 'all'} label="All items" onClick={() => setSelectedCategory('all')} />
        {categories.map((category) => <CategoryChip key={category.id} active={selectedCategory === category.id} label={category.name} onClick={() => setSelectedCategory(category.id)} />)}
      </div>

      {loading ? <MenuSkeleton /> : error ? (
        <section className="mt-6 rounded-2xl border border-red-400/20 bg-brand-surface px-5 py-12 text-center"><p className="text-sm text-red-200">{error}</p><button type="button" onClick={() => { setLoading(true); void loadMenu().finally(() => setLoading(false)) }} className="mt-4 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Retry</button></section>
      ) : items.length === 0 ? (
        <section className="mt-6 rounded-2xl border border-dashed border-white/10 bg-brand-surface/60 px-5 py-16 text-center"><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neon-green/[0.08] text-2xl text-neon-green">+</span><h2 className="mt-4 text-lg font-black text-white">No menu items yet — add your first one</h2><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-white/40">Create a category first, then add the dishes customers can order.</p>{categories.length > 0 && <button type="button" onClick={() => setItemDialog('new')} className="mt-5 min-h-12 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Add your first item</button>}</section>
      ) : (
        <div className="mt-6 space-y-8">
          {visibleCategories.map((category) => {
            const categoryItems = items.filter((item) => item.category.id === category.id)
            if (selectedCategory === 'all' && categoryItems.length === 0) return null
            return <section key={category.id}><div className="mb-3 flex items-center justify-between px-1"><h2 className="text-lg font-black text-white">{category.name}</h2><span className="text-xs font-bold text-white/35">{categoryItems.length} items</span></div>{categoryItems.length > 0 ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{categoryItems.map((item) => <MenuItemCard key={item.id} item={item} toggling={busy === `item-toggle-${item.id}`} onToggle={() => void toggleItem(item)} onEdit={() => setItemDialog(item)} onOptions={() => setOptionsItem(item)} onDelete={() => setItemDelete(item)} />)}</div> : <div className="rounded-2xl border border-dashed border-white/10 px-4 py-10 text-center text-sm text-white/30">No items in this category.</div>}</section>
          })}
        </div>
      )}

      {itemDialog && <MenuItemDialog categories={categories} item={itemDialog === 'new' ? null : itemDialog} onClose={() => setItemDialog(null)} onSaved={savedItem} />}
      {optionsItem && <MenuOptionsDialog item={optionsItem} onClose={() => setOptionsItem(null)} onUpdated={updateItem} />}
      {categoryDelete && <ConfirmDialog title={categoryDelete.item_count > 0 ? 'Category cannot be deleted' : 'Delete category?'} message={categoryDelete.item_count > 0 ? `“${categoryDelete.name}” contains ${categoryDelete.item_count} item${categoryDelete.item_count === 1 ? '' : 's'}. The API blocks deletion; move or delete those items first.` : `Permanently delete “${categoryDelete.name}”? This cannot be undone.`} confirmLabel={categoryDelete.item_count > 0 ? 'Blocked' : 'Delete'} destructive confirmDisabled={categoryDelete.item_count > 0} busy={busy === 'category-delete'} onConfirm={() => void confirmCategoryDelete()} onClose={() => setCategoryDelete(null)} />}
      {itemDelete && <ConfirmDialog title="Delete menu item?" message={`Delete “${itemDelete.name}”? If it has order history, the server will preserve it and mark it unavailable instead.`} confirmLabel="Delete item" destructive busy={busy === 'item-delete'} onConfirm={() => void confirmItemDelete()} onClose={() => setItemDelete(null)} />}
    </div>
  )
}

function CategoryChip({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
  return <button type="button" onClick={onClick} className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-bold transition ${active ? 'bg-neon-green text-brand-black' : 'border border-white/10 bg-brand-surface text-white/50 hover:text-white'}`}>{label}</button>
}

function MenuItemCard({ item, toggling, onToggle, onEdit, onOptions, onDelete }: { item: RestaurantMenuItem; toggling: boolean; onToggle: () => void; onEdit: () => void; onOptions: () => void; onDelete: () => void }) {
  const image = apiAssetUrl(item.photo_path)
  const scheduled = item.available_from || item.available_until
  return (
    <article className={`overflow-hidden rounded-2xl border bg-brand-surface transition ${item.is_available ? 'border-white/10' : 'border-white/[0.06] opacity-70'}`}>
      <div className="relative aspect-[16/9] bg-brand-surface-2">
        {image ? <img src={image} alt="" className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-sm font-bold text-white/20">No photo</div>}
        <span className={`absolute left-3 top-3 rounded-full px-2.5 py-1 text-[11px] font-black backdrop-blur ${item.is_available ? 'bg-neon-green text-brand-black' : 'bg-black/75 text-white/60'}`}>{item.is_available ? 'Available' : 'Unavailable'}</span>
        {item.is_featured && <span className="absolute right-3 top-3 rounded-full bg-black/75 px-2.5 py-1 text-[11px] font-black text-amber-200 backdrop-blur">Featured</span>}
      </div>
      <div className="p-4">
        <div className="flex items-start justify-between gap-3"><div className="min-w-0"><h3 className="truncate text-lg font-black text-white">{item.name}</h3><p className="mt-1 line-clamp-2 min-h-10 text-sm leading-5 text-white/40">{item.description || 'No description yet.'}</p></div><p className="shrink-0 text-base font-black text-neon-green">{peso.format(Number(item.base_price))}</p></div>
        <div className="mt-3 flex flex-wrap gap-2 text-[11px] font-bold text-white/40"><span className="rounded-full bg-white/[0.05] px-2.5 py-1">{item.variants.length} variants</span><span className="rounded-full bg-white/[0.05] px-2.5 py-1">{item.addons.length} add-ons</span>{scheduled && <span className="rounded-full bg-sky-300/[0.07] px-2.5 py-1 text-sky-200">Daily {item.available_from ?? '—'}–{item.available_until ?? '—'}</span>}</div>
        <div className="mt-4 grid grid-cols-2 gap-2">
          <button type="button" disabled={toggling} onClick={onToggle} className={`min-h-11 rounded-xl text-xs font-black ${item.is_available ? 'border border-white/10 text-white/60 hover:text-white' : 'bg-neon-green text-brand-black'}`}>{toggling ? 'Saving…' : item.is_available ? 'Mark unavailable' : 'Make available'}</button>
          <button type="button" onClick={onOptions} className="min-h-11 rounded-xl border border-white/10 text-xs font-bold text-white/60 hover:border-neon-green/30 hover:text-neon-green">Options</button>
          <button type="button" onClick={onEdit} className="min-h-11 rounded-xl border border-white/10 text-xs font-bold text-white/60 hover:border-neon-green/30 hover:text-neon-green">Edit</button>
          <button type="button" onClick={onDelete} className="min-h-11 rounded-xl border border-red-400/15 text-xs font-bold text-red-300 hover:bg-red-400/[0.06]">Delete</button>
        </div>
      </div>
    </article>
  )
}

function MenuSkeleton() {
  return <div className="mt-6"><div className="mb-3 h-6 w-40 animate-pulse rounded bg-white/[0.07]"/><div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{[0, 1, 2, 3, 4, 5].map((index) => <div key={index} className="h-80 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}</div></div>
}

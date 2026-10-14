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
    <div className="fj-restaurant-menu-page">
      <header className="fj-restaurant-page-heading fj-restaurant-page-heading--split">
        <div><p>STORE CATALOGUE</p><h1>Menu</h1><span>Keep dishes, prices, availability, and customer choices current.</span></div>
        <button type="button" disabled={categories.length === 0} onClick={() => setItemDialog('new')} className="fj-restaurant-primary-action">+ Add menu item</button>
      </header>

      {notice && <div role="status" className="fj-restaurant-notice fj-restaurant-notice--success"><span>{notice}</span><button type="button" onClick={() => setNotice('')}>Dismiss</button></div>}
      {actionError && <div role="alert" className="fj-restaurant-notice fj-restaurant-notice--error">{actionError}</div>}

      <section className="fj-menu-categories">
        <div className="fj-menu-categories__heading"><div><h2>Categories</h2><p>Use the arrow buttons to control display order.</p></div><span>{categories.length} total</span></div>
        <form onSubmit={saveCategory} className="fj-menu-category-form">
          <input value={categoryName} onChange={(event) => setCategoryName(event.target.value)} maxLength={255} placeholder="Category name" />
          <button type="submit" disabled={busy === 'category-save'} className="is-primary">{busy === 'category-save' ? 'Saving…' : editingCategory ? 'Save name' : 'Add category'}</button>
          {editingCategory && <button type="button" onClick={() => { setEditingCategory(null); setCategoryName('') }} className="is-secondary">Cancel</button>}
        </form>

        <div className="fj-menu-category-grid">
          {categories.map((category, index) => (
            <div key={category.id} className="fj-menu-category-card">
              <div className="fj-menu-category-card__order">
                <button type="button" aria-label={`Move ${category.name} up`} disabled={index === 0 || busy === 'category-reorder'} onClick={() => void moveCategory(index, -1)}>▲</button>
                <button type="button" aria-label={`Move ${category.name} down`} disabled={index === categories.length - 1 || busy === 'category-reorder'} onClick={() => void moveCategory(index, 1)}>▼</button>
              </div>
              <button type="button" onClick={() => setSelectedCategory(category.id)} className="fj-menu-category-card__name"><strong>{category.name}</strong><small>{category.item_count} {category.item_count === 1 ? 'item' : 'items'}</small></button>
              <button type="button" onClick={() => { setEditingCategory(category); setCategoryName(category.name); setActionError('') }} className="fj-menu-category-card__edit">Rename</button>
              <button type="button" onClick={() => setCategoryDelete(category)} className="fj-menu-category-card__delete">Delete</button>
            </div>
          ))}
          {!loading && categories.length === 0 && <div className="fj-menu-category-grid__empty">Create a category before adding your first menu item.</div>}
        </div>
      </section>

      <div className="fj-menu-filters">
        <CategoryChip active={selectedCategory === 'all'} label="All items" onClick={() => setSelectedCategory('all')} />
        {categories.map((category) => <CategoryChip key={category.id} active={selectedCategory === category.id} label={category.name} onClick={() => setSelectedCategory(category.id)} />)}
      </div>

      {loading ? <MenuSkeleton /> : error ? (
        <section className="fj-restaurant-error-state"><p>{error}</p><button type="button" onClick={() => { setLoading(true); void loadMenu().finally(() => setLoading(false)) }}>Retry</button></section>
      ) : items.length === 0 ? (
        <section className="fj-restaurant-empty"><span>+</span><h2>No menu items yet — add your first one</h2><p>Create a category first, then add the dishes customers can order.</p>{categories.length > 0 && <button type="button" onClick={() => setItemDialog('new')} className="fj-restaurant-primary-action">Add your first item</button>}</section>
      ) : (
        <div className="fj-menu-sections">
          {visibleCategories.map((category) => {
            const categoryItems = items.filter((item) => item.category.id === category.id)
            if (selectedCategory === 'all' && categoryItems.length === 0) return null
            return <section key={category.id} className="fj-menu-section"><div className="fj-menu-section__heading"><h2>{category.name}</h2><span>{categoryItems.length} items</span></div>{categoryItems.length > 0 ? <div className="fj-menu-items-grid">{categoryItems.map((item) => <MenuItemCard key={item.id} item={item} toggling={busy === `item-toggle-${item.id}`} onToggle={() => void toggleItem(item)} onEdit={() => setItemDialog(item)} onOptions={() => setOptionsItem(item)} onDelete={() => setItemDelete(item)} />)}</div> : <div className="fj-menu-section__empty">No items in this category.</div>}</section>
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
  return <button type="button" onClick={onClick} className={`fj-menu-filter ${active ? 'is-active' : ''}`}>{label}</button>
}

function MenuItemCard({ item, toggling, onToggle, onEdit, onOptions, onDelete }: { item: RestaurantMenuItem; toggling: boolean; onToggle: () => void; onEdit: () => void; onOptions: () => void; onDelete: () => void }) {
  const image = apiAssetUrl(item.photo_path)
  const scheduled = item.available_from || item.available_until
  return (
    <article className={`fj-menu-item-card ${item.is_available ? '' : 'is-unavailable'}`}>
      <div className="fj-menu-item-card__image">
        {image ? <img src={image} alt="" /> : <div>No photo</div>}
        <span className={`fj-menu-item-card__availability ${item.is_available ? 'is-available' : ''}`}>{item.is_available ? 'Available' : 'Unavailable'}</span>
        {item.is_featured && <span className="fj-menu-item-card__featured">Featured</span>}
      </div>
      <div className="fj-menu-item-card__body">
        <div className="fj-menu-item-card__title"><div><h3>{item.name}</h3><p>{item.description || 'No description yet.'}</p></div><strong>{peso.format(Number(item.base_price))}</strong></div>
        <div className="fj-menu-item-card__tags"><span>{item.variants.length} variants</span><span>{item.addons.length} add-ons</span>{scheduled && <span className="is-schedule">Daily {item.available_from ?? '—'}–{item.available_until ?? '—'}</span>}</div>
        <div className="fj-menu-item-card__actions">
          <button type="button" disabled={toggling} onClick={onToggle} className={item.is_available ? '' : 'is-primary'}>{toggling ? 'Saving…' : item.is_available ? 'Mark unavailable' : 'Make available'}</button>
          <button type="button" onClick={onOptions}>Options</button>
          <button type="button" onClick={onEdit}>Edit</button>
          <button type="button" onClick={onDelete} className="is-danger">Delete</button>
        </div>
      </div>
    </article>
  )
}

function MenuSkeleton() {
  return <div className="fj-menu-loading" aria-busy="true"><span /><div>{[0, 1, 2, 3, 4, 5].map((index) => <i key={index} />)}</div></div>
}

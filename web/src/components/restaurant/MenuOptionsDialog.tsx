import { useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import {
  createRestaurantMenuAddon,
  createRestaurantMenuVariant,
  deleteRestaurantMenuAddon,
  deleteRestaurantMenuVariant,
  isApiError,
  updateRestaurantMenuAddon,
  updateRestaurantMenuVariant,
} from '../../lib/api'
import type { RestaurantMenuAddon, RestaurantMenuItem, RestaurantMenuVariant } from '../../lib/api'
import { ConfirmDialog } from './ConfirmDialog'

type PendingDelete = { kind: 'variant'; option: RestaurantMenuVariant } | { kind: 'addon'; option: RestaurantMenuAddon }

export function MenuOptionsDialog({ item: initialItem, onClose, onUpdated }: {
  item: RestaurantMenuItem
  onClose: () => void
  onUpdated: (item: RestaurantMenuItem, message: string) => void
}) {
  const [item, setItem] = useState(initialItem)
  const [variantName, setVariantName] = useState('')
  const [variantPrice, setVariantPrice] = useState('0.00')
  const [addonName, setAddonName] = useState('')
  const [addonPrice, setAddonPrice] = useState('0.00')
  const [editingVariant, setEditingVariant] = useState<RestaurantMenuVariant | null>(null)
  const [editingAddon, setEditingAddon] = useState<RestaurantMenuAddon | null>(null)
  const [pendingDelete, setPendingDelete] = useState<PendingDelete | null>(null)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')

  function publish(next: RestaurantMenuItem, message: string) {
    setItem(next)
    onUpdated(next, message)
  }

  function failure(requestError: unknown, fallback: string) {
    setError(isApiError(requestError) ? requestError.message : fallback)
  }

  async function saveVariant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const name = variantName.trim()
    if (!name || variantPrice.trim() === '' || Number.isNaN(Number(variantPrice))) {
      setError('Enter a variant name and a valid price adjustment.')
      return
    }
    setBusy('variant-save')
    setError('')
    try {
      if (editingVariant) {
        const response = await updateRestaurantMenuVariant(item.id, editingVariant.id, { name, price_delta: variantPrice })
        const next = { ...item, variants: item.variants.map((entry) => entry.id === response.variant.id ? response.variant : entry) }
        publish(next, response.message)
      } else {
        const response = await createRestaurantMenuVariant(item.id, { name, price_delta: variantPrice })
        publish({ ...item, variants: [...item.variants, response.variant] }, response.message)
      }
      setVariantName('')
      setVariantPrice('0.00')
      setEditingVariant(null)
    } catch (requestError) {
      failure(requestError, 'The variant could not be saved.')
    } finally {
      setBusy('')
    }
  }

  async function saveAddon(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const name = addonName.trim()
    if (!name || addonPrice.trim() === '' || Number.isNaN(Number(addonPrice)) || Number(addonPrice) < 0) {
      setError('Enter an add-on name and a price of zero or greater.')
      return
    }
    setBusy('addon-save')
    setError('')
    try {
      if (editingAddon) {
        const response = await updateRestaurantMenuAddon(item.id, editingAddon.id, { name, price: addonPrice, is_available: editingAddon.is_available })
        const next = { ...item, addons: item.addons.map((entry) => entry.id === response.addon.id ? response.addon : entry) }
        publish(next, response.message)
      } else {
        const response = await createRestaurantMenuAddon(item.id, { name, price: addonPrice, is_available: true })
        publish({ ...item, addons: [...item.addons, response.addon] }, response.message)
      }
      setAddonName('')
      setAddonPrice('0.00')
      setEditingAddon(null)
    } catch (requestError) {
      failure(requestError, 'The add-on could not be saved.')
    } finally {
      setBusy('')
    }
  }

  async function toggleAddon(addon: RestaurantMenuAddon) {
    setBusy(`addon-${addon.id}`)
    setError('')
    try {
      const response = await updateRestaurantMenuAddon(item.id, addon.id, { name: addon.name, price: addon.price, is_available: !addon.is_available })
      const next = { ...item, addons: item.addons.map((entry) => entry.id === response.addon.id ? response.addon : entry) }
      publish(next, response.message)
    } catch (requestError) {
      failure(requestError, 'Availability could not be changed.')
    } finally {
      setBusy('')
    }
  }

  async function confirmDelete() {
    if (!pendingDelete) return
    setBusy(`${pendingDelete.kind}-delete`)
    setError('')
    try {
      if (pendingDelete.kind === 'variant') {
        const response = await deleteRestaurantMenuVariant(item.id, pendingDelete.option.id)
        publish({ ...item, variants: item.variants.filter((entry) => entry.id !== pendingDelete.option.id) }, response.message)
      } else {
        const response = await deleteRestaurantMenuAddon(item.id, pendingDelete.option.id)
        publish({ ...item, addons: item.addons.filter((entry) => entry.id !== pendingDelete.option.id) }, response.message)
      }
      setPendingDelete(null)
    } catch (requestError) {
      failure(requestError, 'The option could not be deleted.')
      setPendingDelete(null)
    } finally {
      setBusy('')
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/75 p-0 backdrop-blur-sm sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onClose() }}>
      <section role="dialog" aria-modal="true" aria-labelledby="menu-options-title" className="max-h-[96svh] w-full max-w-4xl overflow-y-auto rounded-t-3xl border border-white/10 bg-brand-surface shadow-2xl sm:rounded-2xl">
        <header className="sticky top-0 z-10 flex items-center justify-between border-b border-white/10 bg-brand-surface/95 px-5 py-4 backdrop-blur sm:px-6">
          <div><p className="text-xs font-black uppercase tracking-[0.16em] text-neon-green">{item.name}</p><h2 id="menu-options-title" className="mt-1 text-xl font-black text-white">Variants & add-ons</h2></div>
          <button type="button" disabled={Boolean(busy)} onClick={onClose} aria-label="Close" className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 text-xl text-white/55 hover:text-white">×</button>
        </header>

        <div className="p-5 sm:p-6">
          <div className="mb-5 rounded-xl border border-sky-300/15 bg-sky-300/[0.05] px-4 py-3 text-xs leading-5 text-sky-100/70">
            The current API has two fixed option types: customers choose one variant and may add optional add-ons. Custom group names and required flags are not stored by the backend.
          </div>
          {error && <div role="alert" className="mb-5 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200">{error}</div>}

          <div className="grid gap-7 lg:grid-cols-2">
            <OptionSection title="Variants" description="Sizes or preparations. Price adjustments may be negative.">
              <div className="space-y-2">
                {item.variants.length === 0 && <EmptyOption message="No variants yet." />}
                {item.variants.map((variant) => (
                  <OptionRow key={variant.id} name={variant.name} price={formatDelta(variant.price_delta)} onEdit={() => { setEditingVariant(variant); setVariantName(variant.name); setVariantPrice(variant.price_delta); setError('') }} onDelete={() => setPendingDelete({ kind: 'variant', option: variant })} disabled={Boolean(busy)} />
                ))}
              </div>
              <form onSubmit={saveVariant} className="mt-4 grid gap-3 rounded-xl border border-white/[0.08] bg-brand-black/60 p-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto]">
                <input aria-label="Variant name" value={variantName} onChange={(event) => setVariantName(event.target.value)} maxLength={255} placeholder="e.g. Large" className={inputClass} />
                <div className="relative"><span className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30">₱</span><input aria-label="Variant price adjustment" type="number" step="0.01" inputMode="decimal" value={variantPrice} onChange={(event) => setVariantPrice(event.target.value)} className={`${inputClass} pl-8`} /></div>
                <button type="submit" disabled={Boolean(busy)} className="min-h-12 rounded-xl bg-neon-green px-4 text-sm font-black text-brand-black disabled:opacity-40">{editingVariant ? 'Save' : 'Add'}</button>
                {editingVariant && <button type="button" onClick={() => { setEditingVariant(null); setVariantName(''); setVariantPrice('0.00') }} className="min-h-11 text-xs font-bold text-white/45 sm:col-span-3">Cancel edit</button>}
              </form>
            </OptionSection>

            <OptionSection title="Add-ons" description="Optional extras. Unavailable choices stay saved but cannot be ordered.">
              <div className="space-y-2">
                {item.addons.length === 0 && <EmptyOption message="No add-ons yet." />}
                {item.addons.map((addon) => (
                  <div key={addon.id} className="rounded-xl border border-white/[0.08] bg-white/[0.025] p-3">
                    <div className="flex items-center gap-3">
                      <div className="min-w-0 flex-1"><p className={`truncate text-sm font-bold ${addon.is_available ? 'text-white/80' : 'text-white/35 line-through'}`}>{addon.name}</p><p className="mt-0.5 text-xs text-white/35">+{peso(addon.price)}</p></div>
                      <button type="button" disabled={Boolean(busy)} onClick={() => void toggleAddon(addon)} className={`min-h-10 rounded-lg px-3 text-xs font-black ${addon.is_available ? 'bg-neon-green/10 text-neon-green' : 'bg-white/[0.06] text-white/45'}`}>{busy === `addon-${addon.id}` ? 'Saving…' : addon.is_available ? 'Available' : 'Unavailable'}</button>
                      <button type="button" disabled={Boolean(busy)} onClick={() => { setEditingAddon(addon); setAddonName(addon.name); setAddonPrice(addon.price); setError('') }} className="min-h-10 rounded-lg px-2 text-xs font-bold text-white/55 hover:text-white">Edit</button>
                      <button type="button" disabled={Boolean(busy)} onClick={() => setPendingDelete({ kind: 'addon', option: addon })} className="min-h-10 rounded-lg px-2 text-xs font-bold text-red-300 hover:bg-red-400/10">Delete</button>
                    </div>
                  </div>
                ))}
              </div>
              <form onSubmit={saveAddon} className="mt-4 grid gap-3 rounded-xl border border-white/[0.08] bg-brand-black/60 p-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto]">
                <input aria-label="Add-on name" value={addonName} onChange={(event) => setAddonName(event.target.value)} maxLength={255} placeholder="e.g. Extra cheese" className={inputClass} />
                <div className="relative"><span className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30">₱</span><input aria-label="Add-on price" type="number" min="0" step="0.01" inputMode="decimal" value={addonPrice} onChange={(event) => setAddonPrice(event.target.value)} className={`${inputClass} pl-8`} /></div>
                <button type="submit" disabled={Boolean(busy)} className="min-h-12 rounded-xl bg-neon-green px-4 text-sm font-black text-brand-black disabled:opacity-40">{editingAddon ? 'Save' : 'Add'}</button>
                {editingAddon && <button type="button" onClick={() => { setEditingAddon(null); setAddonName(''); setAddonPrice('0.00') }} className="min-h-11 text-xs font-bold text-white/45 sm:col-span-3">Cancel edit</button>}
              </form>
            </OptionSection>
          </div>
        </div>
      </section>

      {pendingDelete && <ConfirmDialog title={`Delete ${pendingDelete.kind}?`} message={`Remove “${pendingDelete.option.name}”? If it appears in order history, the server will preserve it and explain why it cannot be deleted.`} confirmLabel="Delete" destructive busy={busy.endsWith('-delete')} onConfirm={() => void confirmDelete()} onClose={() => setPendingDelete(null)} />}
    </div>
  )
}

const inputClass = 'h-12 w-full rounded-xl border border-white/10 bg-brand-surface-2 px-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-neon-green focus:ring-2 focus:ring-neon-green/15'

function OptionSection({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return <section><h3 className="text-lg font-black text-white">{title}</h3><p className="mt-1 min-h-10 text-xs leading-5 text-white/40">{description}</p><div className="mt-3">{children}</div></section>
}

function OptionRow({ name, price, disabled, onEdit, onDelete }: { name: string; price: string; disabled: boolean; onEdit: () => void; onDelete: () => void }) {
  return <div className="flex min-h-16 items-center gap-3 rounded-xl border border-white/[0.08] bg-white/[0.025] p-3"><div className="min-w-0 flex-1"><p className="truncate text-sm font-bold text-white/80">{name}</p><p className="mt-0.5 text-xs text-white/35">{price}</p></div><button type="button" disabled={disabled} onClick={onEdit} className="min-h-10 rounded-lg px-3 text-xs font-bold text-white/55 hover:text-white">Edit</button><button type="button" disabled={disabled} onClick={onDelete} className="min-h-10 rounded-lg px-2 text-xs font-bold text-red-300 hover:bg-red-400/10">Delete</button></div>
}

function EmptyOption({ message }: { message: string }) {
  return <div className="rounded-xl border border-dashed border-white/10 px-4 py-6 text-center text-sm text-white/30">{message}</div>
}

function peso(value: string): string {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value))
}

function formatDelta(value: string): string {
  const amount = Number(value)
  if (amount === 0) return 'No price change'
  return `${amount > 0 ? '+' : '−'}${peso(String(Math.abs(amount)))}`
}

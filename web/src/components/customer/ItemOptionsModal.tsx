import { useEffect, useMemo, useState } from 'react'
import type { CustomerMenuItem, MenuItemAddon, MenuItemVariant } from '../../lib/api'
import { CloseIcon, MinusIcon, PlusIcon } from './Icons'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export interface ItemConfiguration {
  variant: MenuItemVariant | null
  addons: MenuItemAddon[]
  quantity: number
}

export function ItemOptionsModal({ item, onClose, onConfirm }: {
  item: CustomerMenuItem
  onClose: () => void
  onConfirm: (configuration: ItemConfiguration) => void
}) {
  const availableAddons = useMemo(() => item.addons.filter((addon) => addon.is_available), [item.addons])
  const [variantId, setVariantId] = useState(item.variants[0]?.id ?? '')
  const [addonIds, setAddonIds] = useState<string[]>([])
  const [quantity, setQuantity] = useState(1)
  const selectedVariant = item.variants.find((variant) => variant.id === variantId) ?? null
  const selectedAddons = availableAddons.filter((addon) => addonIds.includes(addon.id))
  const unitPrice = Number(item.base_price)
    + Number(selectedVariant?.price_delta ?? 0)
    + selectedAddons.reduce((total, addon) => total + Number(addon.price), 0)

  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  function toggleAddon(id: string) {
    setAddonIds((current) => current.includes(id) ? current.filter((addonId) => addonId !== id) : [...current, id])
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="item-options-title">
      <button type="button" aria-label="Close item options" className="absolute inset-0 bg-black/80 backdrop-blur-[2px]" onClick={onClose} />
      <section className="relative z-10 max-h-[92svh] w-full overflow-y-auto rounded-t-3xl border border-white/10 bg-brand-surface shadow-[0_-24px_70px_rgba(0,0,0,0.55)] sm:max-w-lg sm:rounded-3xl">
        <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-white/10 bg-brand-surface/95 px-5 py-5 backdrop-blur sm:px-6">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Customize your order</p>
            <h2 id="item-options-title" className="mt-1 text-xl font-bold text-white">{item.name}</h2>
          </div>
          <button type="button" onClick={onClose} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/10 text-white/65 transition hover:border-neon-green/40 hover:text-neon-green focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green" aria-label="Close options">
            <CloseIcon className="h-5 w-5" />
          </button>
        </header>

        <div className="space-y-7 px-5 py-6 sm:px-6">
          {item.variants.length > 0 && (
            <fieldset>
              <legend className="mb-3 text-sm font-bold text-white">Choose one</legend>
              <div className="space-y-2">
                {item.variants.map((variant) => (
                  <label key={variant.id} className={`flex min-h-12 cursor-pointer items-center justify-between gap-4 rounded-xl border px-4 transition ${variantId === variant.id ? 'border-neon-green bg-neon-green/[0.07]' : 'border-white/10 bg-white/[0.025] hover:border-white/20'}`}>
                    <span className="flex items-center gap-3 text-sm text-white">
                      <input type="radio" name="variant" value={variant.id} checked={variantId === variant.id} onChange={() => setVariantId(variant.id)} className="h-4 w-4 accent-[#39ff14]" />
                      {variant.name}
                    </span>
                    <span className="text-sm font-semibold text-white/55">{Number(variant.price_delta) === 0 ? 'Included' : `${Number(variant.price_delta) > 0 ? '+' : '−'}${currency.format(Math.abs(Number(variant.price_delta)))}`}</span>
                  </label>
                ))}
              </div>
            </fieldset>
          )}

          {availableAddons.length > 0 && (
            <fieldset>
              <legend className="mb-3 text-sm font-bold text-white">Add extras <span className="font-normal text-white/35">(optional)</span></legend>
              <div className="space-y-2">
                {availableAddons.map((addon) => (
                  <label key={addon.id} className={`flex min-h-12 cursor-pointer items-center justify-between gap-4 rounded-xl border px-4 transition ${addonIds.includes(addon.id) ? 'border-neon-green/70 bg-neon-green/[0.06]' : 'border-white/10 bg-white/[0.025] hover:border-white/20'}`}>
                    <span className="flex items-center gap-3 text-sm text-white">
                      <input type="checkbox" checked={addonIds.includes(addon.id)} onChange={() => toggleAddon(addon.id)} className="h-4 w-4 rounded accent-[#39ff14]" />
                      {addon.name}
                    </span>
                    <span className="text-sm font-semibold text-white/55">+{currency.format(Number(addon.price))}</span>
                  </label>
                ))}
              </div>
            </fieldset>
          )}

          <div className="flex items-center justify-between border-t border-white/10 pt-6">
            <span className="text-sm font-bold text-white">Quantity</span>
            <div className="flex items-center gap-3">
              <button type="button" onClick={() => setQuantity((current) => Math.max(1, current - 1))} disabled={quantity === 1} className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 text-white transition hover:border-neon-green/40 hover:text-neon-green disabled:cursor-not-allowed disabled:opacity-35" aria-label="Decrease quantity"><MinusIcon className="h-4 w-4" /></button>
              <span className="min-w-8 text-center text-base font-bold text-white">{quantity}</span>
              <button type="button" onClick={() => setQuantity((current) => Math.min(99, current + 1))} className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 text-white transition hover:border-neon-green/40 hover:text-neon-green" aria-label="Increase quantity"><PlusIcon className="h-4 w-4" /></button>
            </div>
          </div>
        </div>

        <footer className="sticky bottom-0 border-t border-white/10 bg-brand-surface/95 p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] backdrop-blur sm:px-6">
          <button type="button" onClick={() => onConfirm({ variant: selectedVariant, addons: selectedAddons, quantity })} className="flex min-h-12 w-full items-center justify-between rounded-xl bg-neon-green px-5 font-bold text-brand-black transition hover:bg-[#55ff35] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-surface">
            <span>Add to cart</span>
            <span>{currency.format(unitPrice * quantity)}</span>
          </button>
        </footer>
      </section>
    </div>
  )
}

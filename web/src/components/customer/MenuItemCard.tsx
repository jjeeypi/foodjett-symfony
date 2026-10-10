import type { CustomerMenuItem } from '../../lib/api'
import { apiAssetUrl } from '../../lib/api'
import { MinusIcon, PlusIcon } from './Icons'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function MenuItemCard({ item, available, orderingDisabled, quantity, configuredCount, onAdd, onDecrease }: {
  item: CustomerMenuItem
  available: boolean
  orderingDisabled: boolean
  quantity: number
  configuredCount: number
  onAdd: () => void
  onDecrease: () => void
}) {
  const imageUrl = apiAssetUrl(item.photo_path)
  const hasOptions = item.variants.length > 0 || item.addons.some((addon) => addon.is_available)
  const disabled = orderingDisabled || !available

  return (
    <article className={`flex min-h-44 overflow-hidden rounded-2xl border bg-brand-surface transition ${disabled ? 'border-white/6 opacity-65' : 'border-white/9 hover:border-neon-green/25'}`}>
      <div className="flex w-28 shrink-0 items-center justify-center overflow-hidden bg-[radial-gradient(circle_at_30%_20%,rgba(57,255,20,0.14),transparent_58%),#151515] sm:w-36">
        {imageUrl ? <img src={imageUrl} alt="" className="h-full w-full object-cover" /> : <span className="text-3xl font-black text-neon-green/80">{item.name.charAt(0).toUpperCase()}</span>}
      </div>
      <div className="flex min-w-0 flex-1 flex-col p-4">
        <div className="flex-1">
          <div className="flex items-start justify-between gap-3">
            <h3 className="font-bold text-white">{item.name}</h3>
            {item.is_featured && <span className="shrink-0 rounded-full bg-neon-green/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-neon-green">Popular</span>}
          </div>
          {item.description && <p className="mt-1.5 line-clamp-2 text-xs leading-5 text-white/45 sm:text-sm">{item.description}</p>}
          {hasOptions && <p className="mt-2 text-[11px] font-medium text-neon-green/70">Options available</p>}
          {!available && <p className="mt-2 text-xs font-semibold text-red-300">Unavailable right now</p>}
        </div>
        <div className="mt-4 flex items-center justify-between gap-3">
          <strong className="text-sm text-white sm:text-base">{currency.format(Number(item.base_price))}</strong>
          {!hasOptions && quantity > 0 && !disabled ? (
            <div className="flex items-center gap-2">
              <button type="button" onClick={onDecrease} className="flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 text-white transition hover:border-neon-green/40 hover:text-neon-green" aria-label={`Decrease ${item.name} quantity`}><MinusIcon className="h-4 w-4" /></button>
              <span className="min-w-5 text-center text-sm font-bold text-white">{quantity}</span>
              <button type="button" onClick={onAdd} className="flex h-10 w-10 items-center justify-center rounded-xl bg-neon-green text-brand-black transition hover:bg-[#55ff35]" aria-label={`Increase ${item.name} quantity`}><PlusIcon className="h-4 w-4" /></button>
            </div>
          ) : (
            <button type="button" onClick={onAdd} disabled={disabled} className="min-h-10 rounded-xl bg-neon-green px-4 text-xs font-bold text-brand-black transition hover:bg-[#55ff35] disabled:cursor-not-allowed disabled:bg-white/10 disabled:text-white/30">
              {orderingDisabled ? 'Closed' : !available ? 'Unavailable' : configuredCount > 0 ? `Add another (${configuredCount})` : 'Add'}
            </button>
          )}
        </div>
      </div>
    </article>
  )
}

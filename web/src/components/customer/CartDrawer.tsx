import { useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { useCart } from '../../cart/CartContext'
import { CloseIcon, EmptyCartIcon, MinusIcon, PlusIcon, TrashIcon } from './Icons'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function CartDrawer() {
  const navigate = useNavigate()
  const closeButtonRef = useRef<HTMLButtonElement>(null)
  const { items, subtotal, isCartOpen, closeCart, updateQuantity, removeItem } = useCart()

  useEffect(() => {
    if (!isCartOpen) return
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    closeButtonRef.current?.focus()

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') closeCart()
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [closeCart, isCartOpen])

  return (
    <div className={`fixed inset-0 z-50 transition ${isCartOpen ? 'visible' : 'invisible delay-300'}`} aria-hidden={!isCartOpen}>
      <button
        type="button"
        aria-label="Close cart"
        tabIndex={isCartOpen ? 0 : -1}
        className={`absolute inset-0 bg-black/75 backdrop-blur-[2px] transition-opacity duration-300 ${isCartOpen ? 'opacity-100' : 'opacity-0'}`}
        onClick={closeCart}
      />
      <aside
        role="dialog"
        aria-modal="true"
        aria-labelledby="cart-title"
        className={`absolute right-0 top-0 flex h-full w-full max-w-md flex-col border-l border-white/10 bg-brand-surface shadow-[-24px_0_70px_rgba(0,0,0,0.55)] transition-transform duration-300 ease-out ${isCartOpen ? 'translate-x-0' : 'translate-x-full'}`}
      >
        <header className="flex items-center justify-between border-b border-white/10 px-5 py-5 pt-[max(1.25rem,env(safe-area-inset-top))]">
          <div>
            <h2 id="cart-title" className="text-xl font-bold text-white">Your cart</h2>
            <p className="mt-1 text-xs text-white/45">{items.length === 0 ? 'Ready when you are' : `${items.length} unique item${items.length === 1 ? '' : 's'}`}</p>
          </div>
          <button ref={closeButtonRef} type="button" onClick={closeCart} className="flex h-11 w-11 items-center justify-center rounded-full border border-white/10 text-white/70 transition hover:border-neon-green/50 hover:text-neon-green focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green" aria-label="Close cart panel">
            <CloseIcon className="h-5 w-5" />
          </button>
        </header>

        {items.length === 0 ? (
          <div className="flex flex-1 flex-col items-center justify-center px-8 text-center">
            <span className="mb-5 flex h-20 w-20 items-center justify-center rounded-full border border-neon-green/20 bg-neon-green/[0.06] text-neon-green">
              <EmptyCartIcon className="h-10 w-10" />
            </span>
            <h3 className="text-lg font-semibold text-white">Nothing here yet</h3>
            <p className="mt-2 max-w-xs text-sm leading-6 text-white/50">Browse an open restaurant and add something delicious to get started.</p>
            <button type="button" onClick={() => { closeCart(); navigate('/') }} className="mt-6 min-h-11 rounded-xl border border-neon-green px-5 text-sm font-semibold text-neon-green transition hover:bg-neon-green hover:text-brand-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-surface">
              Browse restaurants
            </button>
          </div>
        ) : (
          <>
            <ul className="flex-1 space-y-3 overflow-y-auto p-4 sm:p-5">
              {items.map((item) => (
                <li key={item.id} className="flex gap-3 rounded-2xl border border-white/8 bg-white/[0.025] p-3">
                  <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white/[0.06] text-xs text-white/30">
                    {item.thumbnailUrl ? <img src={item.thumbnailUrl} alt="" className="h-full w-full object-cover" /> : 'Food'}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="truncate text-sm font-semibold text-white">{item.name}</p>
                        <p className="mt-1 text-sm font-bold text-neon-green">{currency.format(item.unitPrice)}</p>
                      </div>
                      <button type="button" onClick={() => removeItem(item.id)} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white/35 transition hover:bg-red-500/10 hover:text-red-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400" aria-label={`Remove ${item.name}`}>
                        <TrashIcon className="h-4 w-4" />
                      </button>
                    </div>
                    <div className="mt-3 flex items-center gap-2">
                      <button type="button" onClick={() => updateQuantity(item.id, item.quantity - 1)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 text-white transition hover:border-neon-green/50 hover:text-neon-green" aria-label={`Decrease ${item.name} quantity`}><MinusIcon className="h-4 w-4" /></button>
                      <span className="min-w-8 text-center text-sm font-semibold text-white">{item.quantity}</span>
                      <button type="button" onClick={() => updateQuantity(item.id, item.quantity + 1)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-white/10 text-white transition hover:border-neon-green/50 hover:text-neon-green" aria-label={`Increase ${item.name} quantity`}><PlusIcon className="h-4 w-4" /></button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>

            <footer className="border-t border-white/10 bg-brand-black/40 p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
              <div className="mb-4 flex items-center justify-between">
                <span className="text-sm text-white/55">Subtotal</span>
                <strong className="text-xl text-white">{currency.format(subtotal)}</strong>
              </div>
              <button
                type="button"
                onClick={() => {
                  closeCart()
                  navigate('/checkout')
                }}
                className="min-h-12 w-full rounded-xl bg-neon-green px-5 font-bold text-brand-black shadow-[0_0_20px_rgba(57,255,20,0.16)] transition hover:bg-[#55ff35] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-surface"
              >
                Checkout
              </button>
            </footer>
          </>
        )}
      </aside>
    </div>
  )
}

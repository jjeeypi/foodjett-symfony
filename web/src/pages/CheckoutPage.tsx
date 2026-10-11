import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useCart } from '../cart/CartContext'
import { CheckoutAddressForm } from '../components/customer/CheckoutAddressForm'
import { getCustomerAddresses, isApiError, placeOrder, previewCheckout } from '../lib/api'
import type { CheckoutInput, CheckoutQuote, CustomerAddress, PaymentMethod } from '../lib/api'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

type AddressState =
  | { status: 'loading' }
  | { status: 'success'; addresses: CustomerAddress[] }
  | { status: 'error'; message: string }

type QuoteState =
  | { key: string; status: 'loading' }
  | { key: string; status: 'success'; quote: CheckoutQuote }
  | { key: string; status: 'error'; message: string }

function newCheckoutToken(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()

  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16)
    const value = character === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}

function amount(value: string): string {
  return currency.format(Number(value))
}

export default function CheckoutPage() {
  const navigate = useNavigate()
  const { items, cartRestaurantId, clearCart } = useCart()
  const [addressState, setAddressState] = useState<AddressState>({ status: 'loading' })
  const [selectedAddressId, setSelectedAddressId] = useState('')
  const [showAddressForm, setShowAddressForm] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('cod')
  const [quoteState, setQuoteState] = useState<QuoteState>({ key: '', status: 'loading' })
  const [quoteAttempt, setQuoteAttempt] = useState(0)
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState('')
  const [addressReload, setAddressReload] = useState(0)

  const cartFingerprint = useMemo(() => JSON.stringify(items.map((item) => ({
    id: item.id,
    quantity: item.quantity,
    restaurantId: item.restaurantId,
  }))), [items])
  const checkoutToken = useMemo(() => {
    void cartFingerprint
    return newCheckoutToken()
  }, [cartFingerprint])
  const checkoutItems = useMemo(() => items.map((item) => ({
    menu_item_id: item.menuItemId,
    variant_id: item.variant?.id ?? null,
    addon_ids: item.addons?.map((addon) => addon.id) ?? [],
    quantity: item.quantity,
  })), [items])
  const quoteKey = `${selectedAddressId}:${paymentMethod}:${cartFingerprint}:${quoteAttempt}`
  const activeQuote = quoteState.key === quoteKey ? quoteState : { key: quoteKey, status: 'loading' as const }

  useEffect(() => {
    if (items.length === 0) return
    const controller = new AbortController()
    getCustomerAddresses(controller.signal)
      .then(({ addresses }) => {
        setAddressState({ status: 'success', addresses })
        setSelectedAddressId((current) => current || addresses.find((address) => address.is_default)?.id || addresses[0]?.id || '')
        if (addresses.length === 0) setShowAddressForm(true)
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        setAddressState({ status: 'error', message: isApiError(error) ? error.message : 'We could not load your saved addresses.' })
      })
    return () => controller.abort()
  }, [addressReload, items.length])

  useEffect(() => {
    if (!selectedAddressId || !cartRestaurantId || checkoutItems.length === 0) return
    const controller = new AbortController()
    const input: CheckoutInput = {
      checkout_token: checkoutToken,
      restaurant_id: cartRestaurantId,
      customer_address_id: selectedAddressId,
      payment_method: paymentMethod,
      tip_amount: '0.00',
      items: checkoutItems,
    }
    previewCheckout(input, controller.signal)
      .then(({ quote }) => setQuoteState({ key: quoteKey, status: 'success', quote }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        setQuoteState({ key: quoteKey, status: 'error', message: isApiError(error) ? error.message : 'We could not validate this order.' })
      })
    return () => controller.abort()
  }, [cartRestaurantId, checkoutItems, checkoutToken, paymentMethod, quoteKey, selectedAddressId])

  if (items.length === 0 || !cartRestaurantId) return <Navigate to="/" replace />

  const selectedAddress = addressState.status === 'success'
    ? addressState.addresses.find((address) => address.id === selectedAddressId) ?? null
    : null
  const canSubmit = !submitting && Boolean(selectedAddress) && activeQuote.status === 'success'

  function checkoutInput(): CheckoutInput {
    return {
      checkout_token: checkoutToken,
      restaurant_id: cartRestaurantId as string,
      customer_address_id: selectedAddressId,
      payment_method: paymentMethod,
      tip_amount: '0.00',
      items: checkoutItems,
    }
  }

  async function submitOrder(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canSubmit) return
    setSubmitting(true)
    setSubmitError('')
    try {
      const response = await placeOrder(checkoutInput())
      clearCart()
      navigate(`/orders/${response.order.id}`, { replace: true, state: { order: response.order } })
    } catch (error: unknown) {
      const message = isApiError(error) ? error.message : 'We could not place your order. Please try again.'
      setSubmitError(message)
      if (isApiError(error) && [404, 409, 422].includes(error.status)) {
        setQuoteState({ key: quoteKey, status: 'error', message })
      }
      setSubmitting(false)
    }
  }

  function addressCreated(address: CustomerAddress) {
    setAddressState((current) => current.status === 'success'
      ? { status: 'success', addresses: [address, ...current.addresses.map((entry) => address.is_default ? { ...entry, is_default: false } : entry)] }
      : { status: 'success', addresses: [address] })
    setSelectedAddressId(address.id)
    setShowAddressForm(false)
  }

  return (
    <form onSubmit={submitOrder} className="mx-auto max-w-6xl" noValidate>
      <div className="mb-7 sm:mb-9">
        <p className="text-xs font-bold uppercase tracking-[0.2em] text-neon-green">Final step</p>
        <h1 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">Checkout</h1>
        <p className="mt-2 text-sm text-white/50">Review the server-verified total, then place your order.</p>
      </div>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div className="space-y-6">
          <section className="rounded-2xl border border-white/10 bg-brand-surface p-4 sm:p-6" aria-labelledby="delivery-heading">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.15em] text-neon-green">1. Delivery</p>
                <h2 id="delivery-heading" className="mt-1 text-xl font-bold text-white">Choose an address</h2>
              </div>
              {addressState.status === 'success' && addressState.addresses.length > 0 && !showAddressForm && (
                <button type="button" onClick={() => setShowAddressForm(true)} className="min-h-11 rounded-xl border border-neon-green/50 px-4 text-sm font-semibold text-neon-green transition hover:bg-neon-green/10">Add new address</button>
              )}
            </div>

            {addressState.status === 'loading' && (
              <div className="mt-5 grid gap-3 sm:grid-cols-2" aria-label="Loading addresses" aria-busy="true">
                <div className="h-28 animate-pulse rounded-xl bg-white/[0.06]" />
                <div className="h-28 animate-pulse rounded-xl bg-white/[0.06]" />
              </div>
            )}

            {addressState.status === 'error' && (
              <div role="alert" className="mt-5 rounded-xl border border-red-400/25 bg-red-500/[0.08] p-4 text-sm text-red-200">
                <p>{addressState.message}</p>
                <button type="button" onClick={() => { setAddressState({ status: 'loading' }); setAddressReload((value) => value + 1) }} className="mt-3 min-h-11 rounded-lg border border-red-300/40 px-4 font-semibold">Try again</button>
              </div>
            )}

            {addressState.status === 'success' && addressState.addresses.length > 0 && (
              <div className="mt-5 grid gap-3 sm:grid-cols-2">
                {addressState.addresses.map((address) => (
                  <label key={address.id} className={`relative flex min-h-28 cursor-pointer gap-3 rounded-xl border p-4 transition ${selectedAddressId === address.id ? 'border-neon-green bg-neon-green/[0.055]' : 'border-white/10 bg-white/[0.02] hover:border-white/20'}`}>
                    <input type="radio" name="delivery-address" value={address.id} checked={selectedAddressId === address.id} onChange={() => { setSelectedAddressId(address.id); setSubmitError('') }} className="mt-1 h-4 w-4 shrink-0 accent-[#39ff14]" />
                    <span className="min-w-0">
                      <span className="flex flex-wrap items-center gap-2 text-sm font-bold text-white">{address.label}{address.is_default && <span className="rounded-full bg-neon-green/10 px-2 py-0.5 text-[10px] uppercase text-neon-green">Default</span>}</span>
                      <span className="mt-1 block text-sm leading-5 text-white/55">{address.address_line}</span>
                      {address.landmark && <span className="mt-1 block text-xs text-white/35">Near {address.landmark}</span>}
                    </span>
                  </label>
                ))}
              </div>
            )}

            {showAddressForm && addressState.status !== 'loading' && (
              <div className="mt-5">
                <CheckoutAddressForm makeDefault={addressState.status === 'success' && addressState.addresses.length === 0} onCreated={addressCreated} onCancel={addressState.status === 'success' && addressState.addresses.length > 0 ? () => setShowAddressForm(false) : undefined} />
              </div>
            )}

            {selectedAddress && activeQuote.status === 'error' && activeQuote.message.toLowerCase().includes('outside') && (
              <div role="alert" className="mt-4 rounded-xl border border-red-400/30 bg-red-500/[0.09] px-4 py-3 text-sm font-semibold text-red-200">{activeQuote.message} Choose or add another address before placing the order.</div>
            )}
          </section>

          <section className="rounded-2xl border border-white/10 bg-brand-surface p-4 sm:p-6" aria-labelledby="payment-heading">
            <p className="text-xs font-bold uppercase tracking-[0.15em] text-neon-green">2. Payment</p>
            <h2 id="payment-heading" className="mt-1 text-xl font-bold text-white">Payment method</h2>
            <div className="mt-5 grid gap-3">
              {([
                ['cod', 'Cash on Delivery', 'Pay the rider when your order arrives.'],
                ['gcash', 'GCash', 'Simulated instant payment for this school project.'],
                ['card', 'Card', 'Simulated instant card payment; no external gateway.'],
              ] as Array<[PaymentMethod, string, string]>).map(([value, label, description]) => (
                <label key={value} className={`flex min-h-20 cursor-pointer items-center gap-4 rounded-xl border px-4 py-3 transition ${paymentMethod === value ? 'border-neon-green bg-neon-green/[0.055]' : 'border-white/10 bg-white/[0.02] hover:border-white/20'}`}>
                  <input type="radio" name="payment-method" value={value} checked={paymentMethod === value} onChange={() => { setPaymentMethod(value); setSubmitError('') }} className="h-4 w-4 shrink-0 accent-[#39ff14]" />
                  <span>
                    <span className="block text-sm font-bold text-white">{label}</span>
                    <span className="mt-1 block text-xs leading-5 text-white/45">{description}</span>
                  </span>
                </label>
              ))}
            </div>
          </section>
        </div>

        <aside className="rounded-2xl border border-white/10 bg-brand-surface p-4 sm:p-6 lg:sticky lg:top-28" aria-labelledby="summary-heading">
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.15em] text-neon-green">Order summary</p>
              <h2 id="summary-heading" className="mt-1 text-xl font-bold text-white">{items[0]?.restaurantName}</h2>
            </div>
            <Link to={`/restaurants/${cartRestaurantId}`} className="inline-flex min-h-11 items-center text-xs font-semibold text-neon-green transition hover:text-[#62ff45]">Edit cart</Link>
          </div>

          <ul className="mt-5 space-y-4 border-y border-white/10 py-5">
            {(activeQuote.status === 'success' ? activeQuote.quote.items : items).map((item) => {
              const cartItem = 'menu_item_id' in item ? items.find((entry) => entry.menuItemId === item.menu_item_id) : item
              const variantName = 'menu_item_id' in item ? item.variant?.name : item.variant?.name
              const addonNames = 'menu_item_id' in item ? item.addons.map((addon) => addon.name) : item.addons?.map((addon) => addon.name) ?? []
              return (
                <li key={'menu_item_id' in item ? `${item.menu_item_id}:${variantName ?? 'base'}:${addonNames.join(',')}` : item.id} className="flex items-start justify-between gap-4">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-white"><span className="mr-2 text-neon-green">{item.quantity}x</span>{item.name}</p>
                    {variantName && <p className="mt-1 text-xs text-white/45">{variantName}</p>}
                    {addonNames.length > 0 && <p className="mt-1 text-xs leading-5 text-white/35">+ {addonNames.join(', ')}</p>}
                    {!('menu_item_id' in item) && cartItem && <p className="mt-1 text-[11px] text-white/30">Price verifying...</p>}
                  </div>
                  {'line_total' in item && <strong className="shrink-0 text-sm text-white">{amount(item.line_total)}</strong>}
                </li>
              )
            })}
          </ul>

          {activeQuote.status === 'loading' && selectedAddressId && (
            <div className="space-y-3 py-5" aria-label="Verifying order total" aria-busy="true">
              <div className="h-4 animate-pulse rounded bg-white/[0.06]" />
              <div className="h-4 w-4/5 animate-pulse rounded bg-white/[0.06]" />
              <p className="text-center text-xs text-white/40">Verifying availability, coverage, and prices...</p>
            </div>
          )}

          {activeQuote.status === 'success' && (
            <dl className="space-y-3 py-5 text-sm">
              <div className="flex justify-between gap-4 text-white/55"><dt>Subtotal</dt><dd>{amount(activeQuote.quote.subtotal)}</dd></div>
              <div className="flex justify-between gap-4 text-white/55"><dt>Delivery fee</dt><dd>{amount(activeQuote.quote.delivery_fee)}</dd></div>
              {Number(activeQuote.quote.service_fee) > 0 && <div className="flex justify-between gap-4 text-white/55"><dt>Service fee</dt><dd>{amount(activeQuote.quote.service_fee)}</dd></div>}
              {Number(activeQuote.quote.discount_amount) > 0 && <div className="flex justify-between gap-4 text-neon-green"><dt>Discount</dt><dd>-{amount(activeQuote.quote.discount_amount)}</dd></div>}
              <div className="flex justify-between gap-4 border-t border-white/10 pt-4 text-lg font-black text-white"><dt>Total</dt><dd>{amount(activeQuote.quote.total_amount)}</dd></div>
            </dl>
          )}

          {activeQuote.status === 'error' && !activeQuote.message.toLowerCase().includes('outside') && (
            <div role="alert" className="my-5 rounded-xl border border-red-400/25 bg-red-500/[0.08] px-4 py-3 text-sm text-red-200">
              <p>{activeQuote.message}</p>
              <button type="button" onClick={() => { setSubmitError(''); setQuoteAttempt((value) => value + 1) }} className="mt-3 min-h-10 rounded-lg border border-red-300/40 px-4 text-xs font-bold text-red-100">Verify again</button>
            </div>
          )}
          {submitError && <div role="alert" className="mb-4 rounded-xl border border-red-400/25 bg-red-500/[0.08] px-4 py-3 text-sm text-red-200">{submitError}</div>}

          <button type="submit" disabled={!canSubmit} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-neon-green px-5 font-black text-brand-black transition hover:bg-[#55ff35] disabled:cursor-not-allowed disabled:bg-white/10 disabled:text-white/35">
            {submitting && <span className="h-4 w-4 animate-spin rounded-full border-2 border-brand-black/30 border-t-brand-black" aria-hidden="true" />}
            {submitting ? 'Placing order...' : paymentMethod === 'cod' ? 'Place COD order' : `Pay with ${paymentMethod === 'gcash' ? 'GCash' : 'Card'}`}
          </button>
          <p className="mt-3 text-center text-[11px] leading-4 text-white/30">The server checks prices, availability, restaurant hours, and delivery coverage again when you submit.</p>
        </aside>
      </div>
    </form>
  )
}

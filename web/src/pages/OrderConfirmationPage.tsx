import { Link, useLocation, useParams } from 'react-router-dom'
import type { CheckoutOrder } from '../lib/api'

const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export default function OrderConfirmationPage() {
  const { id = '' } = useParams()
  const location = useLocation()
  const order = (location.state as { order?: CheckoutOrder } | null)?.order
  const paidOnline = order?.payment_method === 'gcash' || order?.payment_method === 'card'

  return (
    <section className="mx-auto max-w-2xl rounded-3xl border border-neon-green/20 bg-brand-surface px-5 py-12 text-center sm:px-10 sm:py-16">
      <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full border border-neon-green/35 bg-neon-green/10 text-4xl font-black text-neon-green" aria-hidden="true">✓</div>
      <p className="mt-6 text-xs font-bold uppercase tracking-[0.18em] text-neon-green">Order #{order?.order_number ?? id}</p>
      <h1 className="mt-3 text-3xl font-black text-white">{paidOnline ? 'Payment Successful' : 'Order placed'}</h1>
      <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-white/55">{paidOnline ? 'Thank you for your order! Your simulated payment was accepted instantly.' : 'Your order is confirmed. Please have the cash ready when your rider arrives.'}</p>

      {order && (
        <div className="mx-auto mt-7 flex max-w-sm items-center justify-between rounded-xl border border-white/10 bg-white/[0.025] px-4 py-4 text-left">
          <span className="text-sm text-white/45">Order total</span>
          <strong className="text-lg text-white">{currency.format(Number(order.total_amount))}</strong>
        </div>
      )}

      <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
        <Link to="/orders" className="inline-flex min-h-12 items-center justify-center rounded-xl bg-neon-green px-6 text-sm font-bold text-brand-black transition hover:bg-[#55ff35]">View my orders</Link>
        <Link to="/" className="inline-flex min-h-12 items-center justify-center rounded-xl border border-white/15 px-6 text-sm font-semibold text-white/70 transition hover:border-neon-green/40 hover:text-white">Back to restaurants</Link>
      </div>
      {!order && <p className="mt-5 text-xs text-white/30">Live tracking for order {id} will appear here when the full order-tracking screen is connected.</p>}
    </section>
  )
}

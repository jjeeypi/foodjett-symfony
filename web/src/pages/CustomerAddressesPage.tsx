import { useEffect, useState } from 'react'
import { AddressForm } from '../components/customer/AddressForm'
import { deleteCustomerAddress, getCustomerAddresses, isApiError, updateCustomerAddress } from '../lib/api'
import type { CustomerAddress } from '../lib/api'

export default function CustomerAddressesPage() {
  const [addresses, setAddresses] = useState<CustomerAddress[]>([])
  const [editing, setEditing] = useState<CustomerAddress | null | 'new'>(null)
  const [deleting, setDeleting] = useState<CustomerAddress | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getCustomerAddresses(controller.signal).then((response) => setAddresses(response.addresses)).catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load addresses.') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [retry])

  function saved(address: CustomerAddress) {
    setAddresses((current) => {
      const found = current.some((item) => item.id === address.id)
      const next = found ? current.map((item) => item.id === address.id ? address : address.is_default ? { ...item, is_default: false } : item) : [address, ...current.map((item) => address.is_default ? { ...item, is_default: false } : item)]
      return next.sort((left, right) => Number(right.is_default) - Number(left.is_default))
    })
    setEditing(null); setNotice(editing === 'new' ? 'Address added.' : 'Address updated.'); setError('')
  }

  async function setDefault(address: CustomerAddress) {
    setBusy(address.id); setError(''); setNotice('')
    try {
      const response = await updateCustomerAddress(address.id, { label: address.label, address_line: address.address_line, landmark: address.landmark ?? '', delivery_instructions: address.delivery_instructions ?? '', latitude: address.latitude, longitude: address.longitude, is_default: true })
      setAddresses((current) => current.map((item) => item.id === address.id ? response.address : { ...item, is_default: false }).sort((left, right) => Number(right.is_default) - Number(left.is_default)))
      setNotice('Default address updated.')
    } catch (requestError) { setError(isApiError(requestError) ? requestError.message : 'The default address could not be changed.') } finally { setBusy(null) }
  }

  async function remove() {
    if (!deleting) return
    setBusy(deleting.id); setError(''); setNotice('')
    try {
      await deleteCustomerAddress(deleting.id)
      setAddresses((current) => current.filter((item) => item.id !== deleting.id)); setDeleting(null); setNotice('Address deleted.')
    } catch (requestError) { setError(isApiError(requestError) ? requestError.message : 'The address could not be deleted.'); setDeleting(null) } finally { setBusy(null) }
  }

  return <div className="mx-auto max-w-5xl"><header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Your account</p><h1 className="mt-2 text-3xl font-black text-white sm:text-4xl">Saved addresses</h1><p className="mt-2 text-sm text-white/45">Manage the delivery locations available during checkout.</p></div><button type="button" onClick={() => setEditing('new')} className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Add address</button></header>
    {notice && <p role="status" className="mt-5 rounded-xl border border-neon-green/20 bg-neon-green/[0.06] px-4 py-3 text-sm font-semibold text-neon-green">{notice}</p>}{error && <p role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-4 py-3 text-sm text-red-200">{error}</p>}
    {editing && <div className="mt-6"><AddressForm key={editing === 'new' ? 'new' : editing.id} address={editing === 'new' ? undefined : editing} makeDefault={addresses.length === 0} onSaved={saved} onCancel={() => setEditing(null)} /></div>}
    {loading ? <div className="mt-6 grid gap-4 md:grid-cols-2" aria-busy="true">{[0, 1, 2].map((row) => <div key={row} className="h-52 animate-pulse rounded-2xl bg-brand-surface" />)}</div> : error && addresses.length === 0 ? <button type="button" onClick={() => setRetry((value) => value + 1)} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button> : addresses.length === 0 ? <section className="mt-6 rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 px-5 py-14 text-center"><h2 className="font-black text-white">No saved addresses</h2><p className="mt-2 text-sm text-white/40">Add your first delivery location to make checkout faster.</p></section> : <div className="mt-6 grid gap-4 md:grid-cols-2">{addresses.map((address) => <article key={address.id} className={`rounded-2xl border bg-brand-surface p-5 ${address.is_default ? 'border-neon-green/30' : 'border-white/10'}`}><div className="flex items-start justify-between gap-3"><div><h2 className="font-black text-white">{address.label}</h2>{address.is_default && <span className="mt-2 inline-flex rounded-full bg-neon-green/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-wider text-neon-green">Default</span>}</div></div><p className="mt-4 text-sm leading-6 text-white/65">{address.address_line}</p>{address.landmark && <p className="mt-2 text-xs text-white/40">Landmark: {address.landmark}</p>}{address.delivery_instructions && <p className="mt-2 text-xs text-white/40">Instructions: {address.delivery_instructions}</p>}<p className="mt-3 text-[11px] text-white/25">{address.latitude}, {address.longitude}</p><div className="mt-5 flex flex-wrap gap-2"><button type="button" onClick={() => setEditing(address)} className="min-h-10 rounded-xl border border-white/15 px-4 text-xs font-black text-white/65">Edit</button>{!address.is_default && <button type="button" onClick={() => void setDefault(address)} disabled={busy !== null} className="min-h-10 rounded-xl border border-neon-green/30 px-4 text-xs font-black text-neon-green disabled:opacity-40">Set default</button>}<button type="button" onClick={() => setDeleting(address)} disabled={busy !== null} className="min-h-10 rounded-xl border border-red-400/25 px-4 text-xs font-black text-red-300 disabled:opacity-40">Delete</button></div></article>)}</div>}
    {deleting && <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center"><section role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-6"><h2 className="text-xl font-black text-white">Delete {deleting.label}?</h2><p className="mt-2 text-sm leading-6 text-white/45">Addresses used by past orders are protected and the server will keep them for order history.</p><div className="mt-6 grid grid-cols-2 gap-3"><button type="button" onClick={() => setDeleting(null)} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65">Cancel</button><button type="button" onClick={() => void remove()} disabled={busy !== null} className="min-h-12 rounded-xl bg-red-400 text-sm font-black text-brand-black disabled:opacity-50">Delete</button></div></section></div>}
  </div>
}

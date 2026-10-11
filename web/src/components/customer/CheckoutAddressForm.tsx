import { useState } from 'react'
import { NeonInput } from '../NeonInput'
import { createCustomerAddress, isApiError } from '../../lib/api'
import type { CustomerAddress, CustomerAddressInput } from '../../lib/api'

type AddressField = keyof Pick<CustomerAddressInput, 'label' | 'address_line' | 'landmark' | 'delivery_instructions' | 'latitude' | 'longitude'>

const initialFields: CustomerAddressInput = {
  label: '',
  address_line: '',
  landmark: '',
  delivery_instructions: '',
  latitude: '',
  longitude: '',
  is_default: false,
}

function fieldError(field: AddressField, value: string): string | undefined {
  if ((field === 'label' || field === 'address_line' || field === 'latitude' || field === 'longitude') && !value.trim()) {
    return 'This field is required.'
  }
  if (field === 'latitude' && (!Number.isFinite(Number(value)) || Number(value) < -90 || Number(value) > 90)) {
    return 'Latitude must be between -90 and 90.'
  }
  if (field === 'longitude' && (!Number.isFinite(Number(value)) || Number(value) < -180 || Number(value) > 180)) {
    return 'Longitude must be between -180 and 180.'
  }
  return undefined
}

export function CheckoutAddressForm({ makeDefault, onCreated, onCancel }: {
  makeDefault: boolean
  onCreated: (address: CustomerAddress) => void
  onCancel?: () => void
}) {
  const [fields, setFields] = useState<CustomerAddressInput>({ ...initialFields, is_default: makeDefault })
  const [errors, setErrors] = useState<Partial<Record<AddressField, string>>>({})
  const [globalError, setGlobalError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [locating, setLocating] = useState(false)

  function update(field: AddressField, value: string) {
    setFields((current) => ({ ...current, [field]: value }))
    if (errors[field]) setErrors((current) => ({ ...current, [field]: undefined }))
    setGlobalError('')
  }

  function validate(): boolean {
    const nextErrors: Partial<Record<AddressField, string>> = {}
    ;(['label', 'address_line', 'latitude', 'longitude'] as AddressField[]).forEach((field) => {
      const error = fieldError(field, String(fields[field] ?? ''))
      if (error) nextErrors[field] = error
    })
    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  function useCurrentLocation() {
    if (!navigator.geolocation) {
      setGlobalError('Location access is not supported by this browser. Enter the coordinates manually.')
      return
    }
    setLocating(true)
    setGlobalError('')
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setFields((current) => ({
          ...current,
          latitude: coords.latitude.toFixed(7),
          longitude: coords.longitude.toFixed(7),
        }))
        setErrors((current) => ({ ...current, latitude: undefined, longitude: undefined }))
        setLocating(false)
      },
      () => {
        setGlobalError('We could not access your location. Enter the latitude and longitude manually.')
        setLocating(false)
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    )
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!validate()) return
    setSubmitting(true)
    setGlobalError('')
    try {
      const response = await createCustomerAddress(fields)
      onCreated(response.address)
    } catch (error: unknown) {
      if (isApiError(error)) {
        const nextErrors: Partial<Record<AddressField, string>> = {}
        for (const [field, messages] of Object.entries(error.errors ?? {})) {
          if (field in initialFields && messages[0]) nextErrors[field as AddressField] = messages[0]
        }
        setErrors(nextErrors)
        setGlobalError(error.message)
      } else {
        setGlobalError('We could not save this address. Please try again.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={submit} className="rounded-2xl border border-neon-green/20 bg-neon-green/[0.025] p-4 sm:p-5" noValidate>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="font-bold text-white">Add a delivery address</h3>
          <p className="mt-1 text-xs text-white/45">Coordinates are required so Foodjett can verify delivery coverage.</p>
        </div>
        <button type="button" onClick={useCurrentLocation} disabled={locating || submitting} className="min-h-11 rounded-xl border border-neon-green/50 px-4 text-xs font-bold text-neon-green transition hover:bg-neon-green/10 disabled:cursor-not-allowed disabled:opacity-50">
          {locating ? 'Finding location...' : 'Use my location'}
        </button>
      </div>

      {globalError && <p role="alert" className="mb-4 rounded-xl border border-red-400/25 bg-red-500/[0.08] px-4 py-3 text-sm text-red-200">{globalError}</p>}

      <div className="grid gap-4 sm:grid-cols-2">
        <NeonInput id="address-label" label="Label" placeholder="Home, Work, or Other" value={fields.label} onChange={(event) => update('label', event.target.value)} error={errors.label} disabled={submitting} />
        <div className="sm:col-span-2">
          <NeonInput id="address-line" label="Full address" placeholder="House/unit, street, barangay, city" value={fields.address_line} onChange={(event) => update('address_line', event.target.value)} error={errors.address_line} disabled={submitting} />
        </div>
        <NeonInput id="address-landmark" label="Landmark (optional)" placeholder="Near the public market" value={fields.landmark} onChange={(event) => update('landmark', event.target.value)} disabled={submitting} />
        <NeonInput id="address-instructions" label="Delivery instructions (optional)" placeholder="Call at the gate" value={fields.delivery_instructions} onChange={(event) => update('delivery_instructions', event.target.value)} disabled={submitting} />
        <NeonInput id="address-latitude" label="Latitude" inputMode="decimal" placeholder="14.5995000" value={fields.latitude} onChange={(event) => update('latitude', event.target.value)} error={errors.latitude} disabled={submitting} />
        <NeonInput id="address-longitude" label="Longitude" inputMode="decimal" placeholder="120.9842000" value={fields.longitude} onChange={(event) => update('longitude', event.target.value)} error={errors.longitude} disabled={submitting} />
      </div>

      {!makeDefault && (
        <label className="mt-4 flex min-h-11 cursor-pointer items-center gap-3 text-sm text-white/65">
          <input type="checkbox" checked={Boolean(fields.is_default)} onChange={(event) => setFields((current) => ({ ...current, is_default: event.target.checked }))} className="h-4 w-4 accent-[#39ff14]" disabled={submitting} />
          Make this my default address
        </label>
      )}

      <div className="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        {onCancel && <button type="button" onClick={onCancel} disabled={submitting} className="min-h-11 rounded-xl border border-white/15 px-5 text-sm font-semibold text-white/65 transition hover:border-white/30 hover:text-white disabled:opacity-50">Cancel</button>}
        <button type="submit" disabled={submitting} className="min-h-11 rounded-xl bg-neon-green px-5 text-sm font-bold text-brand-black transition hover:bg-[#55ff35] disabled:cursor-not-allowed disabled:opacity-55">
          {submitting ? 'Saving address...' : 'Save address'}
        </button>
      </div>
    </form>
  )
}

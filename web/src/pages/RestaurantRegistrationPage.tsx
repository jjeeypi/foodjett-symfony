import { useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { DocumentUploadField } from '../components/DocumentUploadField'
import { FormAlert } from '../components/FormAlert'
import { Logo } from '../components/Logo'
import { NeonInput } from '../components/NeonInput'
import { PasswordStrengthMeter } from '../components/PasswordStrengthMeter'
import { RegistrationStepIndicator } from '../components/RegistrationStepIndicator'
import { SubmitButton } from '../components/SubmitButton'
import { isApiError, registerRestaurant } from '../lib/api'
import { accountErrors, documentError, firstErrorStep, normalizePhone } from '../lib/registration'

const steps = ['Account', 'Restaurant', 'Documents', 'Review']
const stepFields = [
  ['owner_name', 'email', 'phone', 'password', 'password_confirmation'],
  ['restaurant_name', 'cuisine_type', 'address', 'latitude', 'longitude'],
  ['business_permit', 'food_safety_permit', 'owner_id'],
]

const initialFields = {
  owner_name: '', email: '', phone: '', password: '', password_confirmation: '',
  restaurant_name: '', cuisine_type: '', address: '', latitude: '', longitude: '',
}

type TextField = keyof typeof initialFields
type DocumentField = 'business_permit' | 'food_safety_permit' | 'owner_id'
type FieldErrors = Record<string, string>

const documentLabels: Record<DocumentField, string> = {
  business_permit: 'Business permit',
  food_safety_permit: 'Food safety permit',
  owner_id: 'Owner valid ID',
}

function restaurantDetailsErrors(fields: typeof initialFields): FieldErrors {
  const errors: FieldErrors = {}
  if (!fields.restaurant_name.trim()) errors.restaurant_name = 'Business name is required.'
  if (!fields.cuisine_type.trim()) errors.cuisine_type = 'Cuisine type is required.'
  if (!fields.address.trim()) errors.address = 'Business address is required.'
  const latitude = Number(fields.latitude)
  const longitude = Number(fields.longitude)
  if (!fields.latitude || !Number.isFinite(latitude) || latitude < -90 || latitude > 90) errors.latitude = 'Enter a latitude between -90 and 90.'
  if (!fields.longitude || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) errors.longitude = 'Enter a longitude between -180 and 180.'
  return errors
}

export default function RestaurantRegistrationPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [fields, setFields] = useState(initialFields)
  const [documents, setDocuments] = useState<Record<DocumentField, File | null>>({ business_permit: null, food_safety_permit: null, owner_id: null })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [globalError, setGlobalError] = useState('')
  const [loading, setLoading] = useState(false)
  const [locating, setLocating] = useState(false)

  function updateField(event: ChangeEvent<HTMLInputElement>) {
    const name = event.target.name as TextField
    setFields((current) => ({ ...current, [name]: event.target.value }))
    setErrors((current) => ({ ...current, [name]: '' }))
    setGlobalError('')
  }

  function updateDocument(name: DocumentField, event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null
    setDocuments((current) => ({ ...current, [name]: file }))
    setErrors((current) => ({ ...current, [name]: file ? documentError(file) ?? '' : 'This document is required.' }))
  }

  function validateStep(index: number): FieldErrors {
    if (index === 0) return accountErrors(fields, fields.owner_name, 'owner_name')
    if (index === 1) return restaurantDetailsErrors(fields)
    if (index === 2) {
      const next: FieldErrors = {}
      for (const name of Object.keys(documents) as DocumentField[]) {
        const error = documentError(documents[name])
        if (error) next[name] = error
      }
      return next
    }
    return {}
  }

  function nextStep() {
    const nextErrors = validateStep(step)
    setErrors((current) => ({ ...current, ...nextErrors }))
    if (Object.keys(nextErrors).length === 0) setStep((current) => Math.min(current + 1, steps.length - 1))
  }

  function useCurrentLocation() {
    if (!navigator.geolocation) {
      setGlobalError('Location is not available in this browser. Enter the coordinates manually.')
      return
    }
    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setFields((current) => ({ ...current, latitude: coords.latitude.toFixed(7), longitude: coords.longitude.toFixed(7) }))
        setErrors((current) => ({ ...current, latitude: '', longitude: '' }))
        setLocating(false)
      },
      () => {
        setGlobalError('We could not read your location. Allow location access or enter the coordinates manually.')
        setLocating(false)
      },
      { enableHighAccuracy: true, timeout: 10000 },
    )
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (step < steps.length - 1 || loading) return
    const allErrors = Object.assign({}, ...[0, 1, 2].map(validateStep)) as FieldErrors
    setErrors(allErrors)
    if (Object.keys(allErrors).length > 0) {
      setStep(firstErrorStep(allErrors, stepFields))
      return
    }

    setLoading(true)
    setGlobalError('')
    try {
      const response = await registerRestaurant({
        ...fields,
        owner_name: fields.owner_name.trim(),
        email: fields.email.trim().toLowerCase(),
        phone: normalizePhone(fields.phone),
        restaurant_name: fields.restaurant_name.trim(),
        address: fields.address.trim(),
        cuisine_type: fields.cuisine_type.trim(),
        business_permit: documents.business_permit!,
        food_safety_permit: documents.food_safety_permit!,
        owner_id: documents.owner_id!,
      })
      navigate('/register/pending', { replace: true, state: { role: 'restaurant', name: response.user.name, businessName: fields.restaurant_name } })
    } catch (error: unknown) {
      if (isApiError(error) && error.errors) {
        const mapped = Object.fromEntries(Object.entries(error.errors).filter(([, messages]) => messages[0]).map(([name, messages]) => [name, messages[0]]))
        setErrors(mapped)
        setStep(firstErrorStep(mapped, stepFields))
        setGlobalError('Please correct the highlighted fields and try again.')
      } else {
        setGlobalError(isApiError(error) ? error.message : 'Unable to submit your application right now.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthLayout showStandaloneLogo={false}>
      <section className="rounded-2xl border border-white/10 bg-brand-surface/95 p-5 shadow-[0_24px_70px_rgba(0,0,0,0.5)] sm:p-8">
        <header className="mb-6 text-center">
          <div className="flex justify-center"><Logo /></div>
          <p className="mt-4 text-sm font-semibold uppercase tracking-[0.16em] text-neon-green">Restaurant application</p>
          <h1 className="mt-2 text-2xl font-black text-white">Bring your kitchen to Foodjett</h1>
        </header>
        <RegistrationStepIndicator steps={steps} currentStep={step} />
        <FormAlert message={globalError} />

        <form onSubmit={submit} noValidate>
          {step === 0 && <div className="space-y-5">
            <NeonInput id="restaurant-owner-name" name="owner_name" label="Owner / contact name" autoComplete="name" value={fields.owner_name} onChange={updateField} error={errors.owner_name} disabled={loading} />
            <NeonInput id="restaurant-email" name="email" label="Email address" type="email" inputMode="email" autoComplete="email" value={fields.email} onChange={updateField} error={errors.email} disabled={loading} />
            <NeonInput id="restaurant-phone" name="phone" label="Contact phone" type="tel" inputMode="tel" autoComplete="tel" value={fields.phone} onChange={updateField} error={errors.phone} disabled={loading} />
            <div><NeonInput id="restaurant-password" name="password" label="Password" type="password" autoComplete="new-password" value={fields.password} onChange={updateField} error={errors.password} disabled={loading} showPasswordToggle /><PasswordStrengthMeter password={fields.password} /></div>
            <NeonInput id="restaurant-password-confirmation" name="password_confirmation" label="Confirm password" type="password" autoComplete="new-password" value={fields.password_confirmation} onChange={updateField} error={errors.password_confirmation} disabled={loading} showPasswordToggle />
          </div>}

          {step === 1 && <div className="space-y-5">
            <NeonInput id="restaurant-name" name="restaurant_name" label="Business name" autoComplete="organization" value={fields.restaurant_name} onChange={updateField} error={errors.restaurant_name} disabled={loading} />
            <NeonInput id="restaurant-cuisine" name="cuisine_type" label="Cuisine type" placeholder="e.g. Filipino, Fast Food, Desserts" value={fields.cuisine_type} onChange={updateField} error={errors.cuisine_type} disabled={loading} />
            <NeonInput id="restaurant-address" name="address" label="Business address" autoComplete="street-address" value={fields.address} onChange={updateField} error={errors.address} disabled={loading} />
            <div className="grid gap-4 sm:grid-cols-2">
              <NeonInput id="restaurant-latitude" name="latitude" label="Latitude" inputMode="decimal" placeholder="14.5995" value={fields.latitude} onChange={updateField} error={errors.latitude} disabled={loading} />
              <NeonInput id="restaurant-longitude" name="longitude" label="Longitude" inputMode="decimal" placeholder="120.9842" value={fields.longitude} onChange={updateField} error={errors.longitude} disabled={loading} />
            </div>
            <button type="button" onClick={useCurrentLocation} disabled={locating || loading} className="min-h-11 w-full rounded-xl border border-neon-green/40 px-4 py-2.5 text-sm font-bold text-neon-green hover:bg-neon-green/10 disabled:opacity-50">{locating ? 'Finding your location…' : 'Use my current location'}</button>
            <p className="rounded-xl bg-white/[0.04] px-4 py-3 text-xs leading-5 text-white/50">Operating hours are configured from Store Profile after approval; the registration API does not accept them.</p>
          </div>}

          {step === 2 && <div className="space-y-5">
            <p className="text-sm leading-6 text-white/60">Upload clear scans or photos. PDF, JPG, PNG, WebP, HEIC, or HEIF; maximum 10 MB each.</p>
            {(Object.keys(documentLabels) as DocumentField[]).map((name) => <DocumentUploadField key={name} id={`restaurant-${name}`} name={name} label={documentLabels[name]} file={documents[name]} error={errors[name]} disabled={loading} onChange={(event) => updateDocument(name, event)} />)}
          </div>}

          {step === 3 && <div className="space-y-5">
            <ReviewSection title="Account" rows={[["Owner", fields.owner_name], ["Email", fields.email], ["Phone", fields.phone]]} onEdit={() => setStep(0)} />
            <ReviewSection title="Restaurant" rows={[["Business", fields.restaurant_name], ["Cuisine", fields.cuisine_type], ["Address", fields.address], ["Coordinates", `${fields.latitude}, ${fields.longitude}`]]} onEdit={() => setStep(1)} />
            <ReviewSection title="Documents" rows={(Object.keys(documentLabels) as DocumentField[]).map((name) => [documentLabels[name], documents[name]?.name ?? 'Missing'])} onEdit={() => setStep(2)} />
            <p className="rounded-xl border border-amber-300/25 bg-amber-300/[0.07] px-4 py-3 text-sm leading-6 text-amber-100">Your restaurant will stay closed and pending until an admin reviews the application and documents.</p>
          </div>}

          <div className="mt-7 flex gap-3">
            {step > 0 ? <button type="button" onClick={() => setStep((current) => current - 1)} disabled={loading} className="min-h-12 flex-1 rounded-xl border border-white/15 px-4 font-bold text-white hover:bg-white/5 disabled:opacity-50">Back</button> : <Link to="/register" className="flex min-h-12 flex-1 items-center justify-center rounded-xl border border-white/15 px-4 font-bold text-white hover:bg-white/5">Back</Link>}
            {step < steps.length - 1 ? <button type="button" onClick={nextStep} className="min-h-12 flex-1 rounded-xl bg-neon-green px-4 font-bold text-brand-black hover:bg-[#55ff35]">Next</button> : <SubmitButton loading={loading} loadingLabel="Submitting…" className="flex-1">Submit application</SubmitButton>}
          </div>
        </form>
        <p className="mt-6 text-center text-sm text-white/50">Already approved? <Link to="/login" className="font-semibold text-neon-green">Log in</Link></p>
      </section>
    </AuthLayout>
  )
}

function ReviewSection({ title, rows, onEdit }: { title: string; rows: string[][]; onEdit: () => void }) {
  return <section className="rounded-xl border border-white/10 bg-white/[0.025] p-4"><div className="mb-3 flex items-center justify-between"><h2 className="font-bold text-white">{title}</h2><button type="button" onClick={onEdit} className="min-h-11 px-2 text-sm font-bold text-neon-green">Edit</button></div><dl className="space-y-2">{rows.map(([label, value]) => <div key={label} className="flex gap-3 text-sm"><dt className="w-28 shrink-0 text-white/45">{label}</dt><dd className="min-w-0 break-words text-white/80">{value}</dd></div>)}</dl></section>
}

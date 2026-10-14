import { useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { DocumentUploadField } from '../components/DocumentUploadField'
import { FormAlert } from '../components/FormAlert'
import { NeonInput } from '../components/NeonInput'
import { PasswordStrengthMeter } from '../components/PasswordStrengthMeter'
import { RegistrationStepIndicator } from '../components/RegistrationStepIndicator'
import { SubmitButton } from '../components/SubmitButton'
import { isApiError, registerRider } from '../lib/api'
import { accountErrors, documentError, firstErrorStep, normalizePhone } from '../lib/registration'

const steps = ['Account', 'Vehicle', 'Documents', 'Review']
const stepFields = [
  ['name', 'email', 'phone', 'password', 'password_confirmation'],
  ['vehicle_type', 'plate_number'],
  ['drivers_license', 'vehicle_registration', 'valid_id', 'police_clearance'],
]

type VehicleType = 'motorcycle' | 'bicycle' | 'car'
const initialFields = { name: '', email: '', phone: '', password: '', password_confirmation: '', vehicle_type: 'motorcycle' as VehicleType, plate_number: '' }
type TextField = keyof typeof initialFields
type DocumentField = 'drivers_license' | 'vehicle_registration' | 'valid_id' | 'police_clearance'
type FieldErrors = Record<string, string>

const documentLabels: Record<DocumentField, string> = {
  drivers_license: "Driver's license",
  vehicle_registration: 'Vehicle registration',
  valid_id: 'Valid government ID',
  police_clearance: 'Police clearance',
}

export default function RiderRegistrationPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [fields, setFields] = useState(initialFields)
  const [documents, setDocuments] = useState<Record<DocumentField, File | null>>({ drivers_license: null, vehicle_registration: null, valid_id: null, police_clearance: null })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [globalError, setGlobalError] = useState('')
  const [loading, setLoading] = useState(false)

  function updateField(event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) {
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
    if (index === 0) return accountErrors(fields, fields.name, 'name')
    if (index === 1) {
      const next: FieldErrors = {}
      if (!['motorcycle', 'bicycle', 'car'].includes(fields.vehicle_type)) next.vehicle_type = 'Select a valid vehicle type.'
      if (fields.vehicle_type !== 'bicycle' && !fields.plate_number.trim()) next.plate_number = 'A plate number is required for this vehicle type.'
      else if (fields.plate_number.length > 255) next.plate_number = 'Plate number must be 255 characters or fewer.'
      return next
    }
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
      const response = await registerRider({
        ...fields,
        name: fields.name.trim(),
        email: fields.email.trim().toLowerCase(),
        phone: normalizePhone(fields.phone),
        plate_number: fields.plate_number.trim(),
        drivers_license: documents.drivers_license!,
        vehicle_registration: documents.vehicle_registration!,
        valid_id: documents.valid_id!,
        police_clearance: documents.police_clearance!,
      })
      navigate('/register/pending', { replace: true, state: { role: 'rider', name: response.user.name } })
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
    <AuthLayout>
      <section className="fj-auth-card">
        <header className="fj-auth-card-header">
          <p>Rider application</p>
          <h1>Deliver with Foodjett</h1>
          <p>Create your rider profile, add your vehicle, and submit the required documents.</p>
        </header>
        <RegistrationStepIndicator steps={steps} currentStep={step} />
        <FormAlert message={globalError} />

        <form onSubmit={submit} noValidate>
          {step === 0 && <div className="fj-auth-form-stack">
            <NeonInput id="rider-name" name="name" label="Full name" autoComplete="name" value={fields.name} onChange={updateField} error={errors.name} disabled={loading} />
            <NeonInput id="rider-email" name="email" label="Email address" type="email" inputMode="email" autoComplete="email" value={fields.email} onChange={updateField} error={errors.email} disabled={loading} />
            <NeonInput id="rider-phone" name="phone" label="Phone number" type="tel" inputMode="tel" autoComplete="tel" value={fields.phone} onChange={updateField} error={errors.phone} disabled={loading} />
            <div><NeonInput id="rider-password" name="password" label="Password" type="password" autoComplete="new-password" value={fields.password} onChange={updateField} error={errors.password} disabled={loading} showPasswordToggle /><PasswordStrengthMeter password={fields.password} /></div>
            <NeonInput id="rider-password-confirmation" name="password_confirmation" label="Confirm password" type="password" autoComplete="new-password" value={fields.password_confirmation} onChange={updateField} error={errors.password_confirmation} disabled={loading} showPasswordToggle />
          </div>}

          {step === 1 && <div className="fj-auth-form-stack">
            <div><label htmlFor="rider-vehicle-type" className="fj-auth-select-label">Vehicle type</label><select id="rider-vehicle-type" name="vehicle_type" value={fields.vehicle_type} onChange={updateField} disabled={loading} className="fj-auth-select"><option value="motorcycle">Motorcycle</option><option value="bicycle">Bicycle</option><option value="car">Car</option></select>{errors.vehicle_type ? <p className="fj-auth-field-error"><span aria-hidden="true">!</span>{errors.vehicle_type}</p> : null}</div>
            <NeonInput id="rider-plate-number" name="plate_number" label={`Plate number${fields.vehicle_type === 'bicycle' ? ' (optional)' : ''}`} autoCapitalize="characters" placeholder="ABC-1234" value={fields.plate_number} onChange={updateField} error={errors.plate_number} disabled={loading} />
            <p className="fj-auth-note">Plate number is optional only for bicycles, matching the API validation rule.</p>
          </div>}

          {step === 2 && <div className="fj-auth-form-stack">
            <p className="fj-auth-copy">The current approval checklist requires all four document types. Upload PDF, JPG, PNG, WebP, HEIC, or HEIF files up to 10 MB.</p>
            {(Object.keys(documentLabels) as DocumentField[]).map((name) => <DocumentUploadField key={name} id={`rider-${name}`} name={name} label={documentLabels[name]} file={documents[name]} error={errors[name]} disabled={loading} onChange={(event) => updateDocument(name, event)} />)}
          </div>}

          {step === 3 && <div className="fj-auth-form-stack">
            <ReviewSection title="Account" rows={[["Name", fields.name], ["Email", fields.email], ["Phone", fields.phone]]} onEdit={() => setStep(0)} />
            <ReviewSection title="Vehicle" rows={[["Type", fields.vehicle_type], ["Plate", fields.plate_number || 'Not applicable']]} onEdit={() => setStep(1)} />
            <ReviewSection title="Documents" rows={(Object.keys(documentLabels) as DocumentField[]).map((name) => [documentLabels[name], documents[name]?.name ?? 'Missing'])} onEdit={() => setStep(2)} />
            <p className="fj-auth-note fj-auth-note--warning">Your rider account will remain offline and pending until an admin reviews the application and documents.</p>
          </div>}

          <div className="fj-auth-actions">
            {step > 0 ? <button type="button" onClick={() => setStep((current) => current - 1)} disabled={loading} className="fj-auth-secondary-action">Back</button> : <Link to="/register" className="fj-auth-secondary-action">Back</Link>}
            {step < steps.length - 1 ? <button type="button" onClick={nextStep} className="fj-auth-primary-action">Next</button> : <SubmitButton loading={loading} loadingLabel="Submitting…">Submit application</SubmitButton>}
          </div>
        </form>
        <p className="fj-auth-card-footer">Already approved? <Link to="/login" className="fj-auth-text-link">Log in</Link></p>
      </section>
    </AuthLayout>
  )
}

function ReviewSection({ title, rows, onEdit }: { title: string; rows: string[][]; onEdit: () => void }) {
  return <section className="fj-auth-review"><div><h2>{title}</h2><button type="button" onClick={onEdit}>Edit</button></div><dl>{rows.map(([label, value]) => <div key={label}><dt>{label}</dt><dd className="capitalize">{value}</dd></div>)}</dl></section>
}

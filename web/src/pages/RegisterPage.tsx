import { useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { FormAlert } from '../components/FormAlert'
import { Logo } from '../components/Logo'
import { NeonInput } from '../components/NeonInput'
import { PasswordStrengthMeter } from '../components/PasswordStrengthMeter'
import { SubmitButton } from '../components/SubmitButton'
import { useAuth } from '../auth/useAuth'
import { isApiError, registerCustomer } from '../lib/api'

const emptyFields = {
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
}

type RegistrationField = keyof typeof emptyFields
type FieldErrors = Partial<Record<RegistrationField, string>>

const registrationFields = new Set<RegistrationField>(Object.keys(emptyFields) as RegistrationField[])

function normalizedPhone(phone: string): string {
  return phone.trim().replace(/[\s()-]/g, '')
}

function validateField(field: RegistrationField, value: string, fields: typeof emptyFields): string | undefined {
  switch (field) {
    case 'name':
      if (!value.trim()) return 'Full name is required.'
      if (value.trim().length > 255) return 'Full name must be 255 characters or fewer.'
      return undefined
    case 'email':
      if (!value.trim()) return 'Email is required.'
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())) return 'Enter a valid email address.'
      return undefined
    case 'phone': {
      const phone = normalizedPhone(value)
      if (!phone) return 'Phone number is required.'
      if (!/^\+?[0-9]{7,20}$/.test(phone)) return 'Enter 7-20 digits, with an optional leading +.'
      return undefined
    }
    case 'password':
      if (!value) return 'Password is required.'
      if (value.length < 8) return 'Password must be at least 8 characters.'
      return undefined
    case 'password_confirmation':
      if (!value) return 'Please confirm your password.'
      if (value !== fields.password) return 'Passwords do not match.'
      return undefined
  }
}

function validateAll(fields: typeof emptyFields): FieldErrors {
  const errors: FieldErrors = {}
  for (const field of registrationFields) {
    const error = validateField(field, fields[field], fields)
    if (error) errors[field] = error
  }
  return errors
}

export default function RegisterPage() {
  const navigate = useNavigate()
  const { completeLogin } = useAuth()
  const [showCustomerForm, setShowCustomerForm] = useState(false)
  const [fields, setFields] = useState(emptyFields)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [globalError, setGlobalError] = useState('')
  const [loading, setLoading] = useState(false)

  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    const field = event.target.name as RegistrationField
    const nextFields = { ...fields, [field]: event.target.value }
    setFields(nextFields)
    setGlobalError('')

    setErrors((current) => {
      const nextErrors = { ...current, [field]: undefined }
      if ((field === 'password' || field === 'password_confirmation') && nextFields.password_confirmation) {
        nextErrors.password_confirmation = validateField('password_confirmation', nextFields.password_confirmation, nextFields)
      }
      return nextErrors
    })
  }

  function handleBlur(field: RegistrationField) {
    setErrors((current) => ({
      ...current,
      [field]: validateField(field, fields[field], fields),
    }))
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (loading) return

    const nextErrors = validateAll(fields)
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length > 0) return

    setLoading(true)
    setGlobalError('')

    try {
      const response = await registerCustomer({
        name: fields.name.trim(),
        email: fields.email.trim().toLowerCase(),
        phone: normalizedPhone(fields.phone),
        password: fields.password,
        password_confirmation: fields.password_confirmation,
      })
      await completeLogin(response.token)
      navigate('/', { replace: true })
    } catch (error: unknown) {
      if (isApiError(error) && error.errors) {
        const fieldErrors: FieldErrors = {}
        for (const [field, messages] of Object.entries(error.errors)) {
          if (registrationFields.has(field as RegistrationField) && messages[0]) {
            fieldErrors[field as RegistrationField] = messages[0]
          }
        }
        setErrors(fieldErrors)
        setGlobalError('Please correct the highlighted fields and try again.')
      } else {
        setGlobalError(isApiError(error) ? error.message : 'Unable to create your account right now. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  if (!showCustomerForm) {
    return (
      <AuthLayout showStandaloneLogo={false}>
        <section aria-labelledby="role-heading" className="rounded-2xl border border-white/10 bg-brand-surface/95 p-5 shadow-[0_24px_70px_rgba(0,0,0,0.5)] backdrop-blur-sm sm:p-8">
          <header className="mb-7 text-center">
            <div className="flex justify-center"><Logo /></div>
            <p className="mt-5 text-sm font-bold uppercase tracking-[0.18em] text-neon-green">Join Foodjett</p>
            <h1 id="role-heading" className="mt-2 text-3xl font-black text-white">I&apos;m a...</h1>
            <p className="mt-2 text-sm leading-6 text-white/50">Choose the account that fits how you&apos;ll use Foodjett.</p>
          </header>

          <div className="grid gap-3">
            <button type="button" onClick={() => setShowCustomerForm(true)} className="group flex min-h-20 items-center gap-4 rounded-2xl border border-white/10 bg-white/[0.025] p-4 text-left transition hover:border-neon-green/60 hover:bg-neon-green/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
              <RoleIcon path="M3 11.5 12 4l9 7.5M5.5 10v9h13v-9M9 19v-5h6v5" />
              <span className="min-w-0 flex-1"><span className="block font-bold text-white">Customer</span><span className="mt-1 block text-sm text-white/45">Order meals from nearby restaurants.</span></span>
              <ArrowIcon />
            </button>
            <Link to="/register/restaurant" className="group flex min-h-20 items-center gap-4 rounded-2xl border border-white/10 bg-white/[0.025] p-4 text-left transition hover:border-neon-green/60 hover:bg-neon-green/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
              <RoleIcon path="M4 10h16l-1.2-5H5.2L4 10Zm1 0v9h14v-9M9 19v-5h6v5M3 10c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2" />
              <span className="min-w-0 flex-1"><span className="block font-bold text-white">Restaurant</span><span className="mt-1 block text-sm text-white/45">Apply to sell food on the platform.</span></span>
              <ArrowIcon />
            </Link>
            <Link to="/register/rider" className="group flex min-h-20 items-center gap-4 rounded-2xl border border-white/10 bg-white/[0.025] p-4 text-left transition hover:border-neon-green/60 hover:bg-neon-green/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
              <RoleIcon path="M5 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm14 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM7 19h7l2-6h-5l-2-4H6m10 4h2l2 4" />
              <span className="min-w-0 flex-1"><span className="block font-bold text-white">Rider</span><span className="mt-1 block text-sm text-white/45">Apply to deliver orders with Foodjett.</span></span>
              <ArrowIcon />
            </Link>
          </div>

          <p className="mt-6 text-center text-sm text-white/50">Already have an account? <Link to="/login" className="font-semibold text-neon-green">Log in</Link></p>
        </section>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout showStandaloneLogo={false}>
      <section aria-labelledby="register-heading" className="rounded-2xl border border-white/10 bg-brand-surface/95 p-5 shadow-[0_24px_70px_rgba(0,0,0,0.5)] backdrop-blur-sm sm:p-8">
        <header className="mb-7 text-center">
          <h1 id="register-heading" className="sr-only">Create your Foodjett account</h1>
          <div className="flex justify-center">
            <Logo />
          </div>
          <button type="button" onClick={() => setShowCustomerForm(false)} className="mt-4 min-h-11 text-sm font-semibold text-white/55 hover:text-neon-green">← Choose another account type</button>
        </header>

        <FormAlert message={globalError} />

        <form onSubmit={handleSubmit} noValidate className="space-y-5">
          <NeonInput
            id="register-name"
            name="name"
            label="Full name"
            autoComplete="name"
            maxLength={255}
            placeholder="Jane Doe"
            value={fields.name}
            onChange={handleChange}
            onBlur={() => handleBlur('name')}
            error={errors.name}
            disabled={loading}
          />

          <NeonInput
            id="register-email"
            name="email"
            label="Email address"
            type="email"
            inputMode="email"
            autoComplete="email"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={255}
            placeholder="you@example.com"
            value={fields.email}
            onChange={handleChange}
            onBlur={() => handleBlur('email')}
            error={errors.email}
            disabled={loading}
          />

          <NeonInput
            id="register-phone"
            name="phone"
            label="Phone number"
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            maxLength={25}
            placeholder="+63 912 345 6789"
            value={fields.phone}
            onChange={handleChange}
            onBlur={() => handleBlur('phone')}
            error={errors.phone}
            hint="Spaces and dashes are okay; include your country code."
            disabled={loading}
          />

          <div>
            <NeonInput
              id="register-password"
              name="password"
              label="Password"
              type="password"
              autoComplete="new-password"
              minLength={8}
              placeholder="At least 8 characters"
              value={fields.password}
              onChange={handleChange}
              onBlur={() => handleBlur('password')}
              error={errors.password}
              disabled={loading}
              showPasswordToggle
            />
            <PasswordStrengthMeter password={fields.password} />
          </div>

          <NeonInput
            id="register-password-confirmation"
            name="password_confirmation"
            label="Confirm password"
            type="password"
            autoComplete="new-password"
            placeholder="Re-enter your password"
            value={fields.password_confirmation}
            onChange={handleChange}
            onBlur={() => handleBlur('password_confirmation')}
            error={errors.password_confirmation}
            disabled={loading}
            showPasswordToggle
          />

          <SubmitButton loading={loading} loadingLabel="Creating account...">Create account</SubmitButton>
        </form>

        <p className="mt-6 text-center text-sm text-white/50">
          Already have an account?{' '}
          <Link to="/login" className="inline-flex min-h-11 items-center font-semibold text-neon-green transition-colors hover:text-[#55ff35] focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
            Log in
          </Link>
        </p>
      </section>
    </AuthLayout>
  )
}

function RoleIcon({ path }: { path: string }) {
  return <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-neon-green/10 text-neon-green transition group-hover:bg-neon-green group-hover:text-brand-black"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-6 w-6"><path strokeLinecap="round" strokeLinejoin="round" d={path} /></svg></span>
}

function ArrowIcon() {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-5 w-5 shrink-0 text-white/25 transition group-hover:translate-x-0.5 group-hover:text-neon-green"><path strokeLinecap="round" strokeLinejoin="round" d="m9 5 7 7-7 7" /></svg>
}

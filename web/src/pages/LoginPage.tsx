import { useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { FormAlert } from '../components/FormAlert'
import { Logo } from '../components/Logo'
import { NeonInput } from '../components/NeonInput'
import { SubmitButton } from '../components/SubmitButton'
import { isApiError, login, setToken } from '../lib/api'

type LoginField = 'email' | 'password'
type LoginErrors = Partial<Record<LoginField, string>>

function validateEmail(email: string): string | undefined {
  if (!email.trim()) return 'Email is required.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) return 'Enter a valid email address.'
  return undefined
}

function validatePassword(password: string): string | undefined {
  return password ? undefined : 'Password is required.'
}

export default function LoginPage() {
  const navigate = useNavigate()
  const [fields, setFields] = useState({ email: '', password: '' })
  const [errors, setErrors] = useState<LoginErrors>({})
  const [globalError, setGlobalError] = useState('')
  const [loading, setLoading] = useState(false)

  function validateField(field: LoginField, value: string): string | undefined {
    return field === 'email' ? validateEmail(value) : validatePassword(value)
  }

  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    const field = event.target.name as LoginField
    setFields((current) => ({ ...current, [field]: event.target.value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
    setGlobalError('')
  }

  function handleBlur(field: LoginField) {
    setErrors((current) => ({ ...current, [field]: validateField(field, fields[field]) }))
  }

  function validate(): boolean {
    const nextErrors: LoginErrors = {
      email: validateEmail(fields.email),
      password: validatePassword(fields.password),
    }
    setErrors(nextErrors)
    return !nextErrors.email && !nextErrors.password
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (loading || !validate()) return

    setLoading(true)
    setGlobalError('')

    try {
      const response = await login(fields.email.trim().toLowerCase(), fields.password)
      setToken(response.token)
      navigate('/', { replace: true })
    } catch (error: unknown) {
      if (isApiError(error) && error.status === 401) {
        setGlobalError('Incorrect email or password. Please try again.')
      } else if (isApiError(error) && error.status === 403) {
        setGlobalError(error.message)
      } else {
        setGlobalError(isApiError(error) ? error.message : 'Unable to sign in right now. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthLayout showStandaloneLogo={false}>
      <section aria-labelledby="login-heading" className="rounded-2xl border border-white/10 bg-brand-surface/95 p-5 shadow-[0_24px_70px_rgba(0,0,0,0.5)] backdrop-blur-sm sm:p-8">
        <header className="mb-7 text-center">
          <h1 id="login-heading" className="sr-only">Sign in to Foodjett</h1>
          <div className="flex justify-center">
            <Logo />
          </div>
        </header>

        <FormAlert message={globalError} />

        <form onSubmit={handleSubmit} noValidate className="space-y-5">
          <NeonInput
            id="login-email"
            name="email"
            label="Email address"
            type="email"
            inputMode="email"
            autoComplete="email"
            autoCapitalize="none"
            spellCheck={false}
            placeholder="you@example.com"
            value={fields.email}
            onChange={handleChange}
            onBlur={() => handleBlur('email')}
            error={errors.email}
            disabled={loading}
          />

          <div>
            <NeonInput
              id="login-password"
              name="password"
              label="Password"
              type="password"
              autoComplete="current-password"
              placeholder="Enter your password"
              value={fields.password}
              onChange={handleChange}
              onBlur={() => handleBlur('password')}
              error={errors.password}
              disabled={loading}
              showPasswordToggle
            />
            <div className="mt-2 text-right">
              <Link to="/forgot-password" className="inline-flex min-h-11 items-center text-sm font-medium text-neon-green/80 transition-colors hover:text-neon-green focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
                Forgot password?
              </Link>
            </div>
          </div>

          <SubmitButton loading={loading} loadingLabel="Signing in...">Sign in</SubmitButton>
        </form>

        <p className="mt-6 text-center text-sm text-white/50">
          Don&apos;t have an account?{' '}
          <Link to="/register" className="inline-flex min-h-11 items-center font-semibold text-neon-green transition-colors hover:text-[#55ff35] focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
            Sign up
          </Link>
        </p>
      </section>
    </AuthLayout>
  )
}

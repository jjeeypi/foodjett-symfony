import { useState } from 'react'
import type { ChangeEvent, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { FormAlert } from '../components/FormAlert'
import { NeonInput } from '../components/NeonInput'
import { SubmitButton } from '../components/SubmitButton'
import { homeForRole } from '../auth/auth-routing'
import { useAuth } from '../auth/useAuth'
import { isApiError, login } from '../lib/api'

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
  const { completeLogin } = useAuth()
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
      const user = await completeLogin(response.token)
      navigate(homeForRole(user.role), { replace: true })
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
    <AuthLayout>
      <section aria-labelledby="login-heading" className="fj-auth-card fj-auth-card--compact">
        <header className="fj-auth-card-header">
          <p>Welcome back</p>
          <h1 id="login-heading">Sign in to Foodjett</h1>
          <p>Customer, restaurant, rider, or admin—use the same secure login.</p>
        </header>

        <FormAlert message={globalError} />

        <form onSubmit={handleSubmit} noValidate className="fj-auth-form-stack">
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

          <div className="fj-auth-password-group">
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
            <div className="fj-auth-password-tools">
              <Link to="/forgot-password" className="fj-auth-text-link">
                Forgot password?
              </Link>
            </div>
          </div>

          <SubmitButton loading={loading} loadingLabel="Signing in...">Sign in</SubmitButton>
        </form>

        <p className="fj-auth-card-footer">
          Don&apos;t have an account?{' '}
          <Link to="/register" className="fj-auth-text-link">
            Sign up
          </Link>
        </p>
      </section>
    </AuthLayout>
  )
}

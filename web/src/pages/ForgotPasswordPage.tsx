import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { AuthLayout } from '../components/AuthLayout'
import { FormAlert } from '../components/FormAlert'
import { NeonInput } from '../components/NeonInput'
import { SubmitButton } from '../components/SubmitButton'
import { forgotPassword, isApiError } from '../lib/api'

function emailError(email: string): string | undefined {
  if (!email.trim()) return 'Email is required.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) return 'Enter a valid email address.'
  return undefined
}

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [error, setError] = useState<string>()
  const [globalError, setGlobalError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [loading, setLoading] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (loading) return

    const nextError = emailError(email)
    setError(nextError)
    if (nextError) return

    setLoading(true)
    setGlobalError('')

    try {
      const response = await forgotPassword(email.trim().toLowerCase())
      setSuccessMessage(response.message)
    } catch (requestError: unknown) {
      if (isApiError(requestError) && requestError.errors?.email?.[0]) {
        setError(requestError.errors.email[0])
      } else {
        setGlobalError(isApiError(requestError) ? requestError.message : 'Unable to request a reset right now. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <AuthLayout>
      <section aria-labelledby="forgot-heading" className="fj-auth-card fj-auth-card--compact">
        <header className="fj-auth-card-header">
          <p>Account recovery</p>
          <h1 id="forgot-heading">Reset your password</h1>
          <p>Enter your email and we&apos;ll send reset instructions.</p>
        </header>

        <FormAlert message={globalError} />

        {successMessage ? (
          <div role="status" className="fj-auth-success">
            <svg aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <p>{successMessage}</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} noValidate className="fj-auth-form-stack">
            <NeonInput
              id="forgot-email"
              name="email"
              label="Email address"
              type="email"
              inputMode="email"
              autoComplete="email"
              autoCapitalize="none"
              spellCheck={false}
              placeholder="you@example.com"
              value={email}
              onChange={(event) => {
                setEmail(event.target.value)
                setError(undefined)
                setGlobalError('')
              }}
              onBlur={() => setError(emailError(email))}
              error={error}
              disabled={loading}
            />

            <SubmitButton loading={loading} loadingLabel="Sending...">Send reset instructions</SubmitButton>
          </form>
        )}

        <p className="fj-auth-card-footer">
          Remember your password?{' '}
          <Link to="/login" className="fj-auth-text-link">
            Back to login
          </Link>
        </p>
      </section>
    </AuthLayout>
  )
}

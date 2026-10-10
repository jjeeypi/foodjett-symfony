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
      <section aria-labelledby="forgot-heading" className="rounded-2xl border border-white/10 bg-brand-surface/95 p-5 shadow-[0_24px_70px_rgba(0,0,0,0.5)] backdrop-blur-sm sm:p-8">
        <header className="mb-7">
          <h1 id="forgot-heading" className="text-2xl font-bold tracking-tight text-white">Reset your password</h1>
          <p className="mt-1 text-sm text-white/55">Enter your email and we&apos;ll send reset instructions.</p>
        </header>

        <FormAlert message={globalError} />

        {successMessage ? (
          <div role="status" className="rounded-xl border border-neon-green/35 bg-neon-green/10 px-4 py-5 text-center text-sm text-neon-green">
            <svg aria-hidden="true" className="mx-auto mb-3 h-8 w-8" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" d="m9 12 2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <p className="font-medium">{successMessage}</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} noValidate className="space-y-5">
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

        <p className="mt-6 text-center text-sm text-white/50">
          Remember your password?{' '}
          <Link to="/login" className="inline-flex min-h-11 items-center font-semibold text-neon-green transition-colors hover:text-[#55ff35] focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60">
            Back to login
          </Link>
        </p>
      </section>
    </AuthLayout>
  )
}

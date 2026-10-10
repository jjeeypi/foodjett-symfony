import { forwardRef, useState } from 'react'
import type { InputHTMLAttributes } from 'react'

export interface NeonInputProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  id: string
  error?: string
  hint?: string
  showPasswordToggle?: boolean
}

function EyeIcon({ visible }: { visible: boolean }) {
  if (visible) {
    return (
      <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6S2.25 12 2.25 12Z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
    )
  }

  return (
    <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
      <path strokeLinecap="round" strokeLinejoin="round" d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.3A10.8 10.8 0 0 1 12 4c6.25 0 9.75 6 9.75 6a15.8 15.8 0 0 1-2.2 2.9M6.2 6.2A16 16 0 0 0 2.25 12S5.75 18 12 18c1.3 0 2.45-.26 3.45-.68" />
    </svg>
  )
}

export const NeonInput = forwardRef<HTMLInputElement, NeonInputProps>(
  (
    {
      label,
      id,
      error,
      hint,
      showPasswordToggle = false,
      className = '',
      disabled,
      type = 'text',
      ...props
    },
    ref,
  ) => {
    const [passwordVisible, setPasswordVisible] = useState(false)
    const inputType = showPasswordToggle && passwordVisible ? 'text' : type
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null]
      .filter(Boolean)
      .join(' ') || undefined
    const stateClasses = error
      ? 'border-red-400 focus:border-red-300 focus:ring-red-400/25 focus:shadow-[0_0_12px_rgba(248,113,113,0.22)]'
      : 'border-neon-green/80 focus:border-neon-green focus:ring-neon-green/25 focus:shadow-[0_0_12px_rgba(57,255,20,0.24)]'

    return (
      <div className="w-full">
        <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-white/85">
          {label}
        </label>

        <div className="relative">
          <input
            {...props}
            ref={ref}
            id={id}
            type={inputType}
            disabled={disabled}
            aria-invalid={error ? true : undefined}
            aria-describedby={describedBy}
            className={[
              'min-h-12 w-full rounded-xl border-2 bg-brand-surface-2/90 px-4 py-3 text-base text-white outline-none transition-[border-color,box-shadow,background-color,opacity] duration-200',
              'placeholder:text-white/35 focus:ring-2',
              showPasswordToggle ? 'pr-12' : '',
              disabled
                ? 'cursor-not-allowed border-white/15 bg-white/[0.035] text-white/40 opacity-65 focus:ring-0 focus:shadow-none'
                : stateClasses,
              className,
            ].filter(Boolean).join(' ')}
          />

          {showPasswordToggle && (
            <button
              type="button"
              onClick={() => setPasswordVisible((visible) => !visible)}
              disabled={disabled}
              aria-label={passwordVisible ? 'Hide password' : 'Show password'}
              aria-pressed={passwordVisible}
              className="absolute right-1 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-white/55 transition-colors hover:text-neon-green focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green/60 disabled:cursor-not-allowed disabled:text-white/20"
            >
              <EyeIcon visible={passwordVisible} />
            </button>
          )}
        </div>

        {error && (
          <p id={`${id}-error`} role="alert" className="mt-1.5 flex items-start gap-1.5 text-sm text-red-400">
            <svg aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" clipRule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm-1-9a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0V6a1 1 0 0 0-1-1Z" />
            </svg>
            {error}
          </p>
        )}

        {hint && (
          <p id={`${id}-hint`} className={`mt-1.5 text-xs ${error ? 'sr-only' : 'text-white/45'}`}>
            {hint}
          </p>
        )}
      </div>
    )
  },
)

NeonInput.displayName = 'NeonInput'

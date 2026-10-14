import { forwardRef, useState } from 'react'
import type { InputHTMLAttributes } from 'react'

export interface NeonInputProps extends InputHTMLAttributes<HTMLInputElement> { label: string; id: string; error?: string; hint?: string; showPasswordToggle?: boolean }

function EyeIcon({ visible }: { visible: boolean }) {
  return visible
    ? <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6S2.25 12 2.25 12Z" /><circle cx="12" cy="12" r="3" /></svg>
    : <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.3A10.8 10.8 0 0 1 12 4c6.25 0 9.75 6 9.75 6a15.8 15.8 0 0 1-2.2 2.9M6.2 6.2A16 16 0 0 0 2.25 12S5.75 18 12 18c1.3 0 2.45-.26 3.45-.68" /></svg>
}

export const NeonInput = forwardRef<HTMLInputElement, NeonInputProps>(({ label, id, error, hint, showPasswordToggle = false, className = '', disabled, type = 'text', ...props }, ref) => {
  const [passwordVisible, setPasswordVisible] = useState(false)
  const inputType = showPasswordToggle && passwordVisible ? 'text' : type
  const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined
  return <div className={`fj-auth-field${error ? ' is-invalid' : ''}${disabled ? ' is-disabled' : ''}`}>
    <label htmlFor={id}>{label}</label>
    <div className="fj-auth-input-wrap">
      <input {...props} ref={ref} id={id} type={inputType} disabled={disabled} aria-invalid={error ? true : undefined} aria-describedby={describedBy} className={`${showPasswordToggle ? 'has-toggle ' : ''}${className}`.trim()} />
      {showPasswordToggle && <button type="button" onClick={() => setPasswordVisible((visible) => !visible)} disabled={disabled} aria-label={passwordVisible ? 'Hide password' : 'Show password'} aria-pressed={passwordVisible} className="fj-auth-password-toggle"><EyeIcon visible={passwordVisible} /></button>}
    </div>
    {error && <p id={`${id}-error`} role="alert" className="fj-auth-field-error"><span aria-hidden="true">!</span>{error}</p>}
    {hint && <p id={`${id}-hint`} className={error ? 'sr-only' : 'fj-auth-field-hint'}>{hint}</p>}
  </div>
})
NeonInput.displayName = 'NeonInput'

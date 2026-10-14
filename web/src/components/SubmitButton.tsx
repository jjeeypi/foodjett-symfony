import type { ButtonHTMLAttributes, ReactNode } from 'react'
interface SubmitButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> { children: ReactNode; loading?: boolean; loadingLabel?: string }
export function SubmitButton({ children, loading = false, loadingLabel = 'Please wait...', disabled, className = '', ...props }: SubmitButtonProps) {
  const isDisabled = disabled || loading
  return <button {...props} type="submit" disabled={isDisabled} aria-busy={loading} className={`fj-auth-submit ${className}`.trim()}>{loading ? <span><svg aria-hidden="true" className="fj-auth-spin" fill="none" viewBox="0 0 24 24"><circle opacity=".24" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path opacity=".8" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" /></svg>{loadingLabel}</span> : children}</button>
}

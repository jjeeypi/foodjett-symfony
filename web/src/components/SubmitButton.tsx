import type { ButtonHTMLAttributes, ReactNode } from 'react'

interface SubmitButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  children: ReactNode
  loading?: boolean
  loadingLabel?: string
}

export function SubmitButton({
  children,
  loading = false,
  loadingLabel = 'Please wait...',
  disabled,
  className = '',
  ...props
}: SubmitButtonProps) {
  const isDisabled = disabled || loading

  return (
    <button
      {...props}
      type="submit"
      disabled={isDisabled}
      aria-busy={loading}
      className={[
        'flex min-h-12 w-full items-center justify-center rounded-xl bg-neon-green px-5 py-3 text-base font-bold tracking-wide text-brand-black transition-[transform,background-color,box-shadow,opacity] duration-200',
        'hover:bg-[#55ff35] hover:shadow-[0_0_20px_rgba(57,255,20,0.38)] active:scale-[0.985]',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-black',
        isDisabled ? 'cursor-not-allowed opacity-55 hover:shadow-none active:scale-100' : 'cursor-pointer',
        className,
      ].filter(Boolean).join(' ')}
    >
      {loading ? (
        <span className="flex items-center justify-center gap-2">
          <svg aria-hidden="true" className="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" />
          </svg>
          {loadingLabel}
        </span>
      ) : children}
    </button>
  )
}

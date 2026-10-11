import type { ChangeEvent } from 'react'

interface DocumentUploadFieldProps {
  id: string
  name: string
  label: string
  hint?: string
  file: File | null
  error?: string
  disabled?: boolean
  onChange: (event: ChangeEvent<HTMLInputElement>) => void
}

export function DocumentUploadField({ id, name, label, hint, file, error, disabled, onChange }: DocumentUploadFieldProps) {
  const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined

  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-white/85">{label}</label>
      <label
        htmlFor={id}
        className={`flex min-h-14 cursor-pointer items-center justify-between gap-3 rounded-xl border-2 bg-brand-surface-2/90 px-4 py-3 transition-colors ${
          error ? 'border-red-400' : 'border-neon-green/55 hover:border-neon-green'
        } ${disabled ? 'cursor-not-allowed opacity-55' : ''}`}
      >
        <span className={`min-w-0 truncate text-sm ${file ? 'text-white' : 'text-white/45'}`}>
          {file?.name ?? 'Choose PDF or image'}
        </span>
        <span className="shrink-0 rounded-lg bg-neon-green/10 px-3 py-2 text-xs font-bold text-neon-green">Browse</span>
      </label>
      <input
        id={id}
        name={name}
        type="file"
        accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif"
        className="sr-only"
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        onChange={onChange}
      />
      {error ? <p id={`${id}-error`} role="alert" className="mt-1.5 text-sm text-red-400">{error}</p> : null}
      {hint ? <p id={`${id}-hint`} className={`mt-1.5 text-xs ${error ? 'sr-only' : 'text-white/45'}`}>{hint}</p> : null}
    </div>
  )
}

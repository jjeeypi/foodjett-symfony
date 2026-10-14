import type { ChangeEvent } from 'react'
interface DocumentUploadFieldProps { id: string; name: string; label: string; hint?: string; file: File | null; error?: string; disabled?: boolean; onChange: (event: ChangeEvent<HTMLInputElement>) => void }
export function DocumentUploadField({ id, name, label, hint, file, error, disabled, onChange }: DocumentUploadFieldProps) {
  const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined
  return <div className={`fj-auth-upload${error ? ' is-invalid' : ''}${disabled ? ' is-disabled' : ''}`}>
    <label htmlFor={id} className="fj-auth-upload-label">{label}</label>
    <label htmlFor={id} className="fj-auth-upload-box"><span><strong>{file?.name ?? 'Choose a PDF or image'}</strong><small>{file ? 'Ready to upload' : 'Maximum file size: 10 MB'}</small></span><i>Browse</i></label>
    <input id={id} name={name} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif" className="sr-only" disabled={disabled} aria-invalid={error ? true : undefined} aria-describedby={describedBy} onChange={onChange} />
    {error ? <p id={`${id}-error`} role="alert" className="fj-auth-field-error"><span aria-hidden="true">!</span>{error}</p> : null}
    {hint ? <p id={`${id}-hint`} className={error ? 'sr-only' : 'fj-auth-field-hint'}>{hint}</p> : null}
  </div>
}

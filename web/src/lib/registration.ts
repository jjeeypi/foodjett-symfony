export interface AccountRegistrationFields {
  email: string
  phone: string
  password: string
  password_confirmation: string
}

export const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024
const DOCUMENT_TYPES = new Set(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])

export function normalizePhone(phone: string): string {
  return phone.trim().replace(/[\s()-]/g, '')
}

export function accountErrors(fields: AccountRegistrationFields, name: string, nameKey: string): Record<string, string> {
  const errors: Record<string, string> = {}
  if (!name.trim()) errors[nameKey] = 'Name is required.'
  else if (name.trim().length > 255) errors[nameKey] = 'Name must be 255 characters or fewer.'
  if (!fields.email.trim()) errors.email = 'Email is required.'
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(fields.email.trim())) errors.email = 'Enter a valid email address.'
  const phone = normalizePhone(fields.phone)
  if (!phone) errors.phone = 'Phone number is required.'
  else if (!/^\+?[0-9]{7,20}$/.test(phone)) errors.phone = 'Enter 7-20 digits, with an optional leading +.'
  if (!fields.password) errors.password = 'Password is required.'
  else if (fields.password.length < 8) errors.password = 'Password must be at least 8 characters.'
  if (!fields.password_confirmation) errors.password_confirmation = 'Please confirm your password.'
  else if (fields.password_confirmation !== fields.password) errors.password_confirmation = 'Passwords do not match.'
  return errors
}

export function documentError(file: File | null): string | undefined {
  if (!file) return 'This document is required.'
  if (file.size > MAX_DOCUMENT_BYTES) return 'Uploads cannot exceed 10 MB.'
  if (file.type && !DOCUMENT_TYPES.has(file.type)) return 'Use a PDF, JPG, PNG, WebP, HEIC, or HEIF file.'
  return undefined
}

export function firstErrorStep(errors: Record<string, string>, stepFields: string[][]): number {
  const index = stepFields.findIndex((fields) => fields.some((field) => errors[field]))
  return index < 0 ? stepFields.length - 1 : index
}

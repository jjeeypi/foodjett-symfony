export function humanize(value: string | null | undefined): string {
  if (!value) return 'Not provided'
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

export function formatAdminDate(value: string | null | undefined): string {
  if (!value) return 'Not recorded'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Not recorded' : dateTime.format(date)
}

export function formatAdminMoney(value: string | number | null | undefined): string {
  const amount = Number(value ?? 0)
  return money.format(Number.isFinite(amount) ? amount : 0)
}

export function positivePage(value: string | null): number {
  const parsed = Number(value ?? '1')
  return Number.isInteger(parsed) && parsed > 0 ? parsed : 1
}

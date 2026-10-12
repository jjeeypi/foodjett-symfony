export function humanize(value: string | null | undefined): string {
  if (!value) return 'Not provided'
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

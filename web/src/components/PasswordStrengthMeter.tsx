type Strength = 'weak' | 'medium' | 'strong'

function passwordStrength(password: string): Strength {
  let score = 0
  if (password.length >= 8) score += 1
  if (password.length >= 12) score += 1
  if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score += 1
  if (/\d/.test(password)) score += 1
  if (/[^A-Za-z0-9]/.test(password)) score += 1

  if (score <= 2) return 'weak'
  if (score <= 3) return 'medium'
  return 'strong'
}

const strengthStyles: Record<Strength, { width: string; bar: string; text: string }> = {
  weak: { width: 'w-1/3', bar: 'bg-red-500', text: 'text-red-400' },
  medium: { width: 'w-2/3', bar: 'bg-amber-400', text: 'text-amber-300' },
  strong: { width: 'w-full', bar: 'bg-neon-green', text: 'text-neon-green' },
}

export function PasswordStrengthMeter({ password }: { password: string }) {
  if (!password) return null

  const strength = passwordStrength(password)
  const styles = strengthStyles[strength]

  return (
    <div className="mt-2" aria-live="polite">
      <div className="h-1 overflow-hidden rounded-full bg-white/10" aria-hidden="true">
        <div className={`h-full rounded-full transition-all duration-300 ${styles.width} ${styles.bar}`} />
      </div>
      <p className={`mt-1 text-xs ${styles.text}`}>
        Password strength: <span className="font-semibold capitalize">{strength}</span>
      </p>
    </div>
  )
}

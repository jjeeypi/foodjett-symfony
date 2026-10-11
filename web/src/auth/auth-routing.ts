export function homeForRole(role: string): string {
  if (role === 'restaurant') return '/restaurant/dashboard'
  if (role === 'rider') return '/rider/pool'
  if (role === 'customer') return '/'
  return '/unsupported-role'
}

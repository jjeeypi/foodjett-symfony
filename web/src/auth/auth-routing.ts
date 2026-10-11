export function homeForRole(role: string): string {
  if (role === 'restaurant') return '/restaurant/dashboard'
  if (role === 'customer') return '/'
  return '/unsupported-role'
}

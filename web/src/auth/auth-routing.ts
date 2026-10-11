export function homeForRole(role: string): string {
  if (role === 'admin') return '/admin/dashboard'
  if (role === 'restaurant') return '/restaurant/dashboard'
  if (role === 'rider') return '/rider/pool'
  if (role === 'customer') return '/'
  return '/unsupported-role'
}

/** Centralized JSON client for the Foodjett Symfony API. */

const API_BASE = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/$/, '')
const TOKEN_KEY = 'foodjett_access_token'

let accessToken: string | null = readStoredToken()

function readStoredToken(): string | null {
  try {
    return window.sessionStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function getToken(): string | null {
  return accessToken
}

export function setToken(token: string): void {
  accessToken = token
  try {
    window.sessionStorage.setItem(TOKEN_KEY, token)
  } catch {
    // The in-memory token still supports this tab if storage is unavailable.
  }
}

export function clearToken(): void {
  accessToken = null
  try {
    window.sessionStorage.removeItem(TOKEN_KEY)
  } catch {
    // Storage may be unavailable in privacy-restricted browser contexts.
  }
}

export function isAuthenticated(): boolean {
  if (!accessToken) return false

  try {
    const encodedPayload = accessToken.split('.')[1]
    if (!encodedPayload) throw new Error('Malformed JWT')
    const base64 = encodedPayload.replace(/-/g, '+').replace(/_/g, '/')
    const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, '=')
    const payload = JSON.parse(window.atob(padded)) as { exp?: number }

    if (typeof payload.exp === 'number' && payload.exp * 1000 <= Date.now()) {
      clearToken()
      return false
    }

    return true
  } catch {
    clearToken()
    return false
  }
}

export interface ApiError {
  message: string
  errors?: Record<string, string[]>
  status: number
}

export function isApiError(error: unknown): error is ApiError {
  return typeof error === 'object'
    && error !== null
    && 'message' in error
    && 'status' in error
    && typeof (error as ApiError).message === 'string'
    && typeof (error as ApiError).status === 'number'
}

function normalizeFieldErrors(value: unknown): Record<string, string[]> | undefined {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return undefined

  const errors: Record<string, string[]> = {}
  for (const [field, messages] of Object.entries(value)) {
    if (Array.isArray(messages)) {
      const normalized = messages.filter((message): message is string => typeof message === 'string')
      if (normalized.length > 0) errors[field] = normalized
    } else if (typeof messages === 'string') {
      errors[field] = [messages]
    }
  }

  return Object.keys(errors).length > 0 ? errors : undefined
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body && !(options.body instanceof FormData) && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)

  let response: Response
  try {
    response = await fetch(`${API_BASE}${path}`, { ...options, headers })
  } catch {
    throw {
      message: 'Unable to reach Foodjett. Check your connection and try again.',
      status: 0,
    } satisfies ApiError
  }

  if (!response.ok) {
    let body: { message?: unknown; errors?: unknown } = {}
    try {
      body = await response.json() as typeof body
    } catch {
      // Keep the friendly fallback when the server does not return JSON.
    }

    throw {
      message: typeof body.message === 'string' ? body.message : 'Something went wrong. Please try again.',
      errors: normalizeFieldErrors(body.errors),
      status: response.status,
    } satisfies ApiError
  }

  return response.json() as Promise<T>
}

export function apiAssetUrl(path: string | null): string | null {
  if (!path) return null
  if (/^https?:\/\//i.test(path)) return path

  return `${API_BASE}/${path.replace(/^\//, '')}`
}

export interface PaginationMeta {
  page: number
  perPage: number
  total: number
  lastPage: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PaginationMeta
}

export type RestaurantOperatingStatus = 'open' | 'closed' | 'temporarily_closed'

export interface RestaurantProfile {
  id: string
  name: string
  slug: string
  description: string | null
  logo_path: string | null
  cover_photo_path: string | null
  cuisine_type: string | null
  address: string
  latitude: string
  longitude: string
  default_prep_time_minutes: number
  min_order_amount: string
  commission_rate: string
  approval_status: string
  operating_status: RestaurantOperatingStatus
  payout_method: string | null
  payout_account_details: Record<string, unknown> | null
}

export interface RestaurantDashboard {
  today_order_count: number
  today_revenue: string
  pending_orders_count: number
  recent_orders: Array<{
    id: string
    order_number: string
    status: OrderStatus
    total_amount: string
    payment_method: PaymentMethod
    placed_at: string
  }>
}

export interface RestaurantOrderItem {
  id: string
  menu_item_id: string
  name: string
  variant: { id: string; name: string } | null
  quantity: number
  unit_price: string
  special_instructions: string | null
  addons: Array<{ id: string; menu_item_addon_id: string; name: string; price: string }>
}

export interface RestaurantOrderSummary {
  id: string
  order_number: string
  status: OrderStatus
  customer_name: string
  total_amount: string
  payment_method: PaymentMethod
  estimated_prep_minutes: number | null
  estimated_ready_at: string | null
  ready_at: string | null
  rider_search_started_at: string | null
  customer_notes: string | null
  items: RestaurantOrderItem[]
  placed_at: string
}

export interface RestaurantMenuCategory {
  id: string
  name: string
  sort_order: number
  item_count: number
}

export interface RestaurantMenuVariant {
  id: string
  name: string
  price_delta: string
}

export interface RestaurantMenuAddon {
  id: string
  name: string
  price: string
  is_available: boolean
}

export interface RestaurantMenuItem {
  id: string
  name: string
  description: string | null
  photo_path: string | null
  base_price: string
  is_available: boolean
  available_from: string | null
  available_until: string | null
  is_featured: boolean
  category: { id: string; name: string }
  variants: RestaurantMenuVariant[]
  addons: RestaurantMenuAddon[]
}

export interface RestaurantMenuItemInput {
  menu_category_id: string
  name: string
  description: string
  base_price: string
  is_available: boolean
  is_featured: boolean
  available_from: string | null
  available_until: string | null
  photo?: File | null
}

export function getRestaurantMenuCategories(signal?: AbortSignal): Promise<PaginatedResponse<RestaurantMenuCategory>> {
  return request<PaginatedResponse<RestaurantMenuCategory>>('/api/restaurant/menu-categories?perPage=100', { signal })
}

export function createRestaurantMenuCategory(name: string, sortOrder: number): Promise<{ message: string; category: RestaurantMenuCategory }> {
  return request<{ message: string; category: RestaurantMenuCategory }>('/api/restaurant/menu-categories', {
    method: 'POST',
    body: JSON.stringify({ name, sort_order: sortOrder }),
  })
}

export function updateRestaurantMenuCategory(id: string, data: { name?: string; sort_order?: number }): Promise<{ message: string; category: RestaurantMenuCategory }> {
  return request<{ message: string; category: RestaurantMenuCategory }>(`/api/restaurant/menu-categories/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify(data),
  })
}

export function deleteRestaurantMenuCategory(id: string): Promise<{ message: string }> {
  return request<{ message: string }>(`/api/restaurant/menu-categories/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

export function getRestaurantMenuItems(signal?: AbortSignal): Promise<PaginatedResponse<RestaurantMenuItem>> {
  return request<PaginatedResponse<RestaurantMenuItem>>('/api/restaurant/menu-items?perPage=100', { signal })
}

function restaurantMenuItemFormData(data: RestaurantMenuItemInput): FormData {
  const body = new FormData()
  body.set('menu_category_id', data.menu_category_id)
  body.set('name', data.name)
  body.set('description', data.description)
  body.set('base_price', data.base_price)
  body.set('is_available', data.is_available ? '1' : '0')
  body.set('is_featured', data.is_featured ? '1' : '0')
  body.set('available_from', data.available_from ?? '')
  body.set('available_until', data.available_until ?? '')
  if (data.photo) body.set('photo', data.photo)
  return body
}

export function createRestaurantMenuItem(data: RestaurantMenuItemInput): Promise<{ message: string; item: RestaurantMenuItem }> {
  return request<{ message: string; item: RestaurantMenuItem }>('/api/restaurant/menu-items', {
    method: 'POST',
    body: restaurantMenuItemFormData(data),
  })
}

export function updateRestaurantMenuItem(id: string, data: Omit<RestaurantMenuItemInput, 'photo'>): Promise<{ message: string; item: RestaurantMenuItem }> {
  return request<{ message: string; item: RestaurantMenuItem }>(`/api/restaurant/menu-items/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify(data),
  })
}

export function uploadRestaurantMenuItemPhoto(id: string, photo: File): Promise<{ message: string; item: RestaurantMenuItem }> {
  const body = new FormData()
  body.set('photo', photo)
  return request<{ message: string; item: RestaurantMenuItem }>(`/api/restaurant/menu-items/${encodeURIComponent(id)}/photo`, {
    method: 'POST',
    body,
  })
}

export function toggleRestaurantMenuItemAvailability(id: string, isAvailable: boolean): Promise<{ message: string; is_available: boolean }> {
  return request<{ message: string; is_available: boolean }>(`/api/restaurant/menu-items/${encodeURIComponent(id)}/toggle-availability`, {
    method: 'PATCH',
    body: JSON.stringify({ is_available: isAvailable }),
  })
}

export function deleteRestaurantMenuItem(id: string): Promise<{ message: string }> {
  return request<{ message: string }>(`/api/restaurant/menu-items/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

export function createRestaurantMenuVariant(itemId: string, data: { name: string; price_delta: string }): Promise<{ message: string; variant: RestaurantMenuVariant }> {
  return request<{ message: string; variant: RestaurantMenuVariant }>(`/api/restaurant/menu-items/${encodeURIComponent(itemId)}/variants`, { method: 'POST', body: JSON.stringify(data) })
}

export function updateRestaurantMenuVariant(itemId: string, id: string, data: { name: string; price_delta: string }): Promise<{ message: string; variant: RestaurantMenuVariant }> {
  return request<{ message: string; variant: RestaurantMenuVariant }>(`/api/restaurant/menu-items/${encodeURIComponent(itemId)}/variants/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
}

export function deleteRestaurantMenuVariant(itemId: string, id: string): Promise<{ message: string }> {
  return request<{ message: string }>(`/api/restaurant/menu-items/${encodeURIComponent(itemId)}/variants/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

export function createRestaurantMenuAddon(itemId: string, data: { name: string; price: string; is_available: boolean }): Promise<{ message: string; addon: RestaurantMenuAddon }> {
  return request<{ message: string; addon: RestaurantMenuAddon }>(`/api/restaurant/menu-items/${encodeURIComponent(itemId)}/addons`, { method: 'POST', body: JSON.stringify(data) })
}

export function updateRestaurantMenuAddon(itemId: string, id: string, data: { name: string; price: string; is_available: boolean }): Promise<{ message: string; addon: RestaurantMenuAddon }> {
  return request<{ message: string; addon: RestaurantMenuAddon }>(`/api/restaurant/menu-items/${encodeURIComponent(itemId)}/addons/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify(data) })
}

export function deleteRestaurantMenuAddon(itemId: string, id: string): Promise<{ message: string }> {
  return request<{ message: string }>(`/api/restaurant/menu-items/${encodeURIComponent(itemId)}/addons/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

export function getRestaurantProfile(signal?: AbortSignal): Promise<{ restaurant: RestaurantProfile }> {
  return request<{ restaurant: RestaurantProfile }>('/api/restaurant/profile', { signal })
}

export function updateRestaurantOperatingStatus(operatingStatus: RestaurantOperatingStatus): Promise<{ message: string; operating_status: RestaurantOperatingStatus }> {
  return request<{ message: string; operating_status: RestaurantOperatingStatus }>('/api/restaurant/operating-status', {
    method: 'PATCH',
    body: JSON.stringify({ operating_status: operatingStatus }),
  })
}

export function getRestaurantDashboard(signal?: AbortSignal): Promise<RestaurantDashboard> {
  return request<RestaurantDashboard>('/api/restaurant/dashboard', { signal })
}

export function getRestaurantOrders(signal?: AbortSignal): Promise<PaginatedResponse<RestaurantOrderSummary> & { orders: RestaurantOrderSummary[] }> {
  return request<PaginatedResponse<RestaurantOrderSummary> & { orders: RestaurantOrderSummary[] }>('/api/restaurant/orders?perPage=100', { signal })
}

export function acceptRestaurantOrder(id: string, estimatedPrepMinutes: number): Promise<{ order: RestaurantOrderSummary }> {
  return request<{ order: RestaurantOrderSummary }>(`/api/restaurant/orders/${encodeURIComponent(id)}/accept`, {
    method: 'POST',
    body: JSON.stringify({ estimated_prep_minutes: estimatedPrepMinutes }),
  })
}

export function rejectRestaurantOrder(id: string, reason: string): Promise<{ order: RestaurantOrderSummary }> {
  return request<{ order: RestaurantOrderSummary }>(`/api/restaurant/orders/${encodeURIComponent(id)}/reject`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

export function extendRestaurantOrderPrepTime(id: string, minutes: number): Promise<{ order: RestaurantOrderSummary }> {
  return request<{ order: RestaurantOrderSummary }>(`/api/restaurant/orders/${encodeURIComponent(id)}/extend-prep-time`, {
    method: 'POST',
    body: JSON.stringify({ minutes }),
  })
}

export function markRestaurantOrderReady(id: string): Promise<{ order: RestaurantOrderSummary }> {
  return request<{ order: RestaurantOrderSummary }>(`/api/restaurant/orders/${encodeURIComponent(id)}/mark-ready`, {
    method: 'POST',
  })
}

export type RiderAvailabilityStatus = 'offline' | 'available' | 'busy'

export interface RiderProfile {
  id: string
  name: string
  email: string | null
  phone: string | null
  avatar_path: string | null
  vehicle_type: 'motorcycle' | 'bicycle' | 'car'
  plate_number: string | null
  approval_status: string
  availability_status: RiderAvailabilityStatus
  payout_method: 'bank' | 'ewallet' | null
  payout_account_details: Record<string, unknown> | null
}

export interface RiderPoolOffer {
  order_id: string
  order_number: string
  restaurant: { name: string; latitude: string; longitude: string }
  pickup_distance_km: string
  delivery_distance_km: string
  estimated_pay: string
  pay_breakdown: {
    base_pay: string
    distance_pay: string
    incentive_pay: string
    total: string
  }
  estimated_ready_at: string | null
  search_radius_km: string
  escalation_stage: 'initial' | 'widened' | 'incentivized' | 'admin_alerted' | 'customer_notified' | 'auto_cancelled'
  payment_method: PaymentMethod
}

export interface RiderActiveOrder {
  id: string
  order_number: string
  status: OrderStatus
  restaurant: { name: string; address: string; latitude: string; longitude: string }
  delivery_address: {
    address_line: string
    landmark: string | null
    instructions: string | null
    latitude: string
    longitude: string
  }
  customer: { name: string; phone: string | null }
  items: Array<{ name: string; quantity: number; special_instructions: string | null }>
  customer_notes: string | null
  payment_method: PaymentMethod
  cash_to_collect: string | null
  estimated_ready_at: string | null
  pickup_code_required: boolean
  proof_of_delivery_path: string | null
  delivered_at: string | null
}

export interface RiderConversation {
  id: string
  type: 'customer_rider'
  order: { id: string; order_number: string; restaurant_name: string }
  counterpart: { type: 'customer'; id: string; name: string }
  is_closed: boolean
  closed_at: string | null
  unread_count: number
  last_message: ConversationMessage | null
  updated_at: string | null
}

export function getRiderProfile(signal?: AbortSignal): Promise<{ profile: RiderProfile }> {
  return request<{ profile: RiderProfile }>('/api/rider/profile', { signal })
}

export function toggleRiderAvailability(location?: { latitude: number; longitude: number }): Promise<{
  availability_status: RiderAvailabilityStatus
  latitude: string | null
  longitude: string | null
  last_location_at: string | null
}> {
  return request('/api/rider/availability', {
    method: 'PATCH',
    body: JSON.stringify(location ?? {}),
  })
}

export function getRiderPool(signal?: AbortSignal): Promise<{ orders: RiderPoolOffer[]; reason?: string }> {
  return request<{ orders: RiderPoolOffer[]; reason?: string }>('/api/rider/pool', { signal })
}

export function acceptRiderPoolOrder(id: string): Promise<{ order: { id: string; order_number: string; status: OrderStatus; rider_id: string; rider_assigned_at: string; pickup_code: string } }> {
  return request(`/api/rider/pool/${encodeURIComponent(id)}/accept`, { method: 'POST' })
}

export function declineRiderPoolOrder(id: string): Promise<{ decline: { order_id: string; action: string } }> {
  return request(`/api/rider/pool/${encodeURIComponent(id)}/decline`, { method: 'POST' })
}

export function getRiderActiveOrder(signal?: AbortSignal): Promise<{ message?: string; order: RiderActiveOrder | null }> {
  return request<{ message?: string; order: RiderActiveOrder | null }>('/api/rider/active-order', { signal })
}

export function riderArrivedAtRestaurant(): Promise<{ order: RiderActiveOrder }> {
  return request('/api/rider/active-order/arrived-at-restaurant', { method: 'POST' })
}

export function riderConfirmPickup(pickupCode: string): Promise<{ order: RiderActiveOrder }> {
  return request('/api/rider/active-order/confirm-pickup', { method: 'POST', body: JSON.stringify({ pickup_code: pickupCode }) })
}

export function riderStartDelivery(): Promise<{ order: RiderActiveOrder }> {
  return request('/api/rider/active-order/start-delivery', { method: 'POST' })
}

export function riderArrivedAtCustomer(): Promise<{ order: RiderActiveOrder }> {
  return request('/api/rider/active-order/arrived-at-customer', { method: 'POST' })
}

export function riderConfirmDelivery(proof: File, cashCollected: boolean): Promise<{ message: string; order: RiderActiveOrder; earning: { total_earned: string } | null }> {
  const body = new FormData()
  body.set('proof', proof)
  body.set('cashCollected', cashCollected ? 'true' : 'false')
  return request('/api/rider/active-order/confirm-delivery', { method: 'POST', body })
}

export function updateRiderLocation(latitude: number, longitude: number): Promise<{ updated: boolean; throttled: boolean }> {
  return request('/api/rider/location', { method: 'POST', body: JSON.stringify({ latitude, longitude }) })
}

export function riderCustomerUnreachable(): Promise<{ message: string; order: RiderActiveOrder }> {
  return request('/api/rider/active-order/customer-unreachable', { method: 'POST' })
}

export function getRiderConversations(signal?: AbortSignal): Promise<PaginatedResponse<RiderConversation>> {
  return request<PaginatedResponse<RiderConversation>>('/api/rider/conversations?perPage=100', { signal })
}

export function getRiderConversationMessages(id: string, signal?: AbortSignal): Promise<PaginatedResponse<ConversationMessage> & { conversation: RiderConversation }> {
  return request(`/api/rider/conversations/${encodeURIComponent(id)}/messages?perPage=100`, { signal })
}

export function sendRiderConversationMessage(id: string, body: string): Promise<{ message: ConversationMessage }> {
  return request(`/api/rider/conversations/${encodeURIComponent(id)}/messages`, { method: 'POST', body: JSON.stringify({ body }) })
}

export function markRiderConversationRead(id: string): Promise<{ updated: number }> {
  return request(`/api/rider/conversations/${encodeURIComponent(id)}/mark-read`, { method: 'POST' })
}

export function getRiderEarningsSummary(signal?: AbortSignal): Promise<{ summary: { today: string; week: string; month: string } }> {
  return request('/api/rider/earnings/summary', { signal })
}

export interface RestaurantSummary {
  id: string
  name: string
  slug: string
  logo_path: string | null
  cuisine_type: string | null
  operating_status: 'open' | 'closed' | 'temporarily_closed'
  is_open_now: boolean
  rating: number
  review_count: number
  distance_km: number | null
}

export interface RestaurantOperatingHour {
  day_of_week: number
  opens_at: string
  closes_at: string
}

export interface MenuItemVariant {
  id: string
  name: string
  price_delta: string
}

export interface MenuItemAddon {
  id: string
  name: string
  price: string
  is_available: boolean
}

export interface CustomerMenuItem {
  id: string
  name: string
  description: string | null
  photo_path: string | null
  base_price: string
  is_available: boolean
  available_from: string | null
  available_until: string | null
  is_featured: boolean
  category: { id: string; name: string }
  variants: MenuItemVariant[]
  addons: MenuItemAddon[]
}

export interface CustomerMenuCategory {
  id: string
  name: string
  sort_order: number
  items: CustomerMenuItem[]
}

export interface RestaurantDetail extends RestaurantSummary {
  description: string | null
  cover_photo_path: string | null
  address: string
  latitude: string
  longitude: string
  default_prep_time_minutes: number
  min_order_amount: string
  operating_hours: RestaurantOperatingHour[]
  menu_categories: CustomerMenuCategory[]
}

export function getRestaurants(
  filters: { openNow?: boolean; cuisineType?: string; rating?: number; page?: number; perPage?: number } = {},
  signal?: AbortSignal,
): Promise<PaginatedResponse<RestaurantSummary>> {
  const params = new URLSearchParams()
  if (filters.openNow !== undefined) params.set('open_now', filters.openNow ? '1' : '0')
  if (filters.cuisineType) params.set('cuisine_type', filters.cuisineType)
  if (filters.rating !== undefined) params.set('rating', String(filters.rating))
  if (filters.page !== undefined) params.set('page', String(filters.page))
  if (filters.perPage !== undefined) params.set('perPage', String(filters.perPage))
  const query = params.toString()

  return request<PaginatedResponse<RestaurantSummary>>(`/api/restaurants${query ? `?${query}` : ''}`, { signal })
}

export function getRestaurant(id: string, signal?: AbortSignal): Promise<{ restaurant: RestaurantDetail }> {
  return request<{ restaurant: RestaurantDetail }>(`/api/restaurants/${encodeURIComponent(id)}`, { signal })
}

export interface CustomerAddress {
  id: string
  label: string
  address_line: string
  landmark: string | null
  delivery_instructions: string | null
  latitude: string
  longitude: string
  is_default: boolean
}

export interface CustomerAddressInput {
  label: string
  address_line: string
  landmark?: string
  delivery_instructions?: string
  latitude: string
  longitude: string
  is_default?: boolean
}

export function getCustomerAddresses(signal?: AbortSignal): Promise<{ addresses: CustomerAddress[] }> {
  return request<{ addresses: CustomerAddress[] }>('/api/customer/addresses', { signal })
}

export function createCustomerAddress(data: CustomerAddressInput): Promise<{ message: string; address: CustomerAddress }> {
  return request<{ message: string; address: CustomerAddress }>('/api/customer/addresses', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export type PaymentMethod = 'cod' | 'gcash' | 'card'

export interface CheckoutItemInput {
  menu_item_id: string
  variant_id: string | null
  addon_ids: string[]
  quantity: number
  special_instructions?: string | null
}

export interface CheckoutInput {
  checkout_token: string
  restaurant_id: string
  customer_address_id: string
  payment_method: PaymentMethod
  tip_amount: string
  customer_notes?: string | null
  items: CheckoutItemInput[]
}

export interface CheckoutQuoteItem {
  menu_item_id: string
  name: string
  quantity: number
  variant: { id: string; name: string } | null
  addons: Array<{ id: string; name: string; price: string }>
  unit_total: string
  line_total: string
}

export interface CheckoutQuote {
  restaurant_id: string
  customer_address_id: string
  address_covered: boolean
  payment_method: PaymentMethod
  items: CheckoutQuoteItem[]
  subtotal: string
  delivery_fee: string
  service_fee: string
  discount_amount: string
  tip_amount: string
  total_amount: string
}

export interface CheckoutOrder {
  id: string
  order_number: string
  checkout_token: string
  status: string
  subtotal: string
  delivery_fee: string
  service_fee: string
  discount_amount: string
  tip_amount: string
  total_amount: string
  payment_method: PaymentMethod
  payment_status: string | null
  placed_at: string
}

export type OrderStatus =
  | 'placed'
  | 'accepted'
  | 'preparing'
  | 'ready'
  | 'finding_rider'
  | 'rider_assigned'
  | 'at_restaurant'
  | 'picked_up'
  | 'on_the_way'
  | 'arrived'
  | 'delivered'
  | 'rejected_by_restaurant'
  | 'cancelled_by_customer'
  | 'cancelled_by_restaurant'
  | 'cancelled_no_rider'
  | 'cancelled_by_admin'
  | 'failed_delivery'

export interface CustomerOrderItem {
  id: string
  menu_item_id: string
  name: string
  variant: { id: string; name: string } | null
  quantity: number
  unit_price: string
  special_instructions: string | null
  addons: Array<{ id: string; name: string; price: string }>
}

export interface CustomerOrderDetail {
  id: string
  order_number: string
  status: OrderStatus
  restaurant: { id: string; name: string; logo_path: string | null; address: string }
  item_count: number
  subtotal: string
  delivery_fee: string
  service_fee: string
  discount_amount: string
  tip_amount: string
  total_amount: string
  payment_method: PaymentMethod
  customer_notes: string | null
  estimated_ready_at: string | null
  can_cancel: boolean
  cancellation_reason: string | null
  cancelled_by: string | null
  placed_at: string
  delivered_at: string | null
  rider: { id: string; name: string; vehicle_type: string } | null
  delivery_address: {
    id: string
    label: string
    address_line: string
    landmark: string | null
    delivery_instructions: string | null
    latitude: string
    longitude: string
  }
  items: CustomerOrderItem[]
  status_history: Array<{
    id: string
    status: OrderStatus
    changed_by: string
    note: string | null
    created_at: string
  }>
  payment: { status: string; amount: string; refunded_amount: string } | null
  timestamps: {
    placed_at: string
    accepted_at: string | null
    estimated_ready_at: string | null
    ready_at: string | null
    rider_search_started_at: string | null
    rider_assigned_at: string | null
    rider_arrived_restaurant_at: string | null
    picked_up_at: string | null
    delivered_at: string | null
    updated_at: string | null
  }
}

export interface MercureAuthorization {
  hub_url: string
  subscription_parameter: string
  topics: string[]
}

export function previewCheckout(data: CheckoutInput, signal?: AbortSignal): Promise<{ quote: CheckoutQuote }> {
  return request<{ quote: CheckoutQuote }>('/api/customer/checkout/preview', {
    method: 'POST',
    body: JSON.stringify(data),
    signal,
  })
}

export function placeOrder(data: CheckoutInput): Promise<{ created: boolean; order: CheckoutOrder }> {
  return request<{ created: boolean; order: CheckoutOrder }>('/api/customer/checkout', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export function getCustomerOrder(id: string, signal?: AbortSignal): Promise<{ order: CustomerOrderDetail }> {
  return request<{ order: CustomerOrderDetail }>(`/api/customer/orders/${encodeURIComponent(id)}`, { signal })
}

export function cancelCustomerOrder(id: string, reason: string): Promise<{ order: { id: string; status: OrderStatus; cancellation_reason: string; cancelled_by: string; payment_status: string | null } }> {
  return request<{ order: { id: string; status: OrderStatus; cancellation_reason: string; cancelled_by: string; payment_status: string | null } }>(`/api/customer/orders/${encodeURIComponent(id)}/cancel`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

export function authorizeMercure(): Promise<MercureAuthorization> {
  return request<MercureAuthorization>('/api/mercure-auth', {
    method: 'POST',
    credentials: 'include',
  })
}

export type ConversationType = 'customer_rider' | 'customer_restaurant'

export interface ConversationMessage {
  id: string
  conversation_id: string
  sender: { id: string; name: string }
  body: string
  read_at: string | null
  created_at: string
}

export interface CustomerConversation {
  id: string
  type: ConversationType
  order: { id: string; order_number: string; restaurant_name: string }
  counterpart: {
    type: 'restaurant' | 'rider' | 'customer'
    id: string | null
    name: string | null
    avatar_path: string | null
  }
  is_closed: boolean
  closed_at: string | null
  unread_count: number
  last_message: ConversationMessage | null
  updated_at: string | null
}

export function getCustomerConversations(signal?: AbortSignal): Promise<PaginatedResponse<CustomerConversation>> {
  return request<PaginatedResponse<CustomerConversation>>('/api/customer/conversations?perPage=100', { signal })
}

export function getCustomerConversationMessages(id: string, signal?: AbortSignal): Promise<PaginatedResponse<ConversationMessage> & { conversation: CustomerConversation }> {
  return request<PaginatedResponse<ConversationMessage> & { conversation: CustomerConversation }>(`/api/customer/conversations/${encodeURIComponent(id)}/messages?perPage=100`, { signal })
}

export function sendCustomerConversationMessage(id: string, body: string): Promise<{ message: ConversationMessage }> {
  return request<{ message: ConversationMessage }>(`/api/customer/conversations/${encodeURIComponent(id)}/messages`, {
    method: 'POST',
    body: JSON.stringify({ body }),
  })
}

export function markCustomerConversationRead(id: string): Promise<{ message: string; updated: number; read_at: string }> {
  return request<{ message: string; updated: number; read_at: string }>(`/api/customer/conversations/${encodeURIComponent(id)}/mark-read`, {
    method: 'POST',
  })
}

export function resolveOrderConversation(orderId: string, type: ConversationType): Promise<{ conversation: CustomerConversation }> {
  return request<{ conversation: CustomerConversation }>(`/api/customer/conversations/for-order/${encodeURIComponent(orderId)}`, {
    method: 'POST',
    body: JSON.stringify({ type }),
  })
}

export interface AuthUser {
  id: string
  name: string
  email: string
  phone: string
  role: string
  status: string
  approval_status?: string | null
}

export function getCurrentUser(signal?: AbortSignal): Promise<AuthUser> {
  return request<AuthUser>('/api/me', { signal })
}

export interface LoginResponse {
  token: string
}

export interface RegistrationResponse extends LoginResponse {
  user: AuthUser
}

export interface PendingRegistrationResponse {
  message: string
  user: AuthUser
}

/** Symfony Security JSON login: POST /api/login with login + password. */
export function login(email: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('/api/login', {
    method: 'POST',
    body: JSON.stringify({ login: email, password }),
  })
}

/** Customer registration: POST /api/register. */
export function registerCustomer(data: {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
}): Promise<RegistrationResponse> {
  return request<RegistrationResponse>('/api/register', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export interface RestaurantRegistrationInput {
  owner_name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
  restaurant_name: string
  address: string
  latitude: string
  longitude: string
  cuisine_type: string
  business_permit: File
  food_safety_permit: File
  owner_id: File
}

export function registerRestaurant(data: RestaurantRegistrationInput): Promise<PendingRegistrationResponse> {
  const body = new FormData()
  for (const [key, value] of Object.entries(data)) body.set(key, value)
  return request<PendingRegistrationResponse>('/api/register/restaurant', { method: 'POST', body })
}

export interface RiderRegistrationInput {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
  vehicle_type: 'motorcycle' | 'bicycle' | 'car'
  plate_number: string
  drivers_license: File
  vehicle_registration: File
  valid_id: File
  police_clearance: File
}

export function registerRider(data: RiderRegistrationInput): Promise<PendingRegistrationResponse> {
  const body = new FormData()
  for (const [key, value] of Object.entries(data)) body.set(key, value)
  return request<PendingRegistrationResponse>('/api/register/rider', { method: 'POST', body })
}

/** SymfonyCasts ResetPasswordBundle request endpoint. */
export function forgotPassword(email: string): Promise<{ message: string }> {
  return request<{ message: string }>('/api/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  })
}

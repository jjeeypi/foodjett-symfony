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
  if (options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
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

export interface AuthUser {
  id: string
  name: string
  email: string
  phone: string
  role: string
  status: string
  approval_status: string | null
}

export interface LoginResponse {
  token: string
}

export interface RegistrationResponse extends LoginResponse {
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

/** SymfonyCasts ResetPasswordBundle request endpoint. */
export function forgotPassword(email: string): Promise<{ message: string }> {
  return request<{ message: string }>('/api/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  })
}

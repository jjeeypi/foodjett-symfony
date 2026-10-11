import { createContext } from 'react'
import type { AuthUser } from '../lib/api'

export interface AuthContextValue {
  user: AuthUser | null
  loading: boolean
  completeLogin: (token: string) => Promise<AuthUser>
  logout: () => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

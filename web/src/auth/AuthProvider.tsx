import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { clearToken, getCurrentUser, isAuthenticated, setToken } from '../lib/api'
import type { AuthUser } from '../lib/api'
import { AuthContext } from './auth-context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [hasStoredSession] = useState(() => isAuthenticated())
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(hasStoredSession)

  useEffect(() => {
    if (!hasStoredSession) return

    const controller = new AbortController()
    void getCurrentUser(controller.signal)
      .then((currentUser) => {
        if (!controller.signal.aborted) setUser(currentUser)
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          clearToken()
          setUser(null)
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })

    return () => controller.abort()
  }, [hasStoredSession])

  const completeLogin = useCallback(async (token: string) => {
    setToken(token)
    try {
      const currentUser = await getCurrentUser()
      setUser(currentUser)
      return currentUser
    } catch (error) {
      clearToken()
      setUser(null)
      throw error
    }
  }, [])

  const logout = useCallback(() => {
    clearToken()
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, loading, completeLogin, logout }), [completeLogin, loading, logout, user])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

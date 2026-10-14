import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { FavoritesContext } from './FavoritesContext'

const FAVORITES_STORAGE_KEY = 'foodjett_favorite_restaurants'

function readStoredFavorites(): Set<string> {
  try {
    const stored = window.localStorage.getItem(FAVORITES_STORAGE_KEY)
    if (!stored) return new Set()
    const parsed: unknown = JSON.parse(stored)

    return new Set(Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : [])
  } catch {
    return new Set()
  }
}

export function FavoritesProvider({ children }: { children: ReactNode }) {
  const [favoriteRestaurantIds, setFavoriteRestaurantIds] = useState<Set<string>>(readStoredFavorites)

  useEffect(() => {
    try {
      window.localStorage.setItem(FAVORITES_STORAGE_KEY, JSON.stringify([...favoriteRestaurantIds]))
    } catch {
      // Favorites still work for the current session when browser storage is unavailable.
    }
  }, [favoriteRestaurantIds])

  const value = useMemo(() => ({
    favoriteRestaurantIds,
    isFavorite: (restaurantId: string) => favoriteRestaurantIds.has(restaurantId),
    toggleFavorite(restaurantId: string) {
      setFavoriteRestaurantIds((current) => {
        const next = new Set(current)
        if (next.has(restaurantId)) next.delete(restaurantId)
        else next.add(restaurantId)

        return next
      })
    },
  }), [favoriteRestaurantIds])

  return <FavoritesContext.Provider value={value}>{children}</FavoritesContext.Provider>
}

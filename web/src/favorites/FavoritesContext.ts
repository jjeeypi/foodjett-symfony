import { createContext, useContext } from 'react'

export interface FavoritesContextValue {
  favoriteRestaurantIds: ReadonlySet<string>
  isFavorite: (restaurantId: string) => boolean
  toggleFavorite: (restaurantId: string) => void
}

export const FavoritesContext = createContext<FavoritesContextValue | undefined>(undefined)

export function useFavorites(): FavoritesContextValue {
  const context = useContext(FavoritesContext)
  if (!context) throw new Error('useFavorites must be used inside FavoritesProvider.')

  return context
}

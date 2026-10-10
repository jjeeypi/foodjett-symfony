import { createContext, useContext } from 'react'

export interface CartItem {
  id: string
  restaurantId: string
  name: string
  quantity: number
  unitPrice: number
  thumbnailUrl?: string | null
}

export interface AddCartItem extends Omit<CartItem, 'quantity'> {
  quantity?: number
}

export interface CartContextValue {
  items: CartItem[]
  itemCount: number
  subtotal: number
  isCartOpen: boolean
  addItem: (item: AddCartItem) => void
  updateQuantity: (id: string, quantity: number) => void
  removeItem: (id: string) => void
  clearCart: () => void
  openCart: () => void
  closeCart: () => void
}

export const CartContext = createContext<CartContextValue | undefined>(undefined)

export function useCart(): CartContextValue {
  const context = useContext(CartContext)
  if (!context) throw new Error('useCart must be used inside CartProvider.')

  return context
}

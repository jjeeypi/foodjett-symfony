import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { CartContext } from './CartContext'
import type { AddCartItem, CartItem } from './CartContext'

const CART_STORAGE_KEY = 'foodjett_cart'

function isCartItem(value: unknown): value is CartItem {
  if (typeof value !== 'object' || value === null) return false
  const item = value as Partial<CartItem>

  return typeof item.id === 'string'
    && typeof item.restaurantId === 'string'
    && typeof item.restaurantName === 'string'
    && typeof item.menuItemId === 'string'
    && typeof item.name === 'string'
    && typeof item.quantity === 'number'
    && item.quantity > 0
    && typeof item.unitPrice === 'number'
    && item.unitPrice >= 0
}

function readStoredItems(): CartItem[] {
  try {
    const stored = window.sessionStorage.getItem(CART_STORAGE_KEY)
    if (!stored) return []
    const parsed: unknown = JSON.parse(stored)

    return Array.isArray(parsed) ? parsed.filter(isCartItem) : []
  } catch {
    return []
  }
}

function normalizedItem(item: AddCartItem): CartItem {
  return { ...item, quantity: Math.max(1, Math.floor(item.quantity ?? 1)) }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<CartItem[]>(readStoredItems)
  const [isCartOpen, setIsCartOpen] = useState(false)

  useEffect(() => {
    try {
      window.sessionStorage.setItem(CART_STORAGE_KEY, JSON.stringify(items))
    } catch {
      // In-memory cart state still works if browser storage is unavailable.
    }
  }, [items])

  const value = useMemo(() => ({
    items,
    itemCount: items.reduce((total, item) => total + item.quantity, 0),
    subtotal: items.reduce((total, item) => total + item.unitPrice * item.quantity, 0),
    cartRestaurantId: items[0]?.restaurantId ?? null,
    isCartOpen,
    addItem(item: AddCartItem) {
      if (items.some((entry) => entry.restaurantId !== item.restaurantId)) return 'restaurant_conflict' as const
      const normalized = normalizedItem(item)
      setItems((current) => {
        const existing = current.find((entry) => entry.id === item.id)
        if (!existing) return [...current, normalized]

        return current.map((entry) => entry.id === item.id
          ? { ...entry, quantity: entry.quantity + normalized.quantity }
          : entry)
      })
      return 'added' as const
    },
    replaceCart(item: AddCartItem) {
      setItems([normalizedItem(item)])
    },
    updateQuantity(id: string, quantity: number) {
      const normalized = Math.floor(quantity)
      setItems((current) => normalized <= 0
        ? current.filter((item) => item.id !== id)
        : current.map((item) => item.id === id ? { ...item, quantity: normalized } : item))
    },
    removeItem(id: string) {
      setItems((current) => current.filter((item) => item.id !== id))
    },
    clearCart() {
      setItems([])
    },
    openCart() {
      setIsCartOpen(true)
    },
    closeCart() {
      setIsCartOpen(false)
    },
  }), [isCartOpen, items])

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

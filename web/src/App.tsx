import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { CartProvider } from './cart/CartProvider'
import { CustomerLayout } from './layouts/CustomerLayout'
import { isAuthenticated } from './lib/api'
import { ComingSoonPage } from './pages/ComingSoonPage'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import CheckoutPage from './pages/CheckoutPage'
import OrderConfirmationPage from './pages/OrderConfirmationPage'
import RegisterPage from './pages/RegisterPage'
import RestaurantPage from './pages/RestaurantPage'

function ProtectedRoute({ children }: { children: ReactNode }) {
  return isAuthenticated() ? children : <Navigate to="/login" replace />
}

function GuestRoute({ children }: { children: ReactNode }) {
  return isAuthenticated() ? <Navigate to="/" replace /> : children
}

export default function App() {
  return (
    <BrowserRouter>
      <CartProvider>
        <Routes>
          <Route path="/login" element={<GuestRoute><LoginPage /></GuestRoute>} />
          <Route path="/register" element={<GuestRoute><RegisterPage /></GuestRoute>} />
          <Route path="/forgot-password" element={<GuestRoute><ForgotPasswordPage /></GuestRoute>} />

          <Route element={<ProtectedRoute><CustomerLayout /></ProtectedRoute>}>
            <Route index element={<HomePage />} />
            <Route path="/foods" element={<ComingSoonPage title="Foods" message="Dish browsing will live here. For now, choose an open restaurant from Home." />} />
            <Route path="/messages" element={<ComingSoonPage title="Messages" message="Order conversations will appear here once the messaging screen is connected." />} />
            <Route path="/orders" element={<ComingSoonPage title="Orders" message="Your active and past orders will appear here once the orders screen is connected." />} />
            <Route path="/checkout" element={<CheckoutPage />} />
            <Route path="/orders/:id" element={<OrderConfirmationPage />} />
            <Route path="/restaurants/:id" element={<RestaurantPage />} />
            <Route path="/profile" element={<ComingSoonPage title="Profile" message="Customer profile settings will appear here." />} />
            <Route path="/addresses" element={<ComingSoonPage title="Addresses" message="Saved delivery addresses will appear here." />} />
          </Route>
          <Route path="*" element={<Navigate to={isAuthenticated() ? '/' : '/login'} replace />} />
        </Routes>
      </CartProvider>
    </BrowserRouter>
  )
}

import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthProvider'
import { homeForRole } from './auth/auth-routing'
import { useAuth } from './auth/useAuth'
import { CartProvider } from './cart/CartProvider'
import { CustomerLayout } from './layouts/CustomerLayout'
import { RestaurantLayout } from './layouts/RestaurantLayout'
import { RiderLayout } from './layouts/RiderLayout'
import { AdminLayout } from './layouts/AdminLayout'
import { ComingSoonPage } from './pages/ComingSoonPage'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import MessagesPage from './pages/MessagesPage'
import CheckoutPage from './pages/CheckoutPage'
import OrderTrackingPage from './pages/OrderTrackingPage'
import RegisterPage from './pages/RegisterPage'
import RestaurantRegistrationPage from './pages/RestaurantRegistrationPage'
import RiderRegistrationPage from './pages/RiderRegistrationPage'
import PendingReviewPage from './pages/PendingReviewPage'
import RestaurantPage from './pages/RestaurantPage'
import { RestaurantDashboardPage } from './pages/restaurant/RestaurantDashboardPage'
import { RestaurantComingSoonPage } from './pages/restaurant/RestaurantComingSoonPage'
import { RestaurantMenuPage } from './pages/restaurant/RestaurantMenuPage'
import { RiderAccountPage } from './pages/rider/RiderAccountPage'
import { RiderActivePage } from './pages/rider/RiderActivePage'
import { RiderChatPage } from './pages/rider/RiderChatPage'
import { RiderEarningsPage } from './pages/rider/RiderEarningsPage'
import { RiderPoolPage } from './pages/rider/RiderPoolPage'
import { AdminApprovalsPage } from './pages/admin/AdminApprovalsPage'
import { AdminComingSoonPage } from './pages/admin/AdminComingSoonPage'

function LoadingSession() {
  return (
    <div className="flex min-h-svh items-center justify-center bg-brand-black px-5 text-white">
      <div className="flex items-center gap-3 text-sm text-white/60">
        <span className="h-5 w-5 animate-spin rounded-full border-2 border-white/15 border-t-neon-green" aria-hidden="true" />
        Checking your session…
      </div>
    </div>
  )
}

function ProtectedRoute({ children, role }: { children: ReactNode; role?: 'admin' | 'customer' | 'restaurant' | 'rider' }) {
  const { loading, user } = useAuth()
  if (loading) return <LoadingSession />
  if (!user) return <Navigate to="/login" replace />
  if (role && user.role !== role) return <Navigate to={homeForRole(user.role)} replace />

  return children
}

function GuestRoute({ children }: { children: ReactNode }) {
  const { loading, user } = useAuth()
  if (loading) return <LoadingSession />

  return user ? <Navigate to={homeForRole(user.role)} replace /> : children
}

function RoleHomeRedirect() {
  const { loading, user } = useAuth()
  if (loading) return <LoadingSession />

  return <Navigate to={user ? homeForRole(user.role) : '/login'} replace />
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <CartProvider>
          <Routes>
            <Route path="/login" element={<GuestRoute><LoginPage /></GuestRoute>} />
            <Route path="/register" element={<GuestRoute><RegisterPage /></GuestRoute>} />
            <Route path="/register/restaurant" element={<GuestRoute><RestaurantRegistrationPage /></GuestRoute>} />
            <Route path="/register/rider" element={<GuestRoute><RiderRegistrationPage /></GuestRoute>} />
            <Route path="/register/pending" element={<GuestRoute><PendingReviewPage /></GuestRoute>} />
            <Route path="/forgot-password" element={<GuestRoute><ForgotPasswordPage /></GuestRoute>} />

            <Route element={<ProtectedRoute role="customer"><CustomerLayout /></ProtectedRoute>}>
              <Route index element={<HomePage />} />
              <Route path="/foods" element={<ComingSoonPage title="Foods" message="Dish browsing will live here. For now, choose an open restaurant from Home." />} />
              <Route path="/messages" element={<MessagesPage />} />
              <Route path="/messages/:conversationId" element={<MessagesPage />} />
              <Route path="/orders" element={<ComingSoonPage title="Orders" message="Your active and past orders will appear here once the orders screen is connected." />} />
              <Route path="/checkout" element={<CheckoutPage />} />
              <Route path="/orders/:id" element={<OrderTrackingPage />} />
              <Route path="/restaurants/:id" element={<RestaurantPage />} />
              <Route path="/profile" element={<ComingSoonPage title="Profile" message="Customer profile settings will appear here." />} />
              <Route path="/addresses" element={<ComingSoonPage title="Addresses" message="Saved delivery addresses will appear here." />} />
            </Route>

            <Route path="/restaurant" element={<ProtectedRoute role="restaurant"><RestaurantLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/restaurant/dashboard" replace />} />
              <Route path="dashboard" element={<RestaurantDashboardPage />} />
              <Route path="orders" element={<Navigate to="/restaurant/dashboard" replace />} />
              <Route path="menu" element={<RestaurantMenuPage />} />
              <Route path="earnings" element={<RestaurantComingSoonPage section="Earnings" />} />
              <Route path="profile" element={<RestaurantComingSoonPage section="Profile" />} />
              <Route path="reviews" element={<RestaurantComingSoonPage section="Reviews" />} />
            </Route>

            <Route path="/rider" element={<ProtectedRoute role="rider"><RiderLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/rider/pool" replace />} />
              <Route path="pool" element={<RiderPoolPage />} />
              <Route path="active" element={<RiderActivePage />} />
              <Route path="messages/:conversationId" element={<RiderChatPage />} />
              <Route path="earnings" element={<RiderEarningsPage />} />
              <Route path="account" element={<RiderAccountPage />} />
            </Route>

            <Route path="/admin" element={<ProtectedRoute role="admin"><AdminLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/admin/dashboard" replace />} />
              <Route path="dashboard" element={<AdminApprovalsPage />} />
              <Route path="approvals" element={<AdminApprovalsPage />} />
              <Route path="approvals/:kind/:id" element={<AdminApprovalsPage />} />
              <Route path="orders" element={<AdminComingSoonPage section="Orders" />} />
              <Route path="restaurants" element={<AdminComingSoonPage section="Restaurants" />} />
              <Route path="riders" element={<AdminComingSoonPage section="Riders" />} />
              <Route path="customers" element={<AdminComingSoonPage section="Customers" />} />
              <Route path="payments" element={<AdminComingSoonPage section="Payments" />} />
              <Route path="remittances" element={<AdminComingSoonPage section="Remittances" />} />
              <Route path="vouchers" element={<AdminComingSoonPage section="Vouchers" />} />
              <Route path="reviews" element={<AdminComingSoonPage section="Reviews" />} />
              <Route path="support" element={<AdminComingSoonPage section="Support" />} />
              <Route path="audit-log" element={<AdminComingSoonPage section="Audit Log" />} />
              <Route path="platform-settings" element={<AdminComingSoonPage section="Platform Settings" />} />
            </Route>

            <Route path="/unsupported-role" element={<ProtectedRoute><ComingSoonPage title="Dashboard unavailable" message="This role's web dashboard has not been connected yet." /></ProtectedRoute>} />
            <Route path="*" element={<RoleHomeRedirect />} />
          </Routes>
        </CartProvider>
      </AuthProvider>
    </BrowserRouter>
  )
}

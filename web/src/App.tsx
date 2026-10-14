import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './auth/AuthProvider'
import { homeForRole } from './auth/auth-routing'
import { useAuth } from './auth/useAuth'
import { CartProvider } from './cart/CartProvider'
import { FavoritesProvider } from './favorites/FavoritesProvider'
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
import CustomerOrdersPage from './pages/CustomerOrdersPage'
import RegisterPage from './pages/RegisterPage'
import RestaurantRegistrationPage from './pages/RestaurantRegistrationPage'
import RiderRegistrationPage from './pages/RiderRegistrationPage'
import PendingReviewPage from './pages/PendingReviewPage'
import RestaurantPage from './pages/RestaurantPage'
import { RestaurantDashboardPage } from './pages/restaurant/RestaurantDashboardPage'
import { RestaurantMenuPage } from './pages/restaurant/RestaurantMenuPage'
import { RestaurantEarningsPage } from './pages/restaurant/RestaurantEarningsPage'
import { RestaurantProfilePage } from './pages/restaurant/RestaurantProfilePage'
import { RestaurantReviewsPage } from './pages/restaurant/RestaurantReviewsPage'
import { RiderAccountPage } from './pages/rider/RiderAccountPage'
import { RiderActivePage } from './pages/rider/RiderActivePage'
import { RiderChatPage } from './pages/rider/RiderChatPage'
import { RiderEarningsPage } from './pages/rider/RiderEarningsPage'
import { RiderPoolPage } from './pages/rider/RiderPoolPage'
import { AdminApprovalsPage } from './pages/admin/AdminApprovalsPage'
import { AdminOrdersPage } from './pages/admin/AdminOrdersPage'
import { AdminRestaurantsPage } from './pages/admin/AdminRestaurantsPage'
import { AdminRidersPage } from './pages/admin/AdminRidersPage'
import { AdminPaymentsPage } from './pages/admin/AdminPaymentsPage'
import { AdminRemittancesPage } from './pages/admin/AdminRemittancesPage'
import { AdminVouchersPage } from './pages/admin/AdminVouchersPage'
import { AdminReviewsPage } from './pages/admin/AdminReviewsPage'
import { AdminSupportPage } from './pages/admin/AdminSupportPage'
import { AdminAuditLogPage } from './pages/admin/AdminAuditLogPage'
import { AdminPlatformSettingsPage } from './pages/admin/AdminPlatformSettingsPage'
import { AdminCustomersPage } from './pages/admin/AdminCustomersPage'
import CustomerProfilePage from './pages/CustomerProfilePage'
import CustomerAddressesPage from './pages/CustomerAddressesPage'
import FoodsPage from './pages/FoodsPage'
import FavoritesPage from './pages/FavoritesPage'

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
        <FavoritesProvider>
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
              <Route path="/foods" element={<FoodsPage />} />
              <Route path="/favorites" element={<FavoritesPage />} />
              <Route path="/messages" element={<MessagesPage />} />
              <Route path="/messages/:conversationId" element={<MessagesPage />} />
              <Route path="/orders" element={<CustomerOrdersPage />} />
              <Route path="/checkout" element={<CheckoutPage />} />
              <Route path="/orders/:id" element={<OrderTrackingPage />} />
              <Route path="/restaurants/:id" element={<RestaurantPage />} />
              <Route path="/profile" element={<CustomerProfilePage />} />
              <Route path="/account" element={<Navigate to="/profile" replace />} />
              <Route path="/addresses" element={<CustomerAddressesPage />} />
            </Route>

            <Route path="/restaurant" element={<ProtectedRoute role="restaurant"><RestaurantLayout /></ProtectedRoute>}>
              <Route index element={<Navigate to="/restaurant/dashboard" replace />} />
              <Route path="dashboard" element={<RestaurantDashboardPage />} />
              <Route path="orders" element={<Navigate to="/restaurant/dashboard" replace />} />
              <Route path="menu" element={<RestaurantMenuPage />} />
              <Route path="earnings" element={<RestaurantEarningsPage />} />
              <Route path="payouts" element={<Navigate to="/restaurant/earnings" replace />} />
              <Route path="profile" element={<RestaurantProfilePage />} />
              <Route path="reviews" element={<RestaurantReviewsPage />} />
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
              <Route path="orders" element={<AdminOrdersPage />} />
              <Route path="orders/:id" element={<AdminOrdersPage />} />
              <Route path="restaurants" element={<AdminRestaurantsPage />} />
              <Route path="restaurants/:id" element={<AdminRestaurantsPage />} />
              <Route path="riders" element={<AdminRidersPage />} />
              <Route path="riders/:id" element={<AdminRidersPage />} />
              <Route path="customers" element={<AdminCustomersPage />} />
              <Route path="customers/:id" element={<AdminCustomersPage />} />
              <Route path="payments" element={<AdminPaymentsPage />} />
              <Route path="remittances" element={<AdminRemittancesPage />} />
              <Route path="vouchers" element={<AdminVouchersPage />} />
              <Route path="reviews" element={<AdminReviewsPage />} />
              <Route path="support" element={<AdminSupportPage />} />
              <Route path="audit-log" element={<AdminAuditLogPage />} />
              <Route path="settings" element={<AdminPlatformSettingsPage />} />
              <Route path="platform-settings" element={<Navigate to="/admin/settings" replace />} />
            </Route>

            <Route path="/unsupported-role" element={<ProtectedRoute><ComingSoonPage title="Dashboard unavailable" message="This role's web dashboard has not been connected yet." /></ProtectedRoute>} />
            <Route path="*" element={<RoleHomeRedirect />} />
            </Routes>
          </CartProvider>
        </FavoritesProvider>
      </AuthProvider>
    </BrowserRouter>
  )
}

import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { isAuthenticated } from './lib/api'
import ForgotPasswordPage from './pages/ForgotPasswordPage'
import LoginPage from './pages/LoginPage'
import RegisterPage from './pages/RegisterPage'

function ProtectedRoute({ children }: { children: ReactNode }) {
  return isAuthenticated() ? children : <Navigate to="/login" replace />
}

function GuestRoute({ children }: { children: ReactNode }) {
  return isAuthenticated() ? <Navigate to="/" replace /> : children
}

function Home() {
  return (
    <main className="flex min-h-svh items-center justify-center bg-brand-black px-4 text-white">
      <div className="space-y-3 text-center">
        <h1 className="text-3xl font-bold">
          Welcome to <span className="text-neon-green">Foodjett</span>
        </h1>
        <p className="text-white/55">You are logged in.</p>
      </div>
    </main>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<GuestRoute><LoginPage /></GuestRoute>} />
        <Route path="/register" element={<GuestRoute><RegisterPage /></GuestRoute>} />
        <Route path="/forgot-password" element={<GuestRoute><ForgotPasswordPage /></GuestRoute>} />

        <Route path="/" element={<ProtectedRoute><Home /></ProtectedRoute>} />
        <Route path="*" element={<Navigate to={isAuthenticated() ? '/' : '/login'} replace />} />
      </Routes>
    </BrowserRouter>
  )
}

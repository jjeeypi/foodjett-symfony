import { useEffect, useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { CartDrawer } from '../components/customer/CartDrawer'
import { TopNav } from '../components/customer/TopNav'

export interface CustomerOutletContext {
  searchQuery: string
  setSearchQuery: (query: string) => void
}

export function CustomerLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const [searchQuery, setSearchQuery] = useState(() => new URLSearchParams(location.search).get('q') ?? '')

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      const normalized = searchQuery.trim()
      const urlQuery = new URLSearchParams(location.search).get('q') ?? ''
      if (location.pathname === '/' && normalized === urlQuery) return
      if (location.pathname !== '/' && normalized === '') return

      const params = location.pathname === '/' ? new URLSearchParams(location.search) : new URLSearchParams()
      if (normalized) params.set('q', normalized)
      else params.delete('q')
      const search = params.toString()
      navigate({ pathname: '/', search: search ? `?${search}` : '' }, { replace: location.pathname === '/' })
    }, 300)

    return () => window.clearTimeout(timeout)
  }, [location.pathname, location.search, navigate, searchQuery])

  return (
    <div className="min-h-svh bg-brand-black text-white">
      <TopNav searchQuery={searchQuery} onSearchQueryChange={setSearchQuery} />
      <main className="mx-auto w-full max-w-[90rem] px-4 py-7 sm:px-6 sm:py-9 lg:px-8 lg:py-12">
        <Outlet context={{ searchQuery, setSearchQuery } satisfies CustomerOutletContext} />
      </main>
      <CartDrawer />
    </div>
  )
}

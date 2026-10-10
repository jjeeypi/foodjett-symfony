import { useEffect, useMemo, useState } from 'react'
import { useOutletContext, useSearchParams } from 'react-router-dom'
import { getRestaurants, isApiError } from '../lib/api'
import type { RestaurantSummary } from '../lib/api'
import { RestaurantCard } from '../components/customer/RestaurantCard'
import type { CustomerOutletContext } from '../layouts/CustomerLayout'

function RestaurantSkeleton() {
  return (
    <div className="overflow-hidden rounded-2xl border border-white/8 bg-brand-surface" aria-hidden="true">
      <div className="aspect-[16/9] animate-pulse bg-white/[0.06]" />
      <div className="space-y-3 p-4">
        <div className="h-5 w-2/3 animate-pulse rounded bg-white/[0.08]" />
        <div className="h-4 w-1/3 animate-pulse rounded bg-white/[0.05]" />
        <div className="mt-4 h-px bg-white/8" />
        <div className="h-4 w-1/2 animate-pulse rounded bg-white/[0.05]" />
      </div>
    </div>
  )
}

export default function HomePage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const { searchQuery, setSearchQuery } = useOutletContext<CustomerOutletContext>()
  const [restaurants, setRestaurants] = useState<RestaurantSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const query = searchQuery.trim().toLocaleLowerCase()
  const selectedCuisine = searchParams.get('cuisine') ?? ''

  useEffect(() => {
    const controller = new AbortController()

    getRestaurants({ openNow: true, perPage: 100 }, controller.signal)
      .then((response) => setRestaurants(response.data))
      .catch((requestError: unknown) => {
        if (controller.signal.aborted) return
        setError(isApiError(requestError) ? requestError.message : 'We could not load restaurants right now.')
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })

    return () => controller.abort()
  }, [reloadKey])

  const cuisines = useMemo(() => Array.from(new Set(restaurants
    .map((restaurant) => restaurant.cuisine_type?.trim())
    .filter((cuisine): cuisine is string => Boolean(cuisine))))
    .sort((left, right) => left.localeCompare(right)), [restaurants])

  const filteredRestaurants = useMemo(() => restaurants.filter((restaurant) => {
    const matchesQuery = !query
      || restaurant.name.toLocaleLowerCase().includes(query)
      || (restaurant.cuisine_type ?? '').toLocaleLowerCase().includes(query)
    const matchesCuisine = !selectedCuisine || restaurant.cuisine_type === selectedCuisine

    return matchesQuery && matchesCuisine
  }), [query, restaurants, selectedCuisine])

  function chooseCuisine(cuisine: string) {
    const next = new URLSearchParams(searchParams)
    if (cuisine) next.set('cuisine', cuisine)
    else next.delete('cuisine')
    setSearchParams(next, { replace: true })
  }

  function retry() {
    setLoading(true)
    setError('')
    setReloadKey((key) => key + 1)
  }

  function clearFilters() {
    setSearchQuery('')
    setSearchParams({}, { replace: true })
  }

  return (
    <div>
      <section className="mb-8 sm:mb-10">
        <p className="text-xs font-bold uppercase tracking-[0.2em] text-neon-green">Open near you</p>
        <h1 className="mt-3 max-w-2xl text-3xl font-black tracking-[-0.035em] text-white sm:text-4xl lg:text-5xl">What are you craving today?</h1>
        <p className="mt-3 max-w-2xl text-sm leading-6 text-white/50 sm:text-base">Browse restaurants accepting orders right now and find your next favorite meal.</p>
      </section>

      {!loading && !error && cuisines.length > 0 && (
        <div className="-mx-4 mb-7 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0" aria-label="Filter by cuisine">
          <div className="flex w-max gap-2">
            <button type="button" onClick={() => chooseCuisine('')} className={`min-h-11 rounded-full border px-4 text-sm font-semibold transition ${selectedCuisine === '' ? 'border-neon-green bg-neon-green text-brand-black' : 'border-white/10 bg-white/[0.035] text-white/60 hover:border-neon-green/35 hover:text-white'}`}>All cuisines</button>
            {cuisines.map((cuisine) => (
              <button key={cuisine} type="button" onClick={() => chooseCuisine(cuisine)} className={`min-h-11 rounded-full border px-4 text-sm font-semibold transition ${selectedCuisine === cuisine ? 'border-neon-green bg-neon-green text-brand-black' : 'border-white/10 bg-white/[0.035] text-white/60 hover:border-neon-green/35 hover:text-white'}`}>{cuisine}</button>
            ))}
          </div>
        </div>
      )}

      {loading && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" aria-label="Loading restaurants" aria-busy="true">
          {Array.from({ length: 8 }, (_, index) => <RestaurantSkeleton key={index} />)}
        </div>
      )}

      {!loading && error && (
        <div role="alert" className="rounded-2xl border border-red-400/25 bg-red-500/[0.07] px-6 py-10 text-center">
          <h2 className="text-lg font-bold text-white">Restaurants could not be loaded</h2>
          <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-white/55">{error}</p>
          <button type="button" onClick={retry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-bold text-brand-black transition hover:bg-[#55ff35] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-black">Try again</button>
        </div>
      )}

      {!loading && !error && filteredRestaurants.length === 0 && (
        <div className="rounded-2xl border border-white/10 bg-brand-surface px-6 py-14 text-center">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full border border-neon-green/20 bg-neon-green/[0.06] text-2xl">🍽</div>
          <h2 className="text-lg font-bold text-white">{query || selectedCuisine ? 'No matching restaurants' : 'No restaurants open right now'}</h2>
          <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-white/50">{query || selectedCuisine ? 'Try another restaurant name or cuisine.' : 'Check back soon—more kitchens may open later today.'}</p>
          {(query || selectedCuisine) && <button type="button" onClick={clearFilters} className="mt-5 min-h-11 rounded-xl border border-neon-green px-5 text-sm font-semibold text-neon-green transition hover:bg-neon-green hover:text-brand-black">Clear filters</button>}
        </div>
      )}

      {!loading && !error && filteredRestaurants.length > 0 && (
        <section aria-labelledby="restaurants-heading">
          <div className="mb-4 flex items-end justify-between gap-4">
            <div>
              <h2 id="restaurants-heading" className="text-xl font-bold text-white">Restaurants</h2>
              <p className="mt-1 text-sm text-white/40">{filteredRestaurants.length} open now</p>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {filteredRestaurants.map((restaurant) => <RestaurantCard key={restaurant.id} restaurant={restaurant} />)}
          </div>
        </section>
      )}
    </div>
  )
}

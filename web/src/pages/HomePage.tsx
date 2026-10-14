import { useEffect, useMemo, useState } from 'react'
import { useOutletContext, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { RestaurantCard } from '../components/customer/RestaurantCard'
import { ArrowIcon, BikeIcon, BoltIcon, ClockIcon, PinIcon, SearchIcon, StarIcon } from '../components/customer/Icons'
import type { CustomerOutletContext } from '../layouts/CustomerLayout'
import { getRestaurants, isApiError } from '../lib/api'
import type { RestaurantSummary } from '../lib/api'

const heroImage = 'https://images.unsplash.com/photo-1619810816144-223f5b027aea?auto=format&fit=crop&w=1200&q=85'

function CuisineGlyph({ cuisine }: { cuisine: string }) {
  const normalized = cuisine.toLowerCase()
  const isAll = normalized === 'all'
  const isPizza = normalized.includes('pizza') || normalized.includes('italian')
  const isHealthy = normalized.includes('healthy') || normalized.includes('vegetarian')
  const isDrink = normalized.includes('drink') || normalized.includes('beverage') || normalized.includes('coffee')
  const isDessert = normalized.includes('dessert') || normalized.includes('bakery')
  const isBurger = normalized.includes('burger') || normalized.includes('fast food')

  return (
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {isAll ? <><rect x="8" y="8" width="12" height="12" rx="4" /><rect x="28" y="8" width="12" height="12" rx="4" /><rect x="8" y="28" width="12" height="12" rx="4" /><rect x="28" y="28" width="12" height="12" rx="4" /></>
        : isPizza ? <><path d="m10 7 30 7-25 28Z" /><path d="m10 7 30 7-3 5-25-6Z" /><circle cx="20" cy="21" r="3" /><circle cx="27" cy="24" r="2" /><circle cx="17" cy="32" r="2" /></>
          : isHealthy ? <><path d="M39 9C11 5 5 27 18 35S43 29 39 9Z" /><path d="m10 41 22-22m-9 9v-8m0 8h9" /></>
            : isDrink ? <><path d="m13 18 3 24h17l3-24Z" /><path d="M11 17h27M25 17l3-11h8M18 26h14" /></>
              : isDessert ? <><path d="m15 25 9 19 10-19Z" /><path d="M10 24c-3-6 2-11 6-11-1-12 18-12 17 0 6 0 9 7 5 11Z" /></>
                : isBurger ? <><path d="M8 22c0-20 32-20 32 0Z" /><path d="M7 27h34M8 32l8 3 8-3 8 3 8-3" /><path d="M9 37h30v3H9Z" /></>
                  : <><path d="M7 25h34c-2 15-31 15-34 0Z" /><path d="M14 24V13m6 11V10m6 14V13M11 9l28-5M12 14l28-5" /></>}
    </svg>
  )
}

function RestaurantSkeleton() {
  return <div className="fj-skeleton" aria-hidden="true"><div className="fj-skeleton-media" /><div className="fj-skeleton-copy" /></div>
}

type SortOption = 'recommended' | 'rating' | 'nearest'

export default function HomePage() {
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const { searchQuery, setSearchQuery } = useOutletContext<CustomerOutletContext>()
  const [restaurants, setRestaurants] = useState<RestaurantSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [sort, setSort] = useState<SortOption>('recommended')
  const query = searchQuery.trim().toLocaleLowerCase()
  const selectedCuisine = searchParams.get('cuisine') ?? ''
  const firstName = user?.name.trim().split(/\s+/)[0] ?? 'neighbor'

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

  const filteredRestaurants = useMemo(() => {
    const filtered = restaurants.filter((restaurant) => {
      const matchesQuery = !query
        || restaurant.name.toLocaleLowerCase().includes(query)
        || (restaurant.cuisine_type ?? '').toLocaleLowerCase().includes(query)
      const matchesCuisine = !selectedCuisine || restaurant.cuisine_type === selectedCuisine

      return matchesQuery && matchesCuisine
    })

    return filtered.sort((left, right) => {
      if (sort === 'rating') return right.rating - left.rating
      if (sort === 'nearest') return (left.distance_km ?? Number.POSITIVE_INFINITY) - (right.distance_km ?? Number.POSITIVE_INFINITY)
      return 0
    })
  }, [query, restaurants, selectedCuisine, sort])

  function chooseCuisine(cuisine: string) {
    const next = new URLSearchParams(searchParams)
    if (cuisine) next.set('cuisine', cuisine)
    else next.delete('cuisine')
    setSearchParams(next, { replace: true })
  }

  function clearFilters() {
    setSearchQuery('')
    setSort('recommended')
    setSearchParams({}, { replace: true })
  }

  return (
    <div>
      <section className="fj-welcome-row">
        <div>
          <div className="fj-eyebrow">A LITTLE HUNGRY? YOU&apos;RE IN THE RIGHT PLACE.</div>
          <h1>Good food. <span>Good mood.</span></h1>
          <p>Hey {firstName}, your neighborhood favorites are ready when you are.</p>
        </div>
        <div className="fj-local-note"><PinIcon width="15" height="15" /> Made for your neighborhood</div>
      </section>

      <section className="fj-hero">
        <div className="fj-hero-copy">
          <span className="fj-hero-badge"><BoltIcon width="14" height="14" /> BIG FLAVOR. EASY ORDERING.</span>
          <h2>Your cravings,<br />delivered fresh.</h2>
          <p>Discover something delicious from a local kitchen and have it brought right to your door.</p>
          <button type="button" className="fj-hero-cta" onClick={() => document.getElementById('restaurants')?.scrollIntoView({ behavior: 'smooth' })}>
            Find your next bite <ArrowIcon width="18" height="18" />
          </button>
          <small>Browse kitchens accepting orders right now.</small>
        </div>
        <div className="fj-hero-media"><img src={heroImage} alt="Fresh cheeseburger with golden fries" /></div>
        <div className="fj-hero-sticker"><BikeIcon width="25" height="25" /><strong>Fresh to<br />your doorstep.</strong><span>That&apos;s the Foodjett way.</span></div>
      </section>

      {!loading && !error && cuisines.length > 0 && (
        <section className="fj-section" aria-labelledby="cravings-heading">
          <div className="fj-section-heading"><div><h2 id="cravings-heading">What&apos;s your craving?</h2><p>Something for every mood</p></div></div>
          <div className="fj-cuisine-rail" aria-label="Filter restaurants by cuisine">
            {['', ...cuisines].map((cuisine) => {
              const label = cuisine || 'All'
              return (
                <button key={label} type="button" className={`fj-cuisine ${selectedCuisine === cuisine ? 'selected' : ''}`} onClick={() => chooseCuisine(cuisine)} aria-pressed={selectedCuisine === cuisine}>
                  <span className="fj-cuisine-icon"><CuisineGlyph cuisine={label} /></span>
                  <span>{label}</span>
                </button>
              )
            })}
          </div>
        </section>
      )}

      <section id="restaurants" className="fj-section" aria-labelledby="restaurants-heading">
        <div className="fj-section-heading">
          <div><h2 id="restaurants-heading">Good eats near you</h2><p>Places ready to make your day a little tastier</p></div>
          <label className="fj-search">
            <SearchIcon width="17" height="17" />
            <span className="sr-only">Search restaurants or cuisines</span>
            <input type="search" value={searchQuery} onChange={(event) => setSearchQuery(event.target.value)} placeholder="Search restaurants or cuisines" />
          </label>
        </div>

        <div className="fj-quick-filters">
          <button type="button" className={`fj-filter-chip ${sort === 'recommended' ? 'current' : ''}`} onClick={() => setSort('recommended')}>For you</button>
          <button type="button" className={`fj-filter-chip ${sort === 'rating' ? 'current' : ''}`} onClick={() => setSort('rating')}><StarIcon width="14" height="14" /> Top rated</button>
          <button type="button" className={`fj-filter-chip ${sort === 'nearest' ? 'current' : ''}`} onClick={() => setSort('nearest')}><ClockIcon width="14" height="14" /> Nearest first</button>
          {!loading && !error && <span className="fj-result-count">{filteredRestaurants.length} open now</span>}
        </div>

        {loading && <div className="fj-restaurant-grid" aria-label="Loading restaurants" aria-busy="true">{Array.from({ length: 6 }, (_, index) => <RestaurantSkeleton key={index} />)}</div>}

        {!loading && error && (
          <div className="fj-empty" role="alert">
            <SearchIcon width="34" height="34" />
            <h2>Restaurants could not be loaded</h2>
            <p>{error}</p>
            <button type="button" onClick={() => { setLoading(true); setError(''); setReloadKey((key) => key + 1) }}>Try again</button>
          </div>
        )}

        {!loading && !error && filteredRestaurants.length === 0 && (
          <div className="fj-empty">
            <SearchIcon width="34" height="34" />
            <h2>{query || selectedCuisine ? 'No matching restaurants' : 'No restaurants open right now'}</h2>
            <p>{query || selectedCuisine ? 'Try another restaurant name or cuisine.' : 'Check back soon. More kitchens may open later today.'}</p>
            {(query || selectedCuisine) && <button type="button" onClick={clearFilters}>Clear filters</button>}
          </div>
        )}

        {!loading && !error && filteredRestaurants.length > 0 && (
          <div className="fj-restaurant-grid">
            {filteredRestaurants.map((restaurant) => <RestaurantCard key={restaurant.id} restaurant={restaurant} />)}
          </div>
        )}
      </section>
    </div>
  )
}

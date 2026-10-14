import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { HeartIcon } from '../components/customer/Icons'
import { RestaurantCard } from '../components/customer/RestaurantCard'
import { useFavorites } from '../favorites/FavoritesContext'
import { getRestaurants, isApiError } from '../lib/api'
import type { RestaurantSummary } from '../lib/api'

function FavoriteSkeleton() {
  return <div className="fj-skeleton" aria-hidden="true"><div className="fj-skeleton-media" /><div className="fj-skeleton-copy" /></div>
}

export default function FavoritesPage() {
  const { favoriteRestaurantIds } = useFavorites()
  const [restaurants, setRestaurants] = useState<RestaurantSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    const controller = new AbortController()

    void getRestaurants({ perPage: 100 }, controller.signal)
      .then((response) => setRestaurants(response.data))
      .catch((requestError: unknown) => {
        if (controller.signal.aborted) return
        setError(isApiError(requestError) ? requestError.message : 'We could not load your saved restaurants right now.')
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })

    return () => controller.abort()
  }, [reloadKey])

  const favorites = useMemo(
    () => restaurants.filter((restaurant) => favoriteRestaurantIds.has(restaurant.id)),
    [favoriteRestaurantIds, restaurants],
  )

  return (
    <div>
      <section className="fj-welcome-row fj-favorites-welcome">
        <div>
          <div className="fj-eyebrow">YOUR FOODJETT FAVORITES</div>
          <h1>Your tried-and-loved <span>favorites.</span></h1>
          <p>The neighborhood places you love, all in one spot.</p>
        </div>
      </section>

      <section className="fj-section" aria-labelledby="favorites-heading">
        <div className="fj-section-heading">
          <div>
            <h2 id="favorites-heading">Saved restaurants</h2>
            <p>{loading ? 'Gathering your favorites' : `${favorites.length} saved place${favorites.length === 1 ? '' : 's'}`}</p>
          </div>
        </div>

        {loading && (
          <div className="fj-restaurant-grid fj-favorites-grid" aria-label="Loading saved restaurants" aria-busy="true">
            {Array.from({ length: 3 }, (_, index) => <FavoriteSkeleton key={index} />)}
          </div>
        )}

        {!loading && error && (
          <div className="fj-empty fj-favorites-empty" role="alert">
            <HeartIcon width="34" height="34" />
            <h2>Favorites could not be loaded</h2>
            <p>{error}</p>
            <button type="button" onClick={() => { setLoading(true); setError(''); setReloadKey((key) => key + 1) }}>Try again</button>
          </div>
        )}

        {!loading && !error && favorites.length === 0 && (
          <div className="fj-empty fj-favorites-empty">
            <HeartIcon width="34" height="34" />
            <h2>Your next favorite is waiting</h2>
            <p>Tap the heart on any restaurant to keep it close for next time.</p>
            <Link to="/">Explore restaurants</Link>
          </div>
        )}

        {!loading && !error && favorites.length > 0 && (
          <div className="fj-restaurant-grid fj-favorites-grid">
            {favorites.map((restaurant) => <RestaurantCard key={restaurant.id} restaurant={restaurant} />)}
          </div>
        )}
      </section>
    </div>
  )
}

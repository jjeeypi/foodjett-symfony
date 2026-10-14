import { Link } from 'react-router-dom'
import { useFavorites } from '../../favorites/FavoritesContext'
import type { RestaurantSummary } from '../../lib/api'
import { apiAssetUrl } from '../../lib/api'
import { BikeIcon, ClockIcon, HeartIcon, StarIcon } from './Icons'

function estimatedMinutes(distance: number | null) {
  if (distance === null) return '20–35 min'
  const minimum = Math.max(15, Math.round(15 + distance * 2))
  return `${minimum}–${minimum + 10} min`
}

export function RestaurantCard({ restaurant }: { restaurant: RestaurantSummary }) {
  const imageUrl = apiAssetUrl(restaurant.logo_path)
  const { isFavorite, toggleFavorite } = useFavorites()
  const saved = isFavorite(restaurant.id)

  return (
    <article className="fj-restaurant-card">
      <div className="fj-card-photo">
        <Link to={`/restaurants/${restaurant.id}`} aria-label={`View ${restaurant.name}`} className="fj-card-photo-link">
          {imageUrl
            ? <img src={imageUrl} alt={`${restaurant.name} food and storefront`} loading="lazy" />
            : <span className="fj-card-fallback">{restaurant.name.charAt(0).toUpperCase()}</span>}
          <span className={`fj-open-tag${restaurant.is_open_now ? '' : ' closed'}`}><i /> {restaurant.is_open_now ? 'OPEN NOW' : 'CLOSED'}</span>
        </Link>
        <button
          type="button"
          className={`fj-favorite-button${saved ? ' saved' : ''}`}
          aria-label={saved ? `Remove ${restaurant.name} from favorites` : `Save ${restaurant.name} to favorites`}
          aria-pressed={saved}
          onClick={() => toggleFavorite(restaurant.id)}
        >
          <HeartIcon width="17" height="17" />
        </button>
      </div>
      <Link to={`/restaurants/${restaurant.id}`} aria-label={`View ${restaurant.name}`}>
        <div className="fj-card-body">
          <div className="fj-card-title">
            <h3>{restaurant.name}</h3>
            <span className="fj-card-rating"><StarIcon /><strong>{restaurant.review_count > 0 ? restaurant.rating.toFixed(1) : 'New'}</strong></span>
          </div>
          <p>{restaurant.cuisine_type || 'Local favorites'}{restaurant.review_count > 0 ? ` · ${restaurant.review_count} review${restaurant.review_count === 1 ? '' : 's'}` : ''}</p>
          <div className="fj-card-meta">
            <span><ClockIcon width="14" height="14" /> {estimatedMinutes(restaurant.distance_km)}</span>
            <span><BikeIcon width="15" height="15" /> {restaurant.distance_km === null ? 'Nearby' : `${restaurant.distance_km.toFixed(1)} km away`}</span>
          </div>
        </div>
      </Link>
    </article>
  )
}

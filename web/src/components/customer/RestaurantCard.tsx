import { Link } from 'react-router-dom'
import type { RestaurantSummary } from '../../lib/api'
import { apiAssetUrl } from '../../lib/api'
import { ChevronIcon } from './Icons'

export function RestaurantCard({ restaurant }: { restaurant: RestaurantSummary }) {
  const imageUrl = apiAssetUrl(restaurant.logo_path)

  return (
    <Link to={`/restaurants/${restaurant.id}`} className="group overflow-hidden rounded-2xl border border-white/9 bg-brand-surface transition duration-200 hover:-translate-y-1 hover:border-neon-green/35 hover:shadow-[0_16px_45px_rgba(0,0,0,0.35)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green">
      <div className="relative flex aspect-[16/9] items-center justify-center overflow-hidden bg-[radial-gradient(circle_at_30%_20%,rgba(57,255,20,0.14),transparent_55%),linear-gradient(145deg,#191919,#0d0d0d)]">
        {imageUrl ? (
          <img src={imageUrl} alt={`${restaurant.name} logo`} className="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]" />
        ) : (
          <span className="flex h-20 w-20 items-center justify-center rounded-2xl border border-neon-green/20 bg-neon-green/[0.07] text-3xl font-black text-neon-green">{restaurant.name.charAt(0).toUpperCase()}</span>
        )}
        <span className="absolute left-3 top-3 rounded-full border border-neon-green/20 bg-brand-black/85 px-2.5 py-1 text-[11px] font-bold text-neon-green backdrop-blur">Open now</span>
      </div>
      <div className="p-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="truncate text-base font-bold text-white transition group-hover:text-neon-green">{restaurant.name}</h2>
            <p className="mt-1 truncate text-sm text-white/45">{restaurant.cuisine_type || 'Local favorites'}</p>
          </div>
          <ChevronIcon className="mt-0.5 h-5 w-5 shrink-0 text-white/25 transition group-hover:translate-x-0.5 group-hover:text-neon-green" />
        </div>
        <div className="mt-4 flex items-center gap-3 border-t border-white/8 pt-3 text-xs text-white/50">
          <span className="font-semibold text-white/75"><span className="mr-1 text-neon-green">★</span>{restaurant.review_count > 0 ? restaurant.rating.toFixed(1) : 'New'}</span>
          {restaurant.review_count > 0 && <span>{restaurant.review_count} review{restaurant.review_count === 1 ? '' : 's'}</span>}
          {restaurant.distance_km !== null && <span className="ml-auto">{restaurant.distance_km.toFixed(1)} km</span>}
        </div>
      </div>
    </Link>
  )
}

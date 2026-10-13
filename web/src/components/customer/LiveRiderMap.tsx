import { useEffect, useMemo } from 'react'
import L, { type LatLngExpression } from 'leaflet'
import { MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap } from 'react-leaflet'

interface LiveRiderMapProps {
  riderLatitude: string | null
  riderLongitude: string | null
  destinationLatitude: string
  destinationLongitude: string
  lastLocationAt: string | null
}

const riderIcon = L.divIcon({
  className: 'foodjett-map-marker',
  html: '<span class="foodjett-map-marker__bubble foodjett-map-marker__bubble--rider" aria-hidden="true">&#128757;</span>',
  iconAnchor: [22, 22],
  iconSize: [44, 44],
})

const destinationIcon = L.divIcon({
  className: 'foodjett-map-marker',
  html: '<span class="foodjett-map-marker__bubble foodjett-map-marker__bubble--destination" aria-hidden="true">&#8962;</span>',
  iconAnchor: [20, 40],
  iconSize: [40, 40],
})

function ViewportController({ rider, destination }: { rider: LatLngExpression; destination: LatLngExpression }) {
  const map = useMap()

  useEffect(() => {
    const bounds = L.latLngBounds([rider, destination])
    if (!map.getBounds().contains(bounds)) {
      map.flyToBounds(bounds, { animate: true, duration: 0.8, maxZoom: 16, padding: [42, 42] })
    }
  }, [destination, map, rider])

  return null
}

function coordinate(value: string | null | undefined): number | null {
  if (value === null || value === undefined || value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

export function LiveRiderMap({
  riderLatitude,
  riderLongitude,
  destinationLatitude,
  destinationLongitude,
  lastLocationAt,
}: LiveRiderMapProps) {
  const riderLat = coordinate(riderLatitude)
  const riderLng = coordinate(riderLongitude)
  const destinationLat = coordinate(destinationLatitude)
  const destinationLng = coordinate(destinationLongitude)
  const riderPosition = useMemo<LatLngExpression | null>(
    () => riderLat === null || riderLng === null ? null : [riderLat, riderLng],
    [riderLat, riderLng],
  )
  const destinationPosition = useMemo<LatLngExpression | null>(
    () => destinationLat === null || destinationLng === null ? null : [destinationLat, destinationLng],
    [destinationLat, destinationLng],
  )

  if (!destinationPosition) {
    return (
      <section className="rounded-3xl border border-white/10 bg-brand-surface p-5 sm:p-6">
        <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Live rider map</p>
        <p className="mt-3 text-sm leading-6 text-white/50">The delivery address does not have map coordinates yet.</p>
      </section>
    )
  }

  if (!riderPosition) {
    return (
      <section className="overflow-hidden rounded-3xl border border-white/10 bg-brand-surface">
        <div className="p-5 sm:p-6">
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Live rider map</p>
          <h2 className="mt-1 text-lg font-black text-white">Waiting for the rider's first GPS update</h2>
          <p className="mt-2 text-sm leading-6 text-white/50">The map will appear automatically as soon as the rider's phone sends a location.</p>
        </div>
        <div className="flex h-64 items-center justify-center border-t border-white/8 bg-[radial-gradient(circle_at_center,rgba(57,255,20,0.09),transparent_55%),#0d0d0d] sm:h-72">
          <span className="relative flex h-16 w-16 items-center justify-center rounded-full border border-neon-green/25 bg-neon-green/8 text-2xl">
            <span className="absolute inset-0 animate-ping rounded-full border border-neon-green/20" />
            &#128757;
          </span>
        </div>
      </section>
    )
  }

  const center: LatLngExpression = [
    (riderLat! + destinationLat!) / 2,
    (riderLng! + destinationLng!) / 2,
  ]

  return (
    <section className="overflow-hidden rounded-3xl border border-neon-green/20 bg-brand-surface shadow-[0_20px_60px_rgba(0,0,0,0.22)]">
      <div className="flex flex-col gap-2 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.16em] text-neon-green">Live rider map</p>
          <h2 className="mt-1 text-lg font-black text-white">Your delivery is on the move</h2>
        </div>
        <p className="text-xs text-white/40">
          {lastLocationAt ? `GPS updated ${new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(new Date(lastLocationAt))}` : 'Live GPS'}
        </p>
      </div>
      <div className="relative h-72 border-t border-white/8 sm:h-80 lg:h-96">
        <MapContainer center={center} zoom={14} scrollWheelZoom={false} className="h-full w-full" aria-label="Live map showing the rider and delivery address">
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          <Marker position={riderPosition} icon={riderIcon} zIndexOffset={1000}>
            <Tooltip direction="top" offset={[0, -18]}>Your rider</Tooltip>
          </Marker>
          <Marker position={destinationPosition} icon={destinationIcon}>
            <Tooltip direction="top" offset={[0, -28]}>Delivery address</Tooltip>
          </Marker>
          <Polyline positions={[riderPosition, destinationPosition]} pathOptions={{ color: '#39ff14', dashArray: '8 10', opacity: 0.72, weight: 3 }} />
          <ViewportController rider={riderPosition} destination={destinationPosition} />
        </MapContainer>
        <div className="pointer-events-none absolute bottom-3 left-3 z-[500] rounded-xl border border-white/10 bg-black/75 px-3 py-2 text-[11px] font-semibold text-white/65 backdrop-blur">
          Live position is approximate
        </div>
      </div>
    </section>
  )
}

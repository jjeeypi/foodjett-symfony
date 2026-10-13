export interface BrowserLocation {
  latitude: number
  longitude: number
}

export function getBrowserLocation(): Promise<BrowserLocation | undefined> {
  if (!navigator.geolocation) return Promise.resolve(undefined)

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => resolve({ latitude: coords.latitude, longitude: coords.longitude }),
      () => resolve(undefined),
      { enableHighAccuracy: true, timeout: 10_000, maximumAge: 30_000 },
    )
  })
}

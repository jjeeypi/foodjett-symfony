import { useEffect, useMemo, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { AdminDocumentCard } from '../../components/admin/AdminDocumentCard'
import { NeonInput } from '../../components/NeonInput'
import type { RestaurantOutletContext } from '../../layouts/RestaurantLayout'
import {
  getRestaurantDocuments,
  getRestaurantOperatingHours,
  isApiError,
  replaceRestaurantDocument,
  updateRestaurantOperatingHours,
  updateRestaurantProfile,
} from '../../lib/api'
import type { RestaurantDocument, RestaurantOperatingHour, RestaurantProfileInput } from '../../lib/api'

const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
type HourDraft = RestaurantOperatingHour & { enabled: boolean }

export function RestaurantProfilePage() {
  const { restaurant, updateRestaurant } = useOutletContext<RestaurantOutletContext>()
  const [profile, setProfile] = useState<RestaurantProfileInput>(() => profileFields(restaurant))
  const [hours, setHours] = useState<HourDraft[]>(() => blankHours())
  const [documents, setDocuments] = useState<RestaurantDocument[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [profileBusy, setProfileBusy] = useState(false)
  const [hoursBusy, setHoursBusy] = useState(false)
  const [documentBusy, setDocumentBusy] = useState<string | null>(null)
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void Promise.all([
        getRestaurantOperatingHours(controller.signal),
        getRestaurantDocuments(controller.signal),
      ]).then(([hoursResponse, documentsResponse]) => {
        setHours(mergeHours(hoursResponse.hours))
        setDocuments(documentsResponse.documents)
      }).catch((requestError: unknown) => {
        if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load store settings.')
      }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [retry])

  function updateField<K extends keyof RestaurantProfileInput>(field: K, value: RestaurantProfileInput[K]) {
    setProfile((current) => ({ ...current, [field]: value }))
    setError(''); setNotice('')
  }

  async function saveProfile(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setProfileBusy(true); setError(''); setNotice('')
    try {
      const response = await updateRestaurantProfile(profile)
      setProfile(profileFields(response.restaurant))
      updateRestaurant(response.restaurant)
      setNotice('Business profile saved.')
    } catch (requestError) { setError(apiMessage(requestError, 'The profile could not be saved.')) } finally { setProfileBusy(false) }
  }

  async function saveHours() {
    setHoursBusy(true); setError(''); setNotice('')
    try {
      const response = await updateRestaurantOperatingHours(hours.filter((hour) => hour.enabled).map(({ day_of_week, opens_at, closes_at }) => ({ day_of_week, opens_at, closes_at })))
      setHours(mergeHours(response.hours))
      setNotice('Operating hours saved. Your open-now visibility now follows this schedule while the store toggle is open.')
    } catch (requestError) { setError(apiMessage(requestError, 'Operating hours could not be saved.')) } finally { setHoursBusy(false) }
  }

  async function replaceDocument(document: RestaurantDocument, file: File | undefined) {
    if (!file) return
    setDocumentBusy(document.id); setError(''); setNotice('')
    try {
      const response = await replaceRestaurantDocument(document.id, file)
      setDocuments((current) => current.map((item) => item.id === document.id ? response.document : item))
      setNotice(`${documentLabel(document.type)} resubmitted for admin review.`)
    } catch (requestError) { setError(apiMessage(requestError, 'The document could not be uploaded.')) } finally { setDocumentBusy(null) }
  }

  const activeDays = useMemo(() => hours.filter((hour) => hour.enabled).length, [hours])

  if (loading) return <PageSkeleton />
  if (error && documents.length === 0) return <ErrorState message={error} onRetry={() => setRetry((value) => value + 1)} />

  return <div>
    <PageHeading title="Store profile" description="Keep your public business details, weekly schedule, and approval documents current." />
    {notice && <p role="status" className="mt-5 rounded-xl border border-neon-green/20 bg-neon-green/[0.06] px-4 py-3 text-sm font-semibold text-neon-green">{notice}</p>}
    {error && <p role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-4 py-3 text-sm text-red-200">{error}</p>}

    <form onSubmit={saveProfile} className="mt-6 rounded-2xl border border-white/10 bg-brand-surface p-5 sm:p-6">
      <SectionHeading title="Business information" description="Shown to customers while they browse your store." />
      <div className="mt-5 grid gap-4 sm:grid-cols-2">
        <NeonInput id="restaurant-name" label="Business name" value={profile.name} onChange={(event) => updateField('name', event.target.value)} disabled={profileBusy} />
        <NeonInput id="restaurant-cuisine" label="Cuisine type" value={profile.cuisine_type} onChange={(event) => updateField('cuisine_type', event.target.value)} disabled={profileBusy} />
        <NeonInput id="restaurant-phone" label="Contact phone" type="tel" value={profile.phone} onChange={(event) => updateField('phone', event.target.value)} disabled={profileBusy} />
        <NeonInput id="restaurant-minimum" label="Minimum order (PHP)" inputMode="decimal" value={profile.min_order_amount} onChange={(event) => updateField('min_order_amount', event.target.value)} disabled={profileBusy} />
        <div className="sm:col-span-2"><NeonInput id="restaurant-address" label="Business address" value={profile.address} onChange={(event) => updateField('address', event.target.value)} disabled={profileBusy} /></div>
        <NeonInput id="restaurant-latitude" label="Latitude" inputMode="decimal" value={profile.latitude} onChange={(event) => updateField('latitude', event.target.value)} disabled={profileBusy} />
        <NeonInput id="restaurant-longitude" label="Longitude" inputMode="decimal" value={profile.longitude} onChange={(event) => updateField('longitude', event.target.value)} disabled={profileBusy} />
        <NeonInput id="restaurant-prep" label="Default prep time (minutes)" type="number" min="1" max="240" value={String(profile.default_prep_time_minutes)} onChange={(event) => updateField('default_prep_time_minutes', Number(event.target.value))} disabled={profileBusy} />
        <label className="sm:col-span-2"><span className="mb-2 block text-sm font-semibold text-white/70">Description</span><textarea rows={4} value={profile.description} onChange={(event) => updateField('description', event.target.value)} disabled={profileBusy} className="w-full rounded-xl border border-white/10 bg-brand-black px-4 py-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-neon-green disabled:opacity-50" /></label>
      </div>
      <div className="mt-5 flex justify-end"><button disabled={profileBusy} className="min-h-11 rounded-xl bg-neon-green px-6 text-sm font-black text-brand-black disabled:opacity-50">{profileBusy ? 'Saving…' : 'Save profile'}</button></div>
    </form>

    <section className="mt-6 rounded-2xl border border-white/10 bg-brand-surface p-5 sm:p-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><SectionHeading title="Operating hours" description="The store must also be manually set to Open. With no schedule, the manual toggle remains the source of truth." /><span className="rounded-full border border-white/10 px-3 py-1 text-xs font-bold text-white/45">{activeDays} days configured</span></div>
      <div className="mt-5 divide-y divide-white/[0.07]">{hours.map((hour, index) => <div key={hour.day_of_week} className="grid gap-3 py-4 first:pt-0 last:pb-0 sm:grid-cols-[9rem_1fr_1fr] sm:items-end"><label className="flex min-h-11 items-center gap-3 text-sm font-bold text-white"><input type="checkbox" checked={hour.enabled} onChange={(event) => setHours((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, enabled: event.target.checked } : row))} className="h-5 w-5 accent-[#39ff14]" />{dayNames[hour.day_of_week]}</label><TimeField label="Opens" value={hour.opens_at} disabled={!hour.enabled} onChange={(value) => setHours((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, opens_at: value } : row))} /><TimeField label="Closes" value={hour.closes_at} disabled={!hour.enabled} onChange={(value) => setHours((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, closes_at: value } : row))} /></div>)}</div>
      <div className="mt-5 flex justify-end"><button type="button" onClick={() => void saveHours()} disabled={hoursBusy} className="min-h-11 rounded-xl bg-neon-green px-6 text-sm font-black text-brand-black disabled:opacity-50">{hoursBusy ? 'Saving…' : 'Save hours'}</button></div>
    </section>

    <section className="mt-6"><SectionHeading title="Approval documents" description="Verified and pending files are locked. A rejected document can be corrected and resubmitted for review." />{documents.length === 0 ? <div className="mt-4 rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 px-5 py-12 text-center text-sm text-white/40">No approval documents were submitted.</div> : <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">{documents.map((document) => <div key={document.id}><AdminDocumentCard document={document} />{document.status === 'rejected' && <label className="mt-2 flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-neon-green/30 bg-neon-green/[0.06] px-4 text-xs font-black text-neon-green hover:bg-neon-green/10"><span>{documentBusy === document.id ? 'Uploading…' : 'Replace rejected document'}</span><input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf" disabled={documentBusy !== null} className="sr-only" onChange={(event) => void replaceDocument(document, event.target.files?.[0])} /></label>}</div>)}</div>}</section>
  </div>
}

function profileFields(restaurant: RestaurantOutletContext['restaurant']): RestaurantProfileInput { return { name: restaurant.name, description: restaurant.description ?? '', cuisine_type: restaurant.cuisine_type ?? '', phone: restaurant.phone ?? '', address: restaurant.address, latitude: restaurant.latitude, longitude: restaurant.longitude, default_prep_time_minutes: restaurant.default_prep_time_minutes, min_order_amount: restaurant.min_order_amount } }
function blankHours(): HourDraft[] { return dayNames.map((_, day) => ({ day_of_week: day, opens_at: '09:00', closes_at: '18:00', enabled: false })) }
function mergeHours(saved: RestaurantOperatingHour[]): HourDraft[] { const byDay = new Map(saved.map((hour) => [hour.day_of_week, hour])); return blankHours().map((row) => ({ ...row, ...(byDay.get(row.day_of_week) ?? {}), enabled: byDay.has(row.day_of_week) })) }
function documentLabel(value: string) { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) }
function apiMessage(error: unknown, fallback: string) { return isApiError(error) ? error.message : fallback }
function PageHeading({ title, description }: { title: string; description: string }) { return <header><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Store settings</p><h1 className="mt-2 text-3xl font-black text-white sm:text-4xl">{title}</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">{description}</p></header> }
function SectionHeading({ title, description }: { title: string; description: string }) { return <div><h2 className="text-lg font-black text-white">{title}</h2><p className="mt-1 max-w-2xl text-sm leading-6 text-white/40">{description}</p></div> }
function TimeField({ label, value, disabled, onChange }: { label: string; value: string; disabled: boolean; onChange: (value: string) => void }) { return <label><span className="mb-1.5 block text-[10px] font-black uppercase tracking-[0.12em] text-white/35">{label}</span><input type="time" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className="min-h-11 w-full rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none focus:border-neon-green disabled:opacity-30" /></label> }
function PageSkeleton() { return <div className="space-y-5" aria-busy="true"><div className="h-24 animate-pulse rounded-2xl bg-brand-surface" /><div className="h-96 animate-pulse rounded-2xl bg-brand-surface" /><div className="h-72 animate-pulse rounded-2xl bg-brand-surface" /></div> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section role="alert" className="rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-14 text-center"><p className="text-sm text-red-100/75">{message}</p><button type="button" onClick={onRetry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section> }

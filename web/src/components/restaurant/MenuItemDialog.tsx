import { useEffect, useMemo, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import {
  apiAssetUrl,
  createRestaurantMenuItem,
  isApiError,
  updateRestaurantMenuItem,
  uploadRestaurantMenuItemPhoto,
} from '../../lib/api'
import type { RestaurantMenuCategory, RestaurantMenuItem, RestaurantMenuItemInput } from '../../lib/api'

const MAX_IMAGE_BYTES = 10 * 1024 * 1024
const acceptedImageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif']

interface FormState {
  name: string
  description: string
  base_price: string
  menu_category_id: string
  is_available: boolean
  is_featured: boolean
  scheduled: boolean
  available_from: string
  available_until: string
  photo: File | null
}

function initialState(item: RestaurantMenuItem | null, categories: RestaurantMenuCategory[]): FormState {
  return {
    name: item?.name ?? '',
    description: item?.description ?? '',
    base_price: item?.base_price ?? '',
    menu_category_id: item?.category.id ?? categories[0]?.id ?? '',
    is_available: item?.is_available ?? true,
    is_featured: item?.is_featured ?? false,
    scheduled: Boolean(item?.available_from || item?.available_until),
    available_from: item?.available_from ?? '09:00',
    available_until: item?.available_until ?? '21:00',
    photo: null,
  }
}

export function MenuItemDialog({ categories, item, onClose, onSaved }: {
  categories: RestaurantMenuCategory[]
  item: RestaurantMenuItem | null
  onClose: () => void
  onSaved: (item: RestaurantMenuItem, message: string) => void
}) {
  const [form, setForm] = useState(() => initialState(item, categories))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [globalError, setGlobalError] = useState('')
  const [saving, setSaving] = useState(false)
  const previewUrl = useMemo(() => form.photo ? URL.createObjectURL(form.photo) : apiAssetUrl(item?.photo_path ?? null), [form.photo, item?.photo_path])

  useEffect(() => () => {
    if (form.photo && previewUrl?.startsWith('blob:')) URL.revokeObjectURL(previewUrl)
  }, [form.photo, previewUrl])

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }))
    setErrors((current) => ({ ...current, [key]: '' }))
    setGlobalError('')
  }

  function choosePhoto(file: File | null) {
    if (!file) {
      update('photo', null)
      return
    }
    if (!acceptedImageTypes.includes(file.type)) {
      setErrors((current) => ({ ...current, photo: 'Use a JPEG, PNG, WebP, HEIC, or HEIF image.' }))
      return
    }
    if (file.size > MAX_IMAGE_BYTES) {
      setErrors((current) => ({ ...current, photo: 'The image must be 10 MB or smaller.' }))
      return
    }
    update('photo', file)
  }

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!form.name.trim()) next.name = 'Item name is required.'
    if (!form.menu_category_id) next.menu_category_id = 'Choose a category.'
    if (form.base_price.trim() === '' || Number.isNaN(Number(form.base_price)) || Number(form.base_price) < 0) next.base_price = 'Enter a price of zero or greater.'
    if (form.scheduled && (!form.available_from || !form.available_until)) next.schedule = 'Choose both a start and end time.'
    setErrors(next)
    return Object.keys(next).length === 0
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (saving || !validate()) return
    setSaving(true)
    setGlobalError('')

    const payload: RestaurantMenuItemInput = {
      menu_category_id: form.menu_category_id,
      name: form.name.trim(),
      description: form.description.trim(),
      base_price: form.base_price,
      is_available: form.is_available,
      is_featured: form.is_featured,
      available_from: form.scheduled ? form.available_from : null,
      available_until: form.scheduled ? form.available_until : null,
      photo: form.photo,
    }

    try {
      if (!item) {
        const response = await createRestaurantMenuItem(payload)
        onSaved(response.item, response.message)
      } else {
        const details: Omit<RestaurantMenuItemInput, 'photo'> = {
          menu_category_id: payload.menu_category_id,
          name: payload.name,
          description: payload.description,
          base_price: payload.base_price,
          is_available: payload.is_available,
          is_featured: payload.is_featured,
          available_from: payload.available_from,
          available_until: payload.available_until,
        }
        let response = await updateRestaurantMenuItem(item.id, details)
        if (form.photo) response = await uploadRestaurantMenuItemPhoto(item.id, form.photo)
        onSaved(response.item, form.photo ? 'Menu item and photo updated.' : response.message)
      }
    } catch (requestError) {
      if (isApiError(requestError) && requestError.errors) {
        const fieldErrors: Record<string, string> = {}
        for (const [field, messages] of Object.entries(requestError.errors)) if (messages[0]) fieldErrors[field] = messages[0]
        setErrors(fieldErrors)
      }
      setGlobalError(isApiError(requestError) ? requestError.message : 'The menu item could not be saved.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/75 p-0 backdrop-blur-sm sm:items-center sm:p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !saving) onClose() }}>
      <section role="dialog" aria-modal="true" aria-labelledby="menu-item-dialog-title" className="max-h-[96svh] w-full overflow-y-auto rounded-t-3xl border border-white/10 bg-brand-surface shadow-2xl sm:max-w-3xl sm:rounded-2xl">
        <header className="sticky top-0 z-10 flex items-center justify-between border-b border-white/10 bg-brand-surface/95 px-5 py-4 backdrop-blur sm:px-6">
          <div><p className="text-xs font-black uppercase tracking-[0.16em] text-neon-green">Menu item</p><h2 id="menu-item-dialog-title" className="mt-1 text-xl font-black text-white">{item ? 'Edit item' : 'Add an item'}</h2></div>
          <button type="button" disabled={saving} onClick={onClose} aria-label="Close" className="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 text-xl text-white/55 hover:text-white">×</button>
        </header>

        <form onSubmit={submit} className="p-5 sm:p-6">
          {globalError && <div role="alert" className="mb-5 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200">{globalError}</div>}
          <div className="grid gap-5 md:grid-cols-[14rem_minmax(0,1fr)]">
            <div>
              <label className="group flex aspect-square cursor-pointer flex-col items-center justify-center overflow-hidden rounded-2xl border border-dashed border-white/15 bg-brand-black text-center transition hover:border-neon-green/40">
                {previewUrl ? <img src={previewUrl} alt="Item preview" className="h-full w-full object-cover" /> : <span className="px-5"><span className="block text-3xl text-neon-green">+</span><span className="mt-2 block text-sm font-bold text-white/65">Add item photo</span><span className="mt-1 block text-xs text-white/30">Up to 10 MB</span></span>}
                <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" onChange={(event) => choosePhoto(event.target.files?.[0] ?? null)} className="sr-only" />
              </label>
              {previewUrl && <label className="mt-2 flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-white/10 text-xs font-bold text-white/55 hover:border-neon-green/30 hover:text-neon-green">Choose another image<input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" onChange={(event) => choosePhoto(event.target.files?.[0] ?? null)} className="sr-only" /></label>}
              {errors.photo && <p className="mt-2 text-xs text-red-300">{errors.photo}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Name" error={errors.name} className="sm:col-span-2"><input value={form.name} onChange={(event) => update('name', event.target.value)} maxLength={255} className={inputClass} placeholder="e.g. Chicken Adobo" /></Field>
              <Field label="Category" error={errors.menu_category_id}><select value={form.menu_category_id} onChange={(event) => update('menu_category_id', event.target.value)} className={inputClass}>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></Field>
              <Field label="Base price" error={errors.base_price}><div className="relative"><span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/35">₱</span><input type="number" min="0" step="0.01" inputMode="decimal" value={form.base_price} onChange={(event) => update('base_price', event.target.value)} className={`${inputClass} pl-8`} placeholder="0.00" /></div></Field>
              <Field label="Description" error={errors.description} className="sm:col-span-2"><textarea value={form.description} onChange={(event) => update('description', event.target.value)} rows={3} className={`${inputClass} min-h-24 resize-y py-3`} placeholder="What makes this dish special?" /></Field>
            </div>
          </div>

          <div className="mt-6 grid gap-3 sm:grid-cols-2">
            <Toggle checked={form.is_available} onChange={(checked) => update('is_available', checked)} title="Available for ordering" description="Turn off when temporarily sold out." />
            <Toggle checked={form.is_featured} onChange={(checked) => update('is_featured', checked)} title="Featured item" description="Give this item extra visibility." />
          </div>

          <div className="mt-5 rounded-2xl border border-white/10 bg-brand-black/50 p-4">
            <Toggle checked={form.scheduled} onChange={(checked) => update('scheduled', checked)} title="Daily availability window" description="Show this item only between specific times each day." />
            {form.scheduled && <div className="mt-4 grid grid-cols-2 gap-3 border-t border-white/[0.07] pt-4"><Field label="Available from"><input type="time" value={form.available_from} onChange={(event) => update('available_from', event.target.value)} className={inputClass} /></Field><Field label="Available until"><input type="time" value={form.available_until} onChange={(event) => update('available_until', event.target.value)} className={inputClass} /></Field></div>}
            {errors.schedule && <p className="mt-2 text-xs text-red-300">{errors.schedule}</p>}
          </div>

          <div className="mt-7 grid grid-cols-2 gap-3">
            <button type="button" disabled={saving} onClick={onClose} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65 hover:text-white disabled:opacity-50">Cancel</button>
            <button type="submit" disabled={saving} className="min-h-12 rounded-xl bg-neon-green text-sm font-black text-brand-black transition hover:bg-[#55ff35] disabled:opacity-50">{saving ? 'Saving…' : item ? 'Save changes' : 'Add item'}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

const inputClass = 'h-12 w-full rounded-xl border border-white/10 bg-brand-black px-3 text-sm text-white outline-none transition placeholder:text-white/25 focus:border-neon-green focus:ring-2 focus:ring-neon-green/15'

function Field({ label, error, className = '', children }: { label: string; error?: string; className?: string; children: ReactNode }) {
  return <label className={`block text-sm font-bold text-white/65 ${className}`}><span>{label}</span><span className="mt-2 block">{children}</span>{error && <span className="mt-1.5 block text-xs font-normal text-red-300">{error}</span>}</label>
}

function Toggle({ checked, onChange, title, description }: { checked: boolean; onChange: (checked: boolean) => void; title: string; description: string }) {
  return <label className="flex min-h-16 cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-white/[0.025] px-3 py-2.5"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="peer sr-only"/><span className="relative h-7 w-12 shrink-0 rounded-full bg-white/15 transition peer-checked:bg-neon-green"><span className={`absolute top-1 h-5 w-5 rounded-full bg-brand-black transition-transform ${checked ? 'translate-x-6' : 'translate-x-1'}`} /></span><span><span className="block text-sm font-bold text-white/80">{title}</span><span className="mt-0.5 block text-xs leading-4 text-white/35">{description}</span></span></label>
}

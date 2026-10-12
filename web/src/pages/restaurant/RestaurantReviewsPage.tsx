import { useEffect, useState } from 'react'
import { AdminPagination } from '../../components/admin/AdminPagination'
import { apiAssetUrl, getRestaurantReviews, isApiError, replyToRestaurantReview } from '../../lib/api'
import type { PaginationMeta, RestaurantReview } from '../../lib/api'

const date = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' })

export function RestaurantReviewsPage() {
  const [reviews, setReviews] = useState<RestaurantReview[]>([])
  const [meta, setMeta] = useState<PaginationMeta>({ page: 1, perPage: 20, total: 0, lastPage: 1 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [replying, setReplying] = useState<RestaurantReview | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      setLoading(true); setError('')
      void getRestaurantReviews(page, controller.signal).then((response) => { setReviews(response.data); setMeta(response.meta) }).catch((requestError: unknown) => { if (!controller.signal.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load reviews.') }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [page, retry])

  function saved(review: RestaurantReview) { setReviews((current) => current.map((item) => item.id === review.id ? review : item)); setReplying(null) }

  return <div>
    <header><p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Customer feedback</p><h1 className="mt-2 text-3xl font-black text-white sm:text-4xl">Reviews</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">Read verified order feedback and publish a response when context is useful.</p></header>
    {loading ? <Loading /> : error ? <ErrorState message={error} onRetry={() => setRetry((value) => value + 1)} /> : reviews.length === 0 ? <Empty /> : <><div className="mt-6 grid gap-4 lg:grid-cols-2">{reviews.map((review) => <ReviewCard key={review.id} review={review} onReply={() => setReplying(review)} />)}</div><AdminPagination meta={meta} onPage={setPage} /></>}
    {replying && <ReplyDialog review={replying} onClose={() => setReplying(null)} onSaved={saved} />}
  </div>
}

function ReviewCard({ review, onReply }: { review: RestaurantReview; onReply: () => void }) {
  const photo = apiAssetUrl(review.photo_path)
  return <article className="rounded-2xl border border-white/10 bg-brand-surface p-5"><div className="flex items-start justify-between gap-4"><div><p className="font-black text-white">{review.customer.name}</p><p className="mt-1 text-xs text-white/35">Order #{review.order_number} · {formatDate(review.created_at)}</p></div><span aria-label={`${review.rating} out of 5 stars`} className="shrink-0 text-sm font-black text-amber-300">★ {review.rating}/5</span></div><p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-white/65">{review.comment || 'No written comment.'}</p>{photo && <a href={photo} target="_blank" rel="noreferrer"><img src={photo} alt="Customer review" className="mt-4 max-h-56 w-full rounded-xl border border-white/10 object-cover" /></a>}{review.restaurant_reply ? <div className="mt-4 rounded-xl border border-neon-green/15 bg-neon-green/[0.04] p-4"><p className="text-[10px] font-black uppercase tracking-[0.14em] text-neon-green">Your reply</p><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-white/65">{review.restaurant_reply}</p><button type="button" onClick={onReply} className="mt-3 min-h-10 text-xs font-black text-neon-green">Edit reply</button></div> : <button type="button" onClick={onReply} className="mt-4 min-h-11 rounded-xl border border-neon-green/30 px-4 text-sm font-black text-neon-green hover:bg-neon-green/10">Reply to review</button>}</article>
}

function ReplyDialog({ review, onClose, onSaved }: { review: RestaurantReview; onClose: () => void; onSaved: (review: RestaurantReview) => void }) {
  const [reply, setReply] = useState(review.restaurant_reply ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event: React.FormEvent) { event.preventDefault(); setBusy(true); setError(''); try { const response = await replyToRestaurantReview(review.id, reply.trim()); onSaved(response.review) } catch (requestError) { setError(isApiError(requestError) ? requestError.message : 'The reply could not be saved.') } finally { setBusy(false) } }
  return <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center"><form onSubmit={submit} role="dialog" aria-modal="true" className="w-full max-w-lg rounded-2xl border border-white/10 bg-brand-surface p-6"><h2 className="text-xl font-black text-white">Reply to {review.customer.name}</h2><p className="mt-1 text-sm text-white/40">Your reply will be visible to customers.</p>{error && <p role="alert" className="mt-4 rounded-xl bg-red-400/[0.08] p-3 text-sm text-red-200">{error}</p>}<label className="mt-5 block"><span className="mb-2 block text-sm font-semibold text-white/65">Reply</span><textarea autoFocus required maxLength={5000} rows={6} value={reply} onChange={(event) => setReply(event.target.value)} className="w-full rounded-xl border border-white/10 bg-brand-black px-4 py-3 text-sm text-white outline-none focus:border-neon-green" /></label><p className="mt-1 text-right text-xs text-white/30">{reply.length}/5000</p><div className="mt-5 grid grid-cols-2 gap-3"><button type="button" onClick={onClose} disabled={busy} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/60">Cancel</button><button disabled={busy || !reply.trim()} className="min-h-12 rounded-xl bg-neon-green text-sm font-black text-brand-black disabled:opacity-40">{busy ? 'Saving…' : 'Save reply'}</button></div></form></div>
}

function formatDate(value: string | null) { if (!value) return 'Date unavailable'; const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? 'Date unavailable' : date.format(parsed) }
function Loading() { return <div className="mt-6 grid gap-4 lg:grid-cols-2" aria-busy="true">{[0, 1, 2, 3].map((row) => <div key={row} className="h-64 animate-pulse rounded-2xl bg-brand-surface" />)}</div> }
function Empty() { return <section className="mt-6 rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 px-5 py-14 text-center"><h2 className="font-black text-white">No reviews yet</h2><p className="mt-2 text-sm text-white/40">Customer feedback from delivered orders will appear here.</p></section> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section role="alert" className="mt-6 rounded-2xl border border-red-400/20 bg-red-400/[0.06] px-5 py-14 text-center"><p className="text-sm text-red-100/75">{message}</p><button type="button" onClick={onRetry} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Try again</button></section> }

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

  return <div className="fj-restaurant-reviews-page">
    <header className="fj-restaurant-page-heading"><p>CUSTOMER FEEDBACK</p><h1>Reviews</h1><span>Read verified order feedback and publish a response when context is useful.</span></header>
    {loading ? <Loading /> : error ? <ErrorState message={error} onRetry={() => setRetry((value) => value + 1)} /> : reviews.length === 0 ? <Empty /> : <><div className="mt-6 grid gap-4 lg:grid-cols-2">{reviews.map((review) => <ReviewCard key={review.id} review={review} onReply={() => setReplying(review)} />)}</div><AdminPagination meta={meta} onPage={setPage} /></>}
    {replying && <ReplyDialog review={replying} onClose={() => setReplying(null)} onSaved={saved} />}
  </div>
}

function ReviewCard({ review, onReply }: { review: RestaurantReview; onReply: () => void }) {
  const photo = apiAssetUrl(review.photo_path)
  return <article className="fj-review-card"><div className="fj-review-card__header"><div><strong>{review.customer.name}</strong><small>Order #{review.order_number} · {formatDate(review.created_at)}</small></div><span aria-label={`${review.rating} out of 5 stars`}>★ {review.rating}/5</span></div><p className="fj-review-card__comment">{review.comment || 'No written comment.'}</p>{photo && <a href={photo} target="_blank" rel="noreferrer"><img src={photo} alt="Customer review" /></a>}{review.restaurant_reply ? <div className="fj-review-card__reply"><strong>Your reply</strong><p>{review.restaurant_reply}</p><button type="button" onClick={onReply}>Edit reply</button></div> : <button type="button" onClick={onReply} className="fj-review-card__action">Reply to review</button>}</article>
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
function Empty() { return <section className="fj-restaurant-empty"><span>★</span><h2>No reviews yet</h2><p>Customer feedback from delivered orders will appear here.</p></section> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section role="alert" className="fj-restaurant-error-state"><p>{message}</p><button type="button" onClick={onRetry}>Try again</button></section> }

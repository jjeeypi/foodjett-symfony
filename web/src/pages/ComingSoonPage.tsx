import { Link, useParams } from 'react-router-dom'

export function ComingSoonPage({ title, message }: { title: string; message: string }) {
  const { id } = useParams()

  return (
    <section className="mx-auto max-w-2xl rounded-2xl border border-white/10 bg-brand-surface px-6 py-12 text-center sm:px-10">
      <p className="text-xs font-bold uppercase tracking-[0.2em] text-neon-green">Coming next</p>
      <h1 className="mt-3 text-3xl font-black text-white">{title}{id ? ` #${id}` : ''}</h1>
      <p className="mx-auto mt-3 max-w-lg text-sm leading-6 text-white/50">{message}</p>
      <Link to="/" className="mt-7 inline-flex min-h-11 items-center rounded-xl bg-neon-green px-5 text-sm font-bold text-brand-black transition hover:bg-[#55ff35] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green focus-visible:ring-offset-2 focus-visible:ring-offset-brand-surface">Back to restaurants</Link>
    </section>
  )
}

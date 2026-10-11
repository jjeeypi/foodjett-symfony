export function AdminComingSoonPage({ section }: { section: string }) {
  return (
    <section className="flex min-h-[55vh] items-center justify-center">
      <div className="w-full max-w-xl rounded-2xl border border-white/10 bg-brand-surface p-7 text-center sm:p-10">
        <p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Admin workspace</p>
        <h2 className="mt-3 text-3xl font-black text-white">{section}</h2>
        <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-white/45">Coming soon. This route is reserved for its dedicated admin frontend phase.</p>
      </div>
    </section>
  )
}

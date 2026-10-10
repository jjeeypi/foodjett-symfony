import type { ReactNode } from 'react'

/** Shared mobile-first shell for the customer authentication screens. */
export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <main className="auth-shell relative flex min-h-svh items-start justify-center overflow-x-hidden bg-brand-black px-4 sm:items-center sm:px-6">
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
        <div className="absolute left-1/2 top-0 h-80 w-80 -translate-x-1/2 rounded-full bg-neon-green/[0.055] blur-[110px] sm:top-1/2 sm:h-[38rem] sm:w-[38rem] sm:-translate-y-1/2" />
        <div className="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,0.018)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.018)_1px,transparent_1px)] bg-[size:36px_36px] [mask-image:radial-gradient(circle_at_center,black,transparent_78%)]" />
      </div>

      <div className="relative z-10 w-full max-w-[27.5rem]">{children}</div>
    </main>
  )
}

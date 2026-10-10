import type { ReactNode } from 'react'
import { Logo } from './Logo'

const benefits = [
  'Discover local favorites in seconds',
  'Track every order from kitchen to door',
  'Enjoy fast, reliable delivery',
]

function CheckIcon() {
  return (
    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-neon-green text-brand-black">
      <svg aria-hidden="true" className="h-3 w-3" fill="none" stroke="currentColor" strokeWidth="3" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" d="m5 12 4 4L19 6" />
      </svg>
    </span>
  )
}

/** Shared mobile-first shell for the customer authentication screens. */
export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <main className="auth-shell relative min-h-svh overflow-x-hidden bg-brand-black px-4 sm:px-6 lg:flex lg:items-center lg:px-10">
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
        <div className="absolute -left-32 top-1/3 h-[32rem] w-[32rem] rounded-full bg-neon-green/[0.07] blur-[130px]" />
        <div className="absolute right-0 top-0 h-80 w-80 rounded-full bg-neon-green/[0.04] blur-[110px]" />
        <div className="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,0.018)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.018)_1px,transparent_1px)] bg-[size:40px_40px] [mask-image:radial-gradient(circle_at_center,black,transparent_82%)]" />
      </div>

      <div className="relative z-10 mx-auto grid w-full max-w-6xl items-center gap-10 lg:grid-cols-[1fr_28rem] lg:gap-16 xl:gap-24">
        <section className="hidden lg:block" aria-labelledby="auth-value-heading">
          <Logo className="mb-12 w-52 sm:w-52" />
          <p className="mb-5 text-sm font-bold uppercase tracking-[0.22em] text-neon-green">Made for your cravings</p>
          <h1 id="auth-value-heading" className="max-w-xl text-5xl font-black leading-[1.08] tracking-[-0.04em] text-white xl:text-6xl">
            Great food, delivered at full speed.
          </h1>
          <p className="mt-6 max-w-lg text-lg leading-8 text-white/55">
            Foodjett brings your favorite restaurants closer, with a smoother way to order and track every bite.
          </p>
          <ul className="mt-10 grid gap-4" aria-label="Foodjett benefits">
            {benefits.map((benefit) => (
              <li key={benefit} className="flex items-center gap-3 text-sm font-medium text-white/75">
                <CheckIcon />
                {benefit}
              </li>
            ))}
          </ul>
          <p className="mt-16 text-xs text-white/30">Fast checkout. Secure account. Real-time updates.</p>
        </section>

        <div className="mx-auto w-full max-w-[28rem]">
          <div className="mb-7 flex justify-center lg:hidden">
            <Logo />
          </div>
          {children}
        </div>
      </div>
    </main>
  )
}

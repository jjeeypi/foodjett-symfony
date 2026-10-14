import type { ReactNode } from 'react'
import { Logo } from './Logo'
import '../styles/auth-experience.css'

const benefits = [
  { title: 'Order with confidence', copy: 'Find local kitchens and follow every order to your door.' },
  { title: 'Grow your restaurant', copy: 'Manage menus, orders, and earnings from one workspace.' },
  { title: 'Deliver on your terms', copy: 'Go online, choose nearby runs, and track your pay.' },
]

function CheckIcon() {
  return <span className="fj-auth-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6"><path strokeLinecap="round" strokeLinejoin="round" d="m6 12 4 4 8-9" /></svg></span>
}

/** Shared responsive shell for login and every public registration flow. */
export function AuthLayout({ children, showStandaloneLogo = true }: { children: ReactNode; showStandaloneLogo?: boolean }) {
  return <main className="fj-auth">
    <div className="fj-auth-decoration" aria-hidden="true"><i /><i /></div>
    <section className="fj-auth-story" aria-labelledby="auth-value-heading">
      {showStandaloneLogo && <Logo tone="light-surface" className="fj-auth-brand-logo" />}
      <div className="fj-auth-story-copy">
        <p className="fj-auth-eyebrow"><span /> ONE FOODJETT ACCOUNT</p>
        <h2 id="auth-value-heading">Good food moves everyone forward.</h2>
        <p>Customers, restaurant partners, riders, and operators all sign in through one secure home.</p>
        <ul aria-label="Ways to use Foodjett">{benefits.map((benefit) => <li key={benefit.title}><CheckIcon /><span><strong>{benefit.title}</strong><small>{benefit.copy}</small></span></li>)}</ul>
      </div>
      <p className="fj-auth-story-foot">Local food. Reliable delivery. Better days.</p>
    </section>
    <section className="fj-auth-stage">
      {showStandaloneLogo && <header className="fj-auth-mobile-brand"><Logo tone="light-surface" /><p>Your daily good food</p></header>}
      <div className="fj-auth-form-shell">{children}</div>
      <p className="fj-auth-security">Secure sign-in · Protected account data</p>
    </section>
  </main>
}

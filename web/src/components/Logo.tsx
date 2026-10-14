type LogoProps = {
  className?: string
  tone?: 'dark-surface' | 'light-surface'
}

/** Foodjett wordmark with a high-contrast option for light surfaces. */
export function Logo({ className = '', tone = 'dark-surface' }: LogoProps) {
  if (tone === 'light-surface') {
    return (
      <span
        role="img"
        aria-label="Foodjett"
        className={`fj-logo-on-light inline-flex w-fit select-none items-center gap-1 font-sans text-[30px] font-extrabold leading-none tracking-[-0.075em] text-[#193428] ${className}`}
      >
        <span>foodjett</span>
        <svg className="h-[0.72em] w-[0.72em] shrink-0 rotate-[10deg] text-[#74a646]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="m13 2-9 12h7l-1 8 10-13h-7Z" />
        </svg>
      </span>
    )
  }

  return (
    <img
      src="/logo.svg"
      alt="Foodjett"
      width={207}
      height={48}
      className={`h-auto w-44 select-none object-contain sm:w-48 ${className}`}
      draggable={false}
    />
  )
}

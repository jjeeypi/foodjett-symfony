/** Foodjett brand logo copied from docs/logo file 2/2.svg. */
export function Logo({ className = '' }: { className?: string }) {
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

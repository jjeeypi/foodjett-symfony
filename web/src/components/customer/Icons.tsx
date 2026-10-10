import type { SVGProps } from 'react'

type IconProps = SVGProps<SVGSVGElement>

function IconBase({ children, ...props }: IconProps) {
  return <svg aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" {...props}>{children}</svg>
}

export function HomeIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="m3 11 9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9Z" /></IconBase>
}

export function FoodIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="M7 3v8m-3-8v5a3 3 0 0 0 6 0V3M7 11v10m8-18v18m0-18c3 2 5 5 5 9h-5" /></IconBase>
}

export function CartIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H6M10 20h.01M17 20h.01" /></IconBase>
}

export function MessageIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="M21 12a8 8 0 0 1-8 8H5l-3 2 1-5a9 9 0 1 1 18-5Z" /></IconBase>
}

export function OrdersIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="M7 3h10a2 2 0 0 1 2 2v16l-3-2-4 2-4-2-3 2V5a2 2 0 0 1 2-2Zm2 5h6m-6 4h6" /></IconBase>
}

export function SearchIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="11" cy="11" r="7" /><path strokeLinecap="round" d="m20 20-4-4" /></IconBase>
}

export function UserIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="12" cy="8" r="4" /><path strokeLinecap="round" strokeLinejoin="round" d="M4 21a8 8 0 0 1 16 0" /></IconBase>
}

export function CloseIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" d="M6 6 18 18M18 6 6 18" /></IconBase>
}

export function PlusIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" d="M12 5v14M5 12h14" /></IconBase>
}

export function MinusIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" d="M5 12h14" /></IconBase>
}

export function TrashIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="M4 7h16m-10 4v6m4-6v6M9 7l1-3h4l1 3m3 0-1 14H7L6 7" /></IconBase>
}

export function ChevronIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="m9 18 6-6-6-6" /></IconBase>
}

export function EmptyCartIcon(props: IconProps) {
  return <IconBase {...props}><path strokeLinecap="round" strokeLinejoin="round" d="M4 5h2l2 10h9l2-7H7m3 11h.01M17 19h.01" /><path strokeLinecap="round" d="m9 8 6 6m0-6-6 6" /></IconBase>
}

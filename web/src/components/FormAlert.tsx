export function FormAlert({ message }: { message: string }) {
  if (!message) return null

  return (
    <div role="alert" aria-live="assertive" className="mb-5 flex items-start gap-2 rounded-xl border border-red-400/50 bg-red-500/10 px-4 py-3 text-sm text-red-300">
      <svg aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
        <path fillRule="evenodd" clipRule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm-1-9a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0V6a1 1 0 0 0-1-1Z" />
      </svg>
      <span>{message}</span>
    </div>
  )
}

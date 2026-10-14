import { useEffect, useRef, useState, type FormEvent } from 'react'
import type { ConversationMessage, CustomerConversation } from '../../lib/api'
import { ChevronIcon } from './Icons'

const timeFormatter = new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' })

interface ChatThreadProps {
  conversation: CustomerConversation
  messages: ConversationMessage[]
  currentUserId: string
  loading: boolean
  sending: boolean
  connection: 'connecting' | 'live' | 'disconnected'
  error: string
  onBack: () => void
  onRetry: () => void
  onSend: (body: string) => Promise<boolean>
  variant?: 'default' | 'rider'
}

function Avatar({ conversation }: { conversation: CustomerConversation }) {
  const path = conversation.counterpart.avatar_path
  const initial = (conversation.counterpart.name ?? '?').charAt(0).toUpperCase()

  return (
    <div className="fj-chat-thread__avatar flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white/[0.05] text-sm font-black text-neon-green">
      {path ? <img src={path} alt="" className="h-full w-full object-cover" /> : initial}
    </div>
  )
}

export function ChatThread({ conversation, messages, currentUserId, loading, sending, connection, error, onBack, onRetry, onSend, variant = 'default' }: ChatThreadProps) {
  const [body, setBody] = useState('')
  const messageListRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const list = messageListRef.current
    if (list) list.scrollTo({ top: list.scrollHeight, behavior: 'smooth' })
  }, [messages])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const normalized = body.trim()
    if (!normalized || sending || conversation.is_closed) return
    if (await onSend(normalized)) setBody('')
  }

  return (
    <section className={`fj-chat-thread${variant === 'rider' ? ' fj-chat-thread--rider' : ''} flex min-h-[65svh] min-w-0 w-full max-w-full flex-1 flex-col overflow-hidden rounded-3xl border border-white/10 bg-brand-surface md:min-h-[42rem]`}>
      <header className="fj-chat-thread__header flex min-h-20 min-w-0 max-w-full items-center gap-3 border-b border-white/10 px-4 sm:px-5">
        <button type="button" onClick={onBack} aria-label="Back to conversations" className="fj-chat-thread__back flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-white/10 text-white/70 md:hidden">
          <ChevronIcon className="h-5 w-5 rotate-180" />
        </button>
        <Avatar conversation={conversation} />
        <div className="min-w-0 flex-1">
          <h2 className="fj-chat-thread__title truncate font-black text-white">{conversation.counterpart.name ?? 'Conversation'}</h2>
          <p className="fj-chat-thread__subtitle mt-0.5 truncate text-xs text-white/40">Order #{conversation.order.order_number} · {conversation.order.restaurant_name}</p>
        </div>
        <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider ${connection === 'live' ? 'border-neon-green/25 bg-neon-green/8 text-neon-green' : connection === 'connecting' ? 'border-amber-300/20 text-amber-200' : 'border-red-300/20 text-red-200'}`}>
          <span className="h-1.5 w-1.5 rounded-full bg-current" />{connection === 'live' ? 'Live' : connection === 'connecting' ? 'Connecting' : 'Offline'}
        </span>
      </header>

      <div ref={messageListRef} className="fj-chat-thread__messages min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6" aria-live="polite">
        {loading ? (
          <div className="fj-chat-thread__skeleton animate-pulse space-y-4" aria-label="Loading messages">
            <div className="h-14 w-2/3 rounded-2xl bg-white/[0.06]" />
            <div className="ml-auto h-16 w-3/5 rounded-2xl bg-neon-green/10" />
            <div className="h-20 w-4/5 rounded-2xl bg-white/[0.06]" />
          </div>
        ) : error ? (
          <div className="fj-chat-thread__empty flex h-full min-h-72 flex-col items-center justify-center text-center">
            <p className="text-sm text-red-200">{error}</p>
            <button type="button" onClick={onRetry} className="mt-4 min-h-11 rounded-xl border border-neon-green/35 px-4 text-sm font-bold text-neon-green">Try again</button>
          </div>
        ) : messages.length === 0 ? (
          <div className="fj-chat-thread__empty flex h-full min-h-72 flex-col items-center justify-center text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-neon-green/10 text-2xl text-neon-green">…</span>
            <h3 className="mt-4 font-black text-white">Start the conversation</h3>
            <p className="mt-1 max-w-xs text-sm leading-6 text-white/45">Send a message about order #{conversation.order.order_number}.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {messages.map((message) => {
              const own = message.sender.id === currentUserId
              return (
                <div key={message.id} className={`flex ${own ? 'justify-end' : 'justify-start'}`}>
                  <div className={`fj-chat-thread__bubble ${own ? 'fj-chat-thread__bubble--own' : 'fj-chat-thread__bubble--received'} min-w-0 max-w-[85%] overflow-hidden rounded-2xl px-4 py-3 sm:max-w-[72%] ${own ? 'rounded-br-md bg-neon-green text-brand-black' : 'rounded-bl-md border border-white/10 bg-white/[0.06] text-white'}`}>
                    {!own && <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-white/40">{message.sender.name}</p>}
                    <p className="whitespace-pre-wrap break-words text-sm leading-5">{message.body}</p>
                    <p className={`mt-1.5 text-right text-[10px] ${own ? 'text-brand-black/55' : 'text-white/35'}`}>{timeFormatter.format(new Date(message.created_at))}</p>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {connection === 'disconnected' && !conversation.is_closed && (
        <div className="fj-chat-thread__connection border-t border-amber-300/15 bg-amber-300/[0.05] px-4 py-2 text-center text-xs text-amber-100">Live updates are offline. Messages refresh automatically.</div>
      )}
      {conversation.is_closed ? (
        <div className="fj-chat-thread__closed border-t border-white/10 px-5 py-4 text-center text-sm text-white/45">This order conversation is closed and read-only.</div>
      ) : (
        <form onSubmit={submit} className="fj-chat-thread__form flex w-full min-w-0 max-w-full items-end gap-2 border-t border-white/10 bg-brand-black/35 p-3 sm:p-4">
          <label className="w-0 min-w-0 flex-1">
            <span className="sr-only">Message</span>
            <textarea value={body} onChange={(event) => setBody(event.target.value)} maxLength={1000} rows={1} placeholder="Write a message…" className="fj-chat-thread__textarea max-h-32 min-h-12 w-full resize-none rounded-2xl border border-neon-green/45 bg-brand-surface-2 px-4 py-3 text-base text-white outline-none placeholder:text-white/30 focus:border-neon-green focus:ring-2 focus:ring-neon-green/15 disabled:opacity-50" disabled={sending} />
          </label>
          <button type="submit" disabled={sending || !body.trim()} className="fj-chat-thread__send flex min-h-12 shrink-0 items-center justify-center rounded-2xl bg-neon-green px-5 text-sm font-black text-brand-black transition hover:bg-[#55ff35] disabled:cursor-not-allowed disabled:opacity-40">{sending ? 'Sending…' : 'Send'}</button>
        </form>
      )}
    </section>
  )
}

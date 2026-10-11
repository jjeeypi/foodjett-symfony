import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ChatThread } from '../components/customer/ChatThread'
import { MessageIcon } from '../components/customer/Icons'
import {
  apiAssetUrl,
  authorizeMercure,
  getCurrentUser,
  getCustomerConversationMessages,
  getCustomerConversations,
  isApiError,
  markCustomerConversationRead,
  sendCustomerConversationMessage,
  type ConversationMessage,
  type CustomerConversation,
} from '../lib/api'

const relativeTime = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
const FALLBACK_POLL_MS = 15_000

interface MessageRealtimePayload {
  event?: string
  message_id?: string
  conversation_id?: string
  sender?: { id: string; name: string }
  body?: string
  created_at?: string
}

interface UnreadRealtimePayload {
  conversation_id?: string
}

function timeAgo(value: string | null): string {
  if (!value) return ''
  const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000)
  if (Math.abs(seconds) < 60) return relativeTime.format(seconds, 'second')
  const minutes = Math.round(seconds / 60)
  if (Math.abs(minutes) < 60) return relativeTime.format(minutes, 'minute')
  const hours = Math.round(minutes / 60)
  if (Math.abs(hours) < 24) return relativeTime.format(hours, 'hour')
  return relativeTime.format(Math.round(hours / 24), 'day')
}

function ConversationAvatar({ conversation }: { conversation: CustomerConversation }) {
  const image = apiAssetUrl(conversation.counterpart.avatar_path)
  return (
    <div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white/[0.05] font-black text-neon-green">
      {image ? <img src={image} alt="" className="h-full w-full object-cover" /> : (conversation.counterpart.name ?? '?').charAt(0).toUpperCase()}
    </div>
  )
}

function ListSkeleton() {
  return <div className="animate-pulse space-y-2 p-3">{[1, 2, 3, 4].map((item) => <div key={item} className="h-20 rounded-2xl bg-white/[0.055]" />)}</div>
}

export default function MessagesPage() {
  const { conversationId } = useParams()
  const navigate = useNavigate()
  const [conversations, setConversations] = useState<CustomerConversation[]>([])
  const [messages, setMessages] = useState<ConversationMessage[]>([])
  const [currentUserId, setCurrentUserId] = useState('')
  const [listLoading, setListLoading] = useState(true)
  const [threadLoading, setThreadLoading] = useState(false)
  const [listError, setListError] = useState('')
  const [threadError, setThreadError] = useState('')
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState('')
  const [connection, setConnection] = useState<'connecting' | 'live' | 'disconnected'>('connecting')
  const sourceRef = useRef<EventSource | null>(null)
  const notificationSourceRef = useRef<EventSource | null>(null)

  const selectedConversation = conversations.find((conversation) => conversation.id === conversationId) ?? null

  const loadList = useCallback(async (signal?: AbortSignal) => {
    try {
      const response = await getCustomerConversations(signal)
      setConversations(response.data)
      setListError('')
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setListError(isApiError(error) ? error.message : 'We could not load your conversations.')
    } finally {
      if (!signal?.aborted) setListLoading(false)
    }
  }, [])

  const loadThread = useCallback(async (id: string, signal?: AbortSignal) => {
    try {
      const response = await getCustomerConversationMessages(id, signal)
      setMessages(response.data)
      setConversations((current) => {
        const without = current.filter((conversation) => conversation.id !== response.conversation.id)
        return [{ ...response.conversation, unread_count: 0 }, ...without]
      })
      setThreadError('')
      void markCustomerConversationRead(id)
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setThreadError(isApiError(error) ? error.message : 'We could not load this conversation.')
    } finally {
      if (!signal?.aborted) setThreadLoading(false)
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    const timeout = window.setTimeout(() => {
      void Promise.all([loadList(controller.signal), getCurrentUser(controller.signal).then((user) => setCurrentUserId(user.id))])
        .catch(() => undefined)
    }, 0)
    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [loadList])

  useEffect(() => {
    if (!conversationId) return
    const controller = new AbortController()
    const timeout = window.setTimeout(() => {
      setThreadLoading(true)
      void loadThread(conversationId, controller.signal)
    }, 0)
    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [conversationId, loadThread])

  useEffect(() => {
    notificationSourceRef.current?.close()
    notificationSourceRef.current = null
    if (!currentUserId) return

    let cancelled = false
    let source: EventSource | null = null
    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      const topic = `users/${currentUserId}/notifications`
      if (!authorization.topics.includes(topic)) return
      const hubUrl = new URL(authorization.hub_url)
      hubUrl.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(hubUrl.toString(), { withCredentials: true })
      notificationSourceRef.current = source
      source.addEventListener('notifications.unread_count_updated', (rawEvent) => {
        try {
          const payload = JSON.parse((rawEvent as MessageEvent<string>).data) as UnreadRealtimePayload
          void loadList().then(() => {
            if (payload.conversation_id === conversationId) {
              setConversations((current) => current.map((conversation) => conversation.id === conversationId
                ? { ...conversation, unread_count: 0 }
                : conversation))
            }
          })
        } catch {
          // A malformed notification must not interrupt the open chat subscription.
        }
      })
    }).catch(() => undefined)

    return () => {
      cancelled = true
      source?.close()
      if (notificationSourceRef.current === source) notificationSourceRef.current = null
    }
  }, [conversationId, currentUserId, loadList])

  useEffect(() => {
    sourceRef.current?.close()
    sourceRef.current = null
    if (!conversationId) return

    let cancelled = false
    let source: EventSource | null = null
    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      setConnection('connecting')
      const topic = `conversations/${conversationId}`
      if (!authorization.topics.includes(topic)) throw new Error('Conversation topic is not authorized.')
      const hubUrl = new URL(authorization.hub_url)
      hubUrl.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(hubUrl.toString(), { withCredentials: true })
      sourceRef.current = source
      source.onopen = () => setConnection('live')
      source.onerror = () => setConnection('disconnected')
      source.addEventListener('message.sent', (rawEvent) => {
        try {
          const payload = JSON.parse((rawEvent as MessageEvent<string>).data) as MessageRealtimePayload
          if (payload.conversation_id !== conversationId || !payload.message_id || !payload.sender || !payload.body || !payload.created_at) return
          const message: ConversationMessage = {
            id: payload.message_id,
            conversation_id: conversationId,
            sender: payload.sender,
            body: payload.body,
            created_at: payload.created_at,
            read_at: null,
          }
          setMessages((current) => current.some((entry) => entry.id === message.id) ? current : [...current, message])
          setConversations((current) => current.map((conversation) => conversation.id === conversationId
            ? { ...conversation, last_message: message, updated_at: message.created_at, unread_count: 0 }
            : conversation))
          if (payload.sender.id !== currentUserId) void markCustomerConversationRead(conversationId)
        } catch {
          setConnection('disconnected')
        }
      })
    }).catch(() => {
      if (!cancelled) setConnection('disconnected')
    })

    return () => {
      cancelled = true
      source?.close()
      if (sourceRef.current === source) sourceRef.current = null
    }
  }, [conversationId, currentUserId])

  useEffect(() => {
    if (connection !== 'disconnected' || !conversationId) return
    const interval = window.setInterval(() => void loadThread(conversationId), FALLBACK_POLL_MS)
    return () => window.clearInterval(interval)
  }, [connection, conversationId, loadThread])

  async function send(body: string): Promise<boolean> {
    if (!conversationId) return false
    setSending(true)
    setSendError('')
    try {
      const response = await sendCustomerConversationMessage(conversationId, body)
      setMessages((current) => current.some((message) => message.id === response.message.id) ? current : [...current, response.message])
      setConversations((current) => current.map((conversation) => conversation.id === conversationId
        ? { ...conversation, last_message: response.message, updated_at: response.message.created_at }
        : conversation))
      return true
    } catch (error) {
      setSendError(isApiError(error) ? error.message : 'Your message could not be sent.')
      return false
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="mx-auto min-w-0 max-w-full overflow-hidden xl:max-w-7xl">
      <div className="mb-5 md:mb-7">
        <p className="text-xs font-bold uppercase tracking-[0.18em] text-neon-green">Order conversations</p>
        <h1 className="mt-2 text-3xl font-black text-white sm:text-4xl">Messages</h1>
        <p className="mt-2 text-sm text-white/45">Chat with the restaurant or your assigned rider about an order.</p>
      </div>

      <div className="grid min-w-0 gap-4 md:grid-cols-[21rem_minmax(0,1fr)] lg:grid-cols-[23rem_minmax(0,1fr)]">
        <aside className={`${conversationId ? 'hidden md:block' : 'block'} overflow-hidden rounded-3xl border border-white/10 bg-brand-surface`}>
          <div className="border-b border-white/10 px-5 py-4">
            <h2 className="font-black text-white">Conversations</h2>
            <p className="mt-1 text-xs text-white/35">{conversations.length} order thread{conversations.length === 1 ? '' : 's'}</p>
          </div>
          {listLoading ? <ListSkeleton /> : listError ? (
            <div className="px-5 py-10 text-center">
              <p className="text-sm text-red-200">{listError}</p>
              <button type="button" onClick={() => { setListLoading(true); void loadList() }} className="mt-4 min-h-11 rounded-xl border border-neon-green/35 px-4 text-sm font-bold text-neon-green">Retry</button>
            </div>
          ) : conversations.length === 0 ? (
            <div className="px-5 py-14 text-center">
              <MessageIcon className="mx-auto h-10 w-10 text-neon-green/60" />
              <h3 className="mt-4 font-black text-white">No conversations yet</h3>
              <p className="mt-2 text-sm leading-6 text-white/40">Restaurant chat opens after your order is accepted. Rider chat opens once a rider is assigned.</p>
              <Link to="/" className="mt-5 inline-flex min-h-11 items-center rounded-xl bg-neon-green px-4 text-sm font-black text-brand-black">Browse restaurants</Link>
            </div>
          ) : (
            <div className="max-h-[65svh] overflow-y-auto p-2 md:max-h-[42rem]">
              {conversations.map((conversation) => (
                <Link key={conversation.id} to={`/messages/${conversation.id}`} className={`flex min-h-20 gap-3 rounded-2xl p-3 transition ${conversation.id === conversationId ? 'bg-neon-green/10 ring-1 ring-neon-green/30' : 'hover:bg-white/[0.045]'}`}>
                  <ConversationAvatar conversation={conversation} />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start gap-2">
                      <p className={`min-w-0 flex-1 truncate text-sm ${conversation.unread_count > 0 ? 'font-black text-white' : 'font-bold text-white/80'}`}>{conversation.counterpart.name ?? 'Order conversation'}</p>
                      <span className="shrink-0 text-[10px] text-white/30">{timeAgo(conversation.last_message?.created_at ?? conversation.updated_at)}</span>
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                      <p className={`min-w-0 flex-1 truncate text-xs ${conversation.unread_count > 0 ? 'text-white/70' : 'text-white/35'}`}>{conversation.last_message?.body ?? `Order #${conversation.order.order_number}`}</p>
                      {conversation.unread_count > 0 && <span className="flex min-h-5 min-w-5 items-center justify-center rounded-full bg-neon-green px-1 text-[10px] font-black text-brand-black">{conversation.unread_count > 99 ? '99+' : conversation.unread_count}</span>}
                    </div>
                    <p className="mt-1 text-[10px] uppercase tracking-wider text-white/25">{conversation.counterpart.type} · #{conversation.order.order_number}</p>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </aside>

        <div className={`${conversationId ? 'block' : 'hidden md:block'} min-w-0`}>
          {selectedConversation ? (
            <>
              <ChatThread conversation={{ ...selectedConversation, counterpart: { ...selectedConversation.counterpart, avatar_path: apiAssetUrl(selectedConversation.counterpart.avatar_path) } }} messages={messages} currentUserId={currentUserId} loading={threadLoading} sending={sending} connection={connection} error={threadError} onBack={() => navigate('/messages')} onRetry={() => { setThreadLoading(true); void loadThread(selectedConversation.id) }} onSend={send} />
              {sendError && <p role="alert" className="mt-3 rounded-xl border border-red-400/20 bg-red-500/8 px-4 py-3 text-sm text-red-200">{sendError}</p>}
            </>
          ) : conversationId && !listLoading ? (
            <section className="rounded-3xl border border-white/10 bg-brand-surface px-5 py-14 text-center">
              <h2 className="text-xl font-black text-white">Conversation unavailable</h2>
              <p className="mt-2 text-sm text-white/45">It may not exist or may belong to another account.</p>
              <button type="button" onClick={() => navigate('/messages')} className="mt-5 min-h-11 rounded-xl border border-neon-green/35 px-4 text-sm font-bold text-neon-green">Back to messages</button>
            </section>
          ) : (
            <section className="flex min-h-[42rem] flex-col items-center justify-center rounded-3xl border border-white/10 bg-brand-surface px-8 text-center">
              <MessageIcon className="h-12 w-12 text-neon-green/60" />
              <h2 className="mt-5 text-xl font-black text-white">Choose a conversation</h2>
              <p className="mt-2 max-w-sm text-sm leading-6 text-white/40">Select an order thread to read messages or send an update.</p>
            </section>
          )}
        </div>
      </div>
    </div>
  )
}

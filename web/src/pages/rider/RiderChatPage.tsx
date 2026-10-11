import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { ChatThread } from '../../components/customer/ChatThread'
import {
  authorizeMercure,
  getRiderConversationMessages,
  isApiError,
  markRiderConversationRead,
  sendRiderConversationMessage,
} from '../../lib/api'
import type { ConversationMessage, CustomerConversation, RiderConversation } from '../../lib/api'

interface MessagePayload {
  message_id?: string
  conversation_id?: string
  sender?: { id: string; name: string }
  body?: string
  created_at?: string
}

function sharedConversation(conversation: RiderConversation): CustomerConversation {
  return { ...conversation, counterpart: { ...conversation.counterpart, avatar_path: null } }
}

export function RiderChatPage() {
  const { conversationId } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [conversation, setConversation] = useState<RiderConversation | null>(null)
  const [messages, setMessages] = useState<ConversationMessage[]>([])
  const [loading, setLoading] = useState(true)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState('')
  const [sendError, setSendError] = useState('')
  const [connection, setConnection] = useState<'connecting' | 'live' | 'disconnected'>('connecting')
  const sourceRef = useRef<EventSource | null>(null)

  const load = useCallback(async (signal?: AbortSignal) => {
    if (!conversationId) return
    try {
      const response = await getRiderConversationMessages(conversationId, signal)
      setConversation(response.conversation)
      setMessages(response.data)
      setError('')
      void markRiderConversationRead(conversationId)
    } catch (requestError) {
      if (!signal?.aborted) setError(isApiError(requestError) ? requestError.message : 'Unable to load this conversation.')
    } finally {
      if (!signal?.aborted) setLoading(false)
    }
  }, [conversationId])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => void load(controller.signal))
    return () => controller.abort()
  }, [load])

  useEffect(() => {
    if (!conversationId) return
    let cancelled = false
    let source: EventSource | null = null
    void authorizeMercure().then((authorization) => {
      if (cancelled) return
      const topic = `conversations/${conversationId}`
      if (!authorization.topics.includes(topic)) throw new Error('Conversation topic is not authorized.')
      const url = new URL(authorization.hub_url)
      url.searchParams.append(authorization.subscription_parameter || 'topic', topic)
      source = new EventSource(url.toString(), { withCredentials: true })
      sourceRef.current = source
      source.onopen = () => setConnection('live')
      source.onerror = () => setConnection('disconnected')
      source.addEventListener('message.sent', (rawEvent) => {
        try {
          const payload = JSON.parse((rawEvent as MessageEvent<string>).data) as MessagePayload
          if (payload.conversation_id !== conversationId || !payload.message_id || !payload.sender || !payload.body || !payload.created_at) return
          const message: ConversationMessage = { id: payload.message_id, conversation_id: conversationId, sender: payload.sender, body: payload.body, read_at: null, created_at: payload.created_at }
          setMessages((current) => current.some((entry) => entry.id === message.id) ? current : [...current, message])
          if (payload.sender.id !== user?.id) void markRiderConversationRead(conversationId)
        } catch {
          setConnection('disconnected')
        }
      })
    }).catch(() => { if (!cancelled) setConnection('disconnected') })
    return () => {
      cancelled = true
      source?.close()
      if (sourceRef.current === source) sourceRef.current = null
    }
  }, [conversationId, user?.id])

  async function send(body: string): Promise<boolean> {
    if (!conversationId) return false
    setSending(true)
    setSendError('')
    try {
      const response = await sendRiderConversationMessage(conversationId, body)
      setMessages((current) => current.some((entry) => entry.id === response.message.id) ? current : [...current, response.message])
      return true
    } catch (requestError) {
      setSendError(isApiError(requestError) ? requestError.message : 'Your message could not be sent.')
      return false
    } finally {
      setSending(false)
    }
  }

  if (!conversation && !loading) return <section className="rounded-3xl border border-red-400/20 bg-brand-surface px-5 py-12 text-center"><p className="text-sm text-red-200">{error || 'Conversation unavailable.'}</p><button type="button" onClick={() => navigate('/rider/active')} className="mt-5 min-h-11 rounded-xl bg-neon-green px-4 text-sm font-black text-brand-black">Back to delivery</button></section>

  return <div><button type="button" onClick={() => navigate('/rider/active')} className="mb-4 min-h-11 text-sm font-bold text-neon-green">← Active delivery</button>{conversation ? <ChatThread conversation={sharedConversation(conversation)} messages={messages} currentUserId={user?.id ?? ''} loading={loading} sending={sending} connection={connection} error={error} onBack={() => navigate('/rider/active')} onRetry={() => { setLoading(true); void load() }} onSend={send} /> : <div className="h-[65svh] animate-pulse rounded-3xl bg-brand-surface"/>}{sendError && <p role="alert" className="mt-3 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200">{sendError}</p>}</div>
}

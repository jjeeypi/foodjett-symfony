import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  apiAssetUrl,
  approveAdminRestaurant,
  approveAdminRider,
  getPendingAdminRestaurants,
  getPendingAdminRiders,
  isApiError,
  rejectAdminRestaurant,
  rejectAdminRestaurantDocument,
  rejectAdminRider,
  rejectAdminRiderDocument,
  verifyAdminRestaurantDocument,
  verifyAdminRiderDocument,
} from '../../lib/api'
import type {
  AdminApprovalDocument,
  AdminRestaurantApplication,
  AdminRiderApplication,
  ApprovalStatus,
  DocumentStatus,
} from '../../lib/api'

type ApplicantKind = 'restaurant' | 'rider'
type SelectedApplicant =
  | { kind: 'restaurant'; application: AdminRestaurantApplication }
  | { kind: 'rider'; application: AdminRiderApplication }
type RejectTarget =
  | { scope: 'application'; applicant: SelectedApplicant }
  | { scope: 'document'; applicant: SelectedApplicant; document: AdminApprovalDocument }

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
  dateStyle: 'medium',
  timeStyle: 'short',
})

function formatDate(value: string | null): string {
  if (!value) return 'Not recorded'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Not recorded' : dateFormatter.format(date)
}

function humanize(value: string | null | undefined): string {
  if (!value) return 'Not provided'
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

export function AdminApprovalsPage() {
  const navigate = useNavigate()
  const params = useParams<{ kind?: string; id?: string }>()
  const routeKind: ApplicantKind | null = params.kind === 'restaurant' || params.kind === 'rider' ? params.kind : null
  const [tab, setTab] = useState<ApplicantKind>(routeKind ?? 'restaurant')
  const [restaurants, setRestaurants] = useState<AdminRestaurantApplication[]>([])
  const [riders, setRiders] = useState<AdminRiderApplication[]>([])
  const [restaurantTotal, setRestaurantTotal] = useState(0)
  const [riderTotal, setRiderTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')
  const [actionError, setActionError] = useState('')
  const [notice, setNotice] = useState('')
  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [approvalTarget, setApprovalTarget] = useState<SelectedApplicant | null>(null)
  const [rejectTarget, setRejectTarget] = useState<RejectTarget | null>(null)
  const [rejectionReason, setRejectionReason] = useState('')

  const loadApplications = useCallback(async (signal?: AbortSignal, refresh = false) => {
    if (refresh) setRefreshing(true)
    setError('')
    try {
      const [restaurantResponse, riderResponse] = await Promise.all([
        getPendingAdminRestaurants(signal),
        getPendingAdminRiders(signal),
      ])
      setRestaurants(restaurantResponse.data)
      setRiders(riderResponse.data)
      setRestaurantTotal(restaurantResponse.meta.total)
      setRiderTotal(riderResponse.meta.total)
    } catch (requestError) {
      if (signal?.aborted) return
      setError(isApiError(requestError) ? requestError.message : 'Unable to load pending applications.')
    } finally {
      if (!signal?.aborted) {
        setLoading(false)
        setRefreshing(false)
      }
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => void loadApplications(controller.signal))
    return () => controller.abort()
  }, [loadApplications])

  const activeTab = routeKind ?? tab

  const selected = useMemo<SelectedApplicant | null>(() => {
    if (!routeKind || !params.id) return null
    if (routeKind === 'restaurant') {
      const application = restaurants.find((entry) => entry.id === params.id)
      return application ? { kind: 'restaurant', application } : null
    }
    const application = riders.find((entry) => entry.id === params.id)
    return application ? { kind: 'rider', application } : null
  }, [params.id, restaurants, riders, routeKind])

  const routeSelectionMissing = !loading && Boolean(routeKind && params.id && !selected)

  function chooseTab(kind: ApplicantKind) {
    setTab(kind)
    setActionError('')
    setNotice('')
    if (params.id) navigate('/admin/approvals')
  }

  function openApplicant(kind: ApplicantKind, id: string) {
    navigate(`/admin/approvals/${kind}/${encodeURIComponent(id)}`)
  }

  function removeApplicant(applicant: SelectedApplicant) {
    if (applicant.kind === 'restaurant') {
      setRestaurants((current) => current.filter((entry) => entry.id !== applicant.application.id))
      setRestaurantTotal((current) => Math.max(0, current - 1))
    } else {
      setRiders((current) => current.filter((entry) => entry.id !== applicant.application.id))
      setRiderTotal((current) => Math.max(0, current - 1))
    }
  }

  async function confirmApproval() {
    if (!approvalTarget || busyKey) return
    const key = `approve-${approvalTarget.kind}-${approvalTarget.application.id}`
    setBusyKey(key)
    setActionError('')
    try {
      if (approvalTarget.kind === 'restaurant') await approveAdminRestaurant(approvalTarget.application.id)
      else await approveAdminRider(approvalTarget.application.id)
      const label = approvalTarget.kind === 'restaurant' ? approvalTarget.application.name : approvalTarget.application.owner.name
      removeApplicant(approvalTarget)
      setNotice(`${label} was approved and can now sign in.`)
      setApprovalTarget(null)
      navigate('/admin/approvals', { replace: true })
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'The application could not be approved.')
      setApprovalTarget(null)
    } finally {
      setBusyKey(null)
    }
  }

  function openReject(target: RejectTarget) {
    setRejectTarget(target)
    setRejectionReason('')
    setActionError('')
  }

  async function confirmRejection() {
    if (!rejectTarget || busyKey) return
    const reason = rejectionReason.trim()
    if (!reason || reason.length > 2000) {
      setActionError('Enter a rejection reason of no more than 2,000 characters.')
      return
    }
    const key = `reject-${rejectTarget.scope}-${rejectTarget.applicant.kind}-${rejectTarget.scope === 'document' ? rejectTarget.document.id : rejectTarget.applicant.application.id}`
    setBusyKey(key)
    setActionError('')
    try {
      if (rejectTarget.scope === 'application') {
        if (rejectTarget.applicant.kind === 'restaurant') await rejectAdminRestaurant(rejectTarget.applicant.application.id, reason)
        else await rejectAdminRider(rejectTarget.applicant.application.id, reason)
        const label = rejectTarget.applicant.kind === 'restaurant' ? rejectTarget.applicant.application.name : rejectTarget.applicant.application.owner.name
        removeApplicant(rejectTarget.applicant)
        setNotice(`${label}'s application was rejected.`)
        navigate('/admin/approvals', { replace: true })
      } else {
        const response = rejectTarget.applicant.kind === 'restaurant'
          ? await rejectAdminRestaurantDocument(rejectTarget.document.id, reason)
          : await rejectAdminRiderDocument(rejectTarget.document.id, reason)
        replaceDocument(rejectTarget.applicant.kind, rejectTarget.applicant.application.id, response.document)
        setNotice(`${humanize(rejectTarget.document.type)} was marked rejected.`)
      }
      setRejectTarget(null)
      setRejectionReason('')
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'The rejection could not be saved.')
    } finally {
      setBusyKey(null)
    }
  }

  function replaceDocument(kind: ApplicantKind, applicantId: string, document: AdminApprovalDocument) {
    if (kind === 'restaurant') {
      setRestaurants((current) => current.map((entry) => entry.id === applicantId
        ? { ...entry, documents: entry.documents.map((item) => item.id === document.id ? document : item) }
        : entry))
    } else {
      setRiders((current) => current.map((entry) => entry.id === applicantId
        ? { ...entry, documents: entry.documents.map((item) => item.id === document.id ? document : item) }
        : entry))
    }
  }

  async function verifyDocument(applicant: SelectedApplicant, document: AdminApprovalDocument) {
    if (busyKey) return
    const key = `verify-${applicant.kind}-${document.id}`
    setBusyKey(key)
    setActionError('')
    try {
      const response = applicant.kind === 'restaurant'
        ? await verifyAdminRestaurantDocument(document.id)
        : await verifyAdminRiderDocument(document.id)
      replaceDocument(applicant.kind, applicant.application.id, response.document)
      setNotice(`${humanize(document.type)} was verified.`)
    } catch (requestError) {
      setActionError(isApiError(requestError) ? requestError.message : 'The document could not be verified.')
    } finally {
      setBusyKey(null)
    }
  }

  const visibleApplications: SelectedApplicant[] = activeTab === 'restaurant'
    ? restaurants.map((application) => ({ kind: 'restaurant' as const, application }))
    : riders.map((application) => ({ kind: 'rider' as const, application }))

  return (
    <div>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-xs font-black uppercase tracking-[0.2em] text-neon-green">Account review</p>
          <h2 className="mt-2 text-3xl font-black tracking-tight text-white sm:text-4xl">Pending approvals</h2>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-white/45">Review applicant details and documents before granting platform access.</p>
        </div>
        <button type="button" disabled={loading || refreshing} onClick={() => void loadApplications(undefined, true)} className="min-h-11 w-full rounded-xl border border-white/15 px-4 text-sm font-bold text-white/70 transition hover:border-neon-green/30 hover:text-neon-green disabled:cursor-wait disabled:opacity-50 sm:w-auto">
          {refreshing ? 'Refreshing…' : 'Refresh queue'}
        </button>
      </div>

      {notice && <div role="status" className="mt-5 flex items-center justify-between gap-3 rounded-xl border border-neon-green/25 bg-neon-green/[0.07] px-4 py-3 text-sm font-semibold text-neon-green"><span>{notice}</span><button type="button" onClick={() => setNotice('')} className="min-h-9 rounded-lg px-2 text-xs hover:bg-neon-green/10">Dismiss</button></div>}
      {actionError && <div role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.07] px-4 py-3 text-sm text-red-200">{actionError}</div>}

      <div className="mt-6 grid grid-cols-2 gap-2 rounded-xl border border-white/10 bg-brand-surface p-1 sm:w-fit sm:min-w-[25rem]">
        <TabButton active={activeTab === 'restaurant'} count={restaurantTotal} onClick={() => chooseTab('restaurant')}>Pending restaurants</TabButton>
        <TabButton active={activeTab === 'rider'} count={riderTotal} onClick={() => chooseTab('rider')}>Pending riders</TabButton>
      </div>

      {loading ? <ApprovalSkeleton /> : error ? (
        <section className="mt-6 rounded-2xl border border-red-400/20 bg-brand-surface px-5 py-12 text-center">
          <p className="text-sm text-red-200">{error}</p>
          <button type="button" onClick={() => { setLoading(true); void loadApplications() }} className="mt-4 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Retry</button>
        </section>
      ) : (
        <div className="mt-6 grid items-start gap-5 lg:grid-cols-[22rem_minmax(0,1fr)]">
          <section aria-label={`${humanize(activeTab)} applications`} className={selected ? 'hidden lg:block' : ''}>
            {visibleApplications.length > 0 ? (
              <div className="space-y-3">
                {visibleApplications.map((applicant) => (
                  <ApplicantListCard key={`${applicant.kind}-${applicant.application.id}`} applicant={applicant} selected={selected?.kind === applicant.kind && selected.application.id === applicant.application.id} onClick={() => openApplicant(applicant.kind, applicant.application.id)} />
                ))}
              </div>
            ) : <EmptyQueue kind={activeTab} />}
          </section>

          <section className={!selected && !routeSelectionMissing ? 'hidden lg:block' : ''}>
            {selected ? (
              <ApplicationDetail
                applicant={selected}
                busyKey={busyKey}
                onBack={() => navigate('/admin/approvals')}
                onApprove={() => { setActionError(''); setApprovalTarget(selected) }}
                onReject={() => openReject({ scope: 'application', applicant: selected })}
                onVerifyDocument={(document) => void verifyDocument(selected, document)}
                onRejectDocument={(document) => openReject({ scope: 'document', applicant: selected, document })}
              />
            ) : routeSelectionMissing ? (
              <div className="rounded-2xl border border-amber-300/20 bg-amber-300/[0.05] px-5 py-12 text-center">
                <h3 className="text-lg font-black text-white">Application is no longer pending</h3>
                <p className="mt-2 text-sm text-white/45">It may already have been approved or rejected. Refresh the queue to confirm.</p>
                <button type="button" onClick={() => navigate('/admin/approvals')} className="mt-5 min-h-11 rounded-xl bg-neon-green px-5 text-sm font-black text-brand-black">Back to queue</button>
              </div>
            ) : (
              <div className="flex min-h-[30rem] items-center justify-center rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 px-6 text-center">
                <div><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neon-green/[0.08] text-2xl text-neon-green">✓</span><h3 className="mt-4 text-lg font-black text-white">Select an application</h3><p className="mt-2 max-w-sm text-sm leading-6 text-white/40">Choose an applicant to review their full profile and uploaded documents.</p></div>
              </div>
            )}
          </section>
        </div>
      )}

      {approvalTarget && (
        <DecisionDialog
          title={`Approve ${approvalTarget.kind}?`}
          description="This changes the real approval state and allows this account to sign in. Document statuses remain independent."
          confirmLabel="Approve application"
          busy={Boolean(busyKey)}
          tone="approve"
          onCancel={() => setApprovalTarget(null)}
          onConfirm={() => void confirmApproval()}
        />
      )}

      {rejectTarget && (
        <RejectDialog
          targetLabel={rejectTarget.scope === 'document' ? humanize(rejectTarget.document.type) : rejectTarget.applicant.kind === 'restaurant' ? rejectTarget.applicant.application.name : rejectTarget.applicant.application.owner.name}
          value={rejectionReason}
          busy={Boolean(busyKey)}
          error={actionError}
          onChange={(value) => { setRejectionReason(value); setActionError('') }}
          onCancel={() => { setRejectTarget(null); setActionError('') }}
          onConfirm={() => void confirmRejection()}
        />
      )}
    </div>
  )
}

function TabButton({ active, count, onClick, children }: { active: boolean; count: number; onClick: () => void; children: string }) {
  return <button type="button" onClick={onClick} className={`min-h-11 rounded-lg px-3 text-xs font-black transition sm:text-sm ${active ? 'bg-neon-green text-brand-black' : 'text-white/45 hover:text-white'}`}>{children}<span className={`ml-2 rounded-full px-2 py-0.5 text-[11px] ${active ? 'bg-brand-black/15' : 'bg-white/[0.06]'}`}>{count}</span></button>
}

function ApplicantListCard({ applicant, selected, onClick }: { applicant: SelectedApplicant; selected: boolean; onClick: () => void }) {
  const name = applicant.kind === 'restaurant' ? applicant.application.name : applicant.application.owner.name
  const subtitle = applicant.kind === 'restaurant' ? applicant.application.owner.name : `${humanize(applicant.application.vehicle_type)} · ${applicant.application.plate_number ?? 'No plate'}`
  const verified = applicant.application.documents.filter((document) => document.status === 'verified').length
  return (
    <button type="button" onClick={onClick} className={`w-full rounded-2xl border p-4 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neon-green ${selected ? 'border-neon-green/40 bg-neon-green/[0.07]' : 'border-white/10 bg-brand-surface hover:border-white/20'}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0"><p className="truncate font-black text-white">{name}</p><p className="mt-1 truncate text-xs text-white/45">{subtitle}</p></div>
        <StatusBadge status={applicant.application.approval_status} />
      </div>
      <div className="mt-4 flex items-center justify-between gap-3 text-[11px] text-white/35">
        <span>{formatDate(applicant.application.created_at)}</span>
        <span>{verified}/{applicant.application.documents.length} docs verified</span>
      </div>
    </button>
  )
}

function ApplicationDetail({ applicant, busyKey, onBack, onApprove, onReject, onVerifyDocument, onRejectDocument }: {
  applicant: SelectedApplicant
  busyKey: string | null
  onBack: () => void
  onApprove: () => void
  onReject: () => void
  onVerifyDocument: (document: AdminApprovalDocument) => void
  onRejectDocument: (document: AdminApprovalDocument) => void
}) {
  const application = applicant.application
  const restaurant = applicant.kind === 'restaurant' ? applicant.application : null
  const rider = applicant.kind === 'rider' ? applicant.application : null
  const title = restaurant ? restaurant.name : rider!.owner.name
  return (
    <article className="overflow-hidden rounded-2xl border border-white/10 bg-brand-surface">
      <header className="border-b border-white/10 px-4 py-5 sm:px-6">
        <button type="button" onClick={onBack} className="mb-4 inline-flex min-h-10 items-center gap-2 rounded-lg pr-3 text-sm font-bold text-white/50 hover:text-neon-green lg:hidden"><span aria-hidden="true">←</span> Back to queue</button>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2"><StatusBadge status={application.approval_status} /><span className="text-xs text-white/35">Submitted {formatDate(application.created_at)}</span></div>
            <h3 className="mt-3 break-words text-2xl font-black text-white sm:text-3xl">{title}</h3>
            <p className="mt-1 text-sm text-white/45">{restaurant ? `Owner: ${restaurant.owner.name}` : `${humanize(rider!.vehicle_type)} rider application`}</p>
          </div>
          <div className="grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
            <button type="button" disabled={Boolean(busyKey)} onClick={onReject} className="min-h-11 rounded-xl border border-red-400/30 px-4 text-sm font-black text-red-300 transition hover:bg-red-400/10 disabled:opacity-50">Reject</button>
            <button type="button" disabled={Boolean(busyKey)} onClick={onApprove} className="min-h-11 rounded-xl bg-neon-green px-4 text-sm font-black text-brand-black transition hover:bg-[#55ff35] disabled:opacity-50">Approve</button>
          </div>
        </div>
      </header>

      <div className="space-y-7 p-4 sm:p-6">
        <section>
          <SectionHeading>Contact information</SectionHeading>
          <dl className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <InfoCell label="Name" value={application.owner.name} />
            <InfoCell label="Email" value={application.owner.email ?? 'Not provided'} />
            <InfoCell label="Phone" value={application.owner.phone ?? 'Not provided'} />
          </dl>
        </section>

        <section>
          <SectionHeading>{applicant.kind === 'restaurant' ? 'Business details' : 'Rider details'}</SectionHeading>
          {restaurant ? (
            <dl className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              <InfoCell label="Business name" value={restaurant.name} />
              <InfoCell label="Cuisine" value={restaurant.cuisine_type ?? 'Not provided'} />
              <InfoCell label="Address" value={restaurant.address} wide />
              <InfoCell label="Coordinates" value={`${restaurant.latitude}, ${restaurant.longitude}`} />
              <InfoCell label="Minimum order" value={`₱${Number(restaurant.min_order_amount).toFixed(2)}`} />
              <InfoCell label="Default prep time" value={`${restaurant.default_prep_time_minutes} minutes`} />
              {restaurant.description && <InfoCell label="Description" value={restaurant.description} wide />}
            </dl>
          ) : (
            <dl className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              <InfoCell label="Vehicle type" value={humanize(rider!.vehicle_type)} />
              <InfoCell label="Plate number" value={rider!.plate_number ?? 'Not applicable'} />
              <InfoCell label="Availability" value={humanize(rider!.availability_status)} />
            </dl>
          )}
        </section>

        <section>
          <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"><SectionHeading>Submitted documents</SectionHeading><p className="text-xs text-white/35">Document status is reviewed independently from account approval.</p></div>
          {application.documents.length > 0 ? (
            <div className="mt-3 grid gap-4 xl:grid-cols-2">
              {application.documents.map((document) => <DocumentReviewCard key={document.id} applicant={applicant} document={document} busy={Boolean(busyKey)} onVerify={() => onVerifyDocument(document)} onReject={() => onRejectDocument(document)} />)}
            </div>
          ) : <div className="mt-3 rounded-xl border border-amber-300/20 bg-amber-300/[0.05] px-4 py-5 text-sm text-amber-100">No documents were returned for this application.</div>}
        </section>
      </div>
    </article>
  )
}

function DocumentReviewCard({ document, busy, onVerify, onReject }: { applicant: SelectedApplicant; document: AdminApprovalDocument; busy: boolean; onVerify: () => void; onReject: () => void }) {
  const url = apiAssetUrl(document.file_path)
  const image = /\.(?:jpe?g|png|gif|webp)$/i.test(document.file_path)
  const pdf = /\.pdf$/i.test(document.file_path)
  return (
    <article className="overflow-hidden rounded-xl border border-white/10 bg-brand-black/45">
      <div className="flex min-h-44 items-center justify-center border-b border-white/10 bg-black/40 p-3">
        {url && image ? <a href={url} target="_blank" rel="noreferrer" className="block w-full"><img src={url} alt={`${humanize(document.type)} preview`} loading="lazy" className="mx-auto max-h-64 w-full rounded-lg object-contain" /></a> : (
          <div className="text-center"><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04] text-xl font-black text-neon-green">{pdf ? 'PDF' : 'FILE'}</span>{url && <a href={url} target="_blank" rel="noreferrer" className="mt-3 inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-bold text-neon-green hover:bg-neon-green/10">Open {pdf ? 'PDF' : 'document'} ↗</a>}</div>
        )}
      </div>
      <div className="p-4">
        <div className="flex items-start justify-between gap-3"><div><h5 className="font-black text-white">{humanize(document.type)}</h5><p className="mt-1 break-all text-[11px] text-white/30">{document.file_path.split('/').at(-1)}</p></div><DocumentStatusBadge status={document.status} /></div>
        {document.rejection_reason && <p className="mt-3 rounded-lg bg-red-400/[0.07] px-3 py-2 text-xs leading-5 text-red-200">{document.rejection_reason}</p>}
        <div className="mt-4 grid grid-cols-2 gap-2">
          <button type="button" disabled={busy || document.status === 'rejected'} onClick={onReject} className="min-h-11 rounded-lg border border-red-400/25 text-xs font-black text-red-300 disabled:cursor-not-allowed disabled:opacity-35">Reject</button>
          <button type="button" disabled={busy || document.status === 'verified'} onClick={onVerify} className="min-h-11 rounded-lg border border-neon-green/30 bg-neon-green/[0.07] text-xs font-black text-neon-green disabled:cursor-not-allowed disabled:opacity-35">Verify</button>
        </div>
      </div>
    </article>
  )
}

function SectionHeading({ children }: { children: string }) {
  return <h4 className="text-xs font-black uppercase tracking-[0.16em] text-white/55">{children}</h4>
}

function InfoCell({ label, value, wide = false }: { label: string; value: string; wide?: boolean }) {
  return <div className={`rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 ${wide ? 'sm:col-span-2 xl:col-span-3' : ''}`}><dt className="text-[10px] font-black uppercase tracking-[0.13em] text-white/30">{label}</dt><dd className="mt-1.5 break-words text-sm font-semibold leading-6 text-white/80">{value}</dd></div>
}

function StatusBadge({ status }: { status: ApprovalStatus }) {
  const style = status === 'approved' ? 'border-neon-green/25 bg-neon-green/[0.08] text-neon-green' : status === 'rejected' ? 'border-red-400/25 bg-red-400/[0.08] text-red-300' : 'border-amber-300/25 bg-amber-300/[0.08] text-amber-200'
  return <span className={`inline-flex shrink-0 rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ${style}`}>{status}</span>
}

function DocumentStatusBadge({ status }: { status: DocumentStatus }) {
  const style = status === 'verified' ? 'bg-neon-green/[0.08] text-neon-green' : status === 'rejected' ? 'bg-red-400/[0.08] text-red-300' : 'bg-amber-300/[0.08] text-amber-200'
  return <span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ${style}`}>{status}</span>
}

function EmptyQueue({ kind }: { kind: ApplicantKind }) {
  return <div className="rounded-2xl border border-dashed border-white/10 bg-brand-surface/50 px-5 py-14 text-center"><span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-neon-green/[0.08] text-xl text-neon-green">✓</span><h3 className="mt-4 font-black text-white">No pending applications</h3><p className="mt-2 text-sm leading-6 text-white/40">There are no {kind} applications waiting for review.</p></div>
}

function ApprovalSkeleton() {
  return <div className="mt-6 grid gap-5 lg:grid-cols-[22rem_minmax(0,1fr)]"><div className="space-y-3">{[0, 1, 2].map((item) => <div key={item} className="h-32 animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" />)}</div><div className="h-[34rem] animate-pulse rounded-2xl border border-white/[0.06] bg-brand-surface" /></div>
}

function DecisionDialog({ title, description, confirmLabel, busy, tone, onCancel, onConfirm }: { title: string; description: string; confirmLabel: string; busy: boolean; tone: 'approve' | 'reject'; onCancel: () => void; onConfirm: () => void }) {
  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onCancel() }}>
      <section role="dialog" aria-modal="true" aria-labelledby="decision-title" className="w-full max-w-md rounded-2xl border border-white/10 bg-brand-surface p-5 shadow-2xl sm:p-6">
        <h3 id="decision-title" className="text-xl font-black text-white">{title}</h3><p className="mt-2 text-sm leading-6 text-white/45">{description}</p>
        <div className="mt-6 grid grid-cols-2 gap-3"><button type="button" disabled={busy} onClick={onCancel} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65 disabled:opacity-50">Cancel</button><button type="button" disabled={busy} onClick={onConfirm} className={`min-h-12 rounded-xl text-sm font-black text-brand-black disabled:opacity-50 ${tone === 'approve' ? 'bg-neon-green' : 'bg-red-400'}`}>{busy ? 'Saving…' : confirmLabel}</button></div>
      </section>
    </div>
  )
}

function RejectDialog({ targetLabel, value, busy, error, onChange, onCancel, onConfirm }: { targetLabel: string; value: string; busy: boolean; error: string; onChange: (value: string) => void; onCancel: () => void; onConfirm: () => void }) {
  return (
    <div className="fixed inset-0 z-[70] flex items-end justify-center bg-black/80 p-3 backdrop-blur-sm sm:items-center" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onCancel() }}>
      <section role="dialog" aria-modal="true" aria-labelledby="reject-title" className="w-full max-w-lg rounded-2xl border border-white/10 bg-brand-surface p-5 shadow-2xl sm:p-6">
        <p className="text-xs font-black uppercase tracking-[0.16em] text-red-300">Rejection reason required</p><h3 id="reject-title" className="mt-2 text-xl font-black text-white">Reject {targetLabel}?</h3><p className="mt-2 text-sm leading-6 text-white/45">This reason is stored with the record and can be shown to the applicant.</p>
        <textarea autoFocus value={value} onChange={(event) => onChange(event.target.value)} maxLength={2000} rows={5} placeholder="Explain what needs to be corrected" className="mt-5 w-full resize-none rounded-xl border border-white/10 bg-brand-black px-3 py-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-red-400 focus:ring-2 focus:ring-red-400/15" />
        <div className="mt-1 flex justify-between text-[11px] text-white/30"><span>{error || 'Be specific so the applicant can correct it.'}</span><span>{value.length}/2000</span></div>
        <div className="mt-6 grid grid-cols-2 gap-3"><button type="button" disabled={busy} onClick={onCancel} className="min-h-12 rounded-xl border border-white/15 text-sm font-bold text-white/65 disabled:opacity-50">Cancel</button><button type="button" disabled={busy || !value.trim()} onClick={onConfirm} className="min-h-12 rounded-xl bg-red-400 text-sm font-black text-brand-black disabled:opacity-50">{busy ? 'Saving…' : 'Reject'}</button></div>
      </section>
    </div>
  )
}

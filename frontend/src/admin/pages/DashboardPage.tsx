import { useEffect, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Activity,
  AlertTriangle,
  CheckCircle2Icon,
  Clock,
  Database,
  RefreshCw,
  TrendingUp,
  Upload,
  XCircle,
} from 'lucide-react'
import { adminFetch } from '../lib/adminFetch'

type ScraperRun = {
  source_site_key: string
  status: string
  started_at: string
  finished_at: string | null
  items_found: number
  items_upserted: number
}

type SourceStat = {
  source_site_name: string
  total: number
}

type AnalyticsPayload = {
  total_projects: number
  featured_projects: number
  sources: number
  projects_added_7d: number
  closing_soon_7d: number
  last_run_at: string | null
  successful_runs_24h: number
  failed_runs_24h: number
  success_rate_24h: number
  total_items_found_24h: number
  total_items_upserted_24h: number
  upsert_rate_24h: number
  recent_runs: ScraperRun[]
  projects_by_source: SourceStat[]
}

const emptyAnalytics: AnalyticsPayload = {
  total_projects: 0,
  featured_projects: 0,
  sources: 0,
  projects_added_7d: 0,
  closing_soon_7d: 0,
  last_run_at: null,
  successful_runs_24h: 0,
  failed_runs_24h: 0,
  success_rate_24h: 0,
  total_items_found_24h: 0,
  total_items_upserted_24h: 0,
  upsert_rate_24h: 0,
  recent_runs: [],
  projects_by_source: [],
}

/* ── micro-animation variants ─────────────────────────────────────── */
const container = {
  hidden: { opacity: 0 },
  show: {
    opacity: 1,
    transition: { staggerChildren: 0.06 },
  },
}

const fadeUp = {
  hidden: { opacity: 0, y: 16 },
  show: { opacity: 1, y: 0, transition: { duration: 0.35 } },
}

/* ── skeleton shell ───────────────────────────────────────────────── */
function DashboardSkeleton() {
  return (
    <div className="space-y-8 px-6 pb-8 pt-4">
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Card key={`stat-sk-${i}`}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="h-4 w-4 rounded" />
            </CardHeader>
            <CardContent className="space-y-2">
              <Skeleton className="h-8 w-20" />
              <Skeleton className="h-3 w-28" />
            </CardContent>
          </Card>
        ))}
      </div>
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-3 w-56" />
        </CardHeader>
        <CardContent className="space-y-4">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={`src-sk-${i}`} className="flex items-center gap-3">
              <Skeleton className="h-4 flex-1" />
              <Skeleton className="h-5 w-14 rounded-full" />
            </div>
          ))}
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-3 w-56" />
        </CardHeader>
        <CardContent className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={`run-sk-${i}`} className="h-16 w-full rounded-lg" />
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

/* ── stat cards ────────────────────────────────────────────────────── */
const statCards = [
  {
    key: 'total',
    label: 'Total Projects',
    desc: 'All scraped projects',
    icon: Database,
    color: 'text-blue-600',
    bgAccent: 'bg-blue-50',
    getValue: (a: AnalyticsPayload) => a.total_projects,
  },
  {
    key: 'featured',
    label: 'Featured Projects',
    desc: 'Shown on homepage',
    icon: CheckCircle2Icon,
    color: 'text-emerald-600',
    bgAccent: 'bg-emerald-50',
    getValue: (a: AnalyticsPayload) => a.featured_projects,
  },
  {
    key: 'sources',
    label: 'Active Sources',
    desc: 'Data sources configured',
    icon: RefreshCw,
    color: 'text-violet-600',
    bgAccent: 'bg-violet-50',
    getValue: (a: AnalyticsPayload) => a.sources,
  },
  {
    key: 'runs',
    label: 'Recent Runs',
    desc: 'Last 10 scraper runs',
    icon: Clock,
    color: 'text-amber-600',
    bgAccent: 'bg-amber-50',
    getValue: (a: AnalyticsPayload) => a.recent_runs.length,
  },
  {
    key: 'added7d',
    label: 'Added (7d)',
    desc: 'Newly ingested projects',
    icon: Upload,
    color: 'text-cyan-600',
    bgAccent: 'bg-cyan-50',
    getValue: (a: AnalyticsPayload) => a.projects_added_7d,
  },
  {
    key: 'closing7d',
    label: 'Closing Soon (7d)',
    desc: 'Projects nearing deadline',
    icon: AlertTriangle,
    color: 'text-rose-600',
    bgAccent: 'bg-rose-50',
    getValue: (a: AnalyticsPayload) => a.closing_soon_7d,
  },
] as const

export default function DashboardPage() {
  const [analytics, setAnalytics] = useState<AnalyticsPayload | null>(null)
  const [isLoading, setIsLoading] = useState(false)

  useEffect(() => {
    const controller = new AbortController()

    const load = async () => {
      setIsLoading(true)
      try {
        const response = await adminFetch('/admin/analytics', {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Failed to load analytics')
        const payload = await response.json()
        setAnalytics(payload?.data ?? emptyAnalytics)
      } catch {
        setAnalytics(emptyAnalytics)
      } finally {
        setIsLoading(false)
      }
    }

    load()

    return () => controller.abort()
  }, [])

  if (isLoading || !analytics) {
    return <DashboardSkeleton />
  }

  const maxSource = Math.max(...analytics.projects_by_source.map((s) => s.total), 1)

  return (
    <TooltipProvider delayDuration={200}>
      <motion.div
        className="space-y-8 px-6 pb-8 pt-4"
        variants={container}
        initial="hidden"
        animate="show"
      >
        {/* ── Stats Overview ──────────────────────────────────────── */}
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {statCards.map((s) => (
            <motion.div key={s.key} variants={fadeUp}>
              <Card className="transition-shadow hover:shadow-md">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    {s.label}
                  </CardTitle>
                  <span className={`rounded-lg p-2 ${s.bgAccent}`}>
                    <s.icon className={`h-4 w-4 ${s.color}`} />
                  </span>
                </CardHeader>
                <CardContent>
                  <div className="text-3xl font-bold tracking-tight">
                    {s.getValue(analytics).toLocaleString()}
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">{s.desc}</p>
                </CardContent>
              </Card>
            </motion.div>
          ))}
        </div>

        {/* ── Modern report cards ─────────────────────────────────── */}
        <div className="grid gap-4 lg:grid-cols-3">
          <motion.div variants={fadeUp}>
            <Card className="h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Activity className="h-4 w-4 text-emerald-600" />
                  Run Health (24h)
                </CardTitle>
                <CardDescription>Operational quality of scraper jobs</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-center justify-between rounded-lg border border-emerald-100 bg-emerald-50/60 px-3 py-2 text-sm">
                  <span className="text-emerald-700">Successful runs</span>
                  <span className="font-semibold text-emerald-700">
                    {analytics.successful_runs_24h}
                  </span>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-rose-100 bg-rose-50/60 px-3 py-2 text-sm">
                  <span className="text-rose-700">Failed runs</span>
                  <span className="font-semibold text-rose-700">
                    {analytics.failed_runs_24h}
                  </span>
                </div>
                <div>
                  <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                    <span>Success rate</span>
                    <span className="font-semibold text-slate-700">
                      {analytics.success_rate_24h}%
                    </span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <motion.div
                      className="h-full rounded-full bg-gradient-to-r from-emerald-500 to-emerald-600"
                      initial={{ width: 0 }}
                      animate={{ width: `${Math.min(100, Math.max(0, analytics.success_rate_24h))}%` }}
                      transition={{ duration: 0.5, ease: 'easeOut' }}
                    />
                  </div>
                </div>
              </CardContent>
            </Card>
          </motion.div>

          <motion.div variants={fadeUp}>
            <Card className="h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Database className="h-4 w-4 text-blue-600" />
                  Ingestion Efficiency (24h)
                </CardTitle>
                <CardDescription>From discovered records to saved records</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                  <span className="text-muted-foreground">Items found</span>
                  <span className="font-semibold text-slate-800">
                    {analytics.total_items_found_24h.toLocaleString()}
                  </span>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                  <span className="text-muted-foreground">Items upserted</span>
                  <span className="font-semibold text-slate-800">
                    {analytics.total_items_upserted_24h.toLocaleString()}
                  </span>
                </div>
                <div>
                  <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                    <span>Upsert rate</span>
                    <span className="font-semibold text-slate-700">
                      {analytics.upsert_rate_24h}%
                    </span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <motion.div
                      className="h-full rounded-full bg-gradient-to-r from-blue-500 to-blue-600"
                      initial={{ width: 0 }}
                      animate={{ width: `${Math.min(100, Math.max(0, analytics.upsert_rate_24h))}%` }}
                      transition={{ duration: 0.5, ease: 'easeOut' }}
                    />
                  </div>
                </div>
              </CardContent>
            </Card>
          </motion.div>

          <motion.div variants={fadeUp}>
            <Card className="h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Clock className="h-4 w-4 text-violet-600" />
                  Data Freshness
                </CardTitle>
                <CardDescription>Recency and near-term project pressure</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="rounded-lg border border-violet-100 bg-violet-50/60 px-3 py-2">
                  <p className="text-xs text-violet-700">Last scraper run</p>
                  <p className="mt-0.5 text-sm font-semibold text-violet-900">
                    {analytics.last_run_at
                      ? new Date(analytics.last_run_at).toLocaleString()
                      : 'No runs yet'}
                  </p>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                  <span className="text-muted-foreground">Projects added (7d)</span>
                  <span className="font-semibold text-slate-800">
                    {analytics.projects_added_7d.toLocaleString()}
                  </span>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                  <span className="text-muted-foreground">Closing soon (7d)</span>
                  <span className="font-semibold text-slate-800">
                    {analytics.closing_soon_7d.toLocaleString()}
                  </span>
                </div>
              </CardContent>
            </Card>
          </motion.div>
        </div>

        {/* ── Projects by Source ───────────────────────────────────── */}
        <motion.div variants={fadeUp}>
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <TrendingUp className="h-5 w-5 text-muted-foreground" />
                <div>
                  <CardTitle>Projects by Source</CardTitle>
                  <CardDescription>
                    Distribution of projects across all sources
                  </CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {analytics.projects_by_source.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                  No analytics yet. Run a scraper to populate data.
                </p>
              ) : (
                <div className="space-y-3">
                  <AnimatePresence>
                    {analytics.projects_by_source.map((source, idx) => {
                      const pct = Math.round((source.total / maxSource) * 100)
                      return (
                        <motion.div
                          key={source.source_site_name}
                          initial={{ opacity: 0, x: -8 }}
                          animate={{ opacity: 1, x: 0 }}
                          transition={{ delay: idx * 0.04, duration: 0.3 }}
                          className="group"
                        >
                          <div className="flex items-center justify-between text-sm">
                            <span className="font-medium">{source.source_site_name}</span>
                            <span className="tabular-nums font-semibold text-primary">
                              {source.total.toLocaleString()}
                            </span>
                          </div>
                          <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
                            <motion.div
                              className="h-full rounded-full bg-gradient-to-r from-blue-500 to-blue-600"
                              initial={{ width: 0 }}
                              animate={{ width: `${pct}%` }}
                              transition={{
                                duration: 0.6,
                                delay: idx * 0.05,
                                ease: 'easeOut',
                              }}
                            />
                          </div>
                        </motion.div>
                      )
                    })}
                  </AnimatePresence>
                </div>
              )}
            </CardContent>
          </Card>
        </motion.div>

        {/* ── Recent Scraper Runs ──────────────────────────────────── */}
        <motion.div variants={fadeUp}>
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <RefreshCw className="h-5 w-5 text-muted-foreground" />
                <div>
                  <CardTitle>Recent Scraper Runs</CardTitle>
                  <CardDescription>Last 10 scraping operations</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {analytics.recent_runs.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                  No recent runs. Trigger a scraper to see activity.
                </p>
              ) : (
                <div className="space-y-3">
                  <AnimatePresence>
                    {analytics.recent_runs.map((run, idx) => (
                      <motion.div
                        key={`${run.source_site_key}-${run.started_at}-${idx}`}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: idx * 0.04, duration: 0.25 }}
                        className="flex flex-col gap-2 rounded-lg border border-slate-100 bg-slate-50/50 p-4 transition-colors hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
                      >
                        <div className="flex-1">
                          <p className="font-semibold text-slate-800">
                            {run.source_site_key}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            {new Date(run.started_at).toLocaleString()}
                          </p>
                        </div>
                        <div className="flex items-center gap-4">
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <div className="text-center">
                                <p className="text-lg font-bold tabular-nums text-slate-700">
                                  {run.items_found.toLocaleString()}
                                </p>
                                <p className="text-[10px] uppercase tracking-wider text-muted-foreground">
                                  Found
                                </p>
                              </div>
                            </TooltipTrigger>
                            <TooltipContent>Items discovered</TooltipContent>
                          </Tooltip>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <div className="text-center">
                                <p className="text-lg font-bold tabular-nums text-slate-700">
                                  {run.items_upserted.toLocaleString()}
                                </p>
                                <p className="text-[10px] uppercase tracking-wider text-muted-foreground">
                                  Saved
                                </p>
                              </div>
                            </TooltipTrigger>
                            <TooltipContent>Items upserted to DB</TooltipContent>
                          </Tooltip>
                          <Badge
                            variant="outline"
                            className={
                              run.status === 'success'
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : run.status === 'error'
                                  ? 'border-rose-200 bg-rose-50 text-rose-700'
                                  : 'border-slate-200 bg-slate-100 text-slate-600'
                            }
                          >
                            {run.status === 'success' ? (
                              <CheckCircle2Icon className="mr-1 h-3 w-3" />
                            ) : run.status === 'error' ? (
                              <XCircle className="mr-1 h-3 w-3" />
                            ) : null}
                            {run.status}
                          </Badge>
                        </div>
                      </motion.div>
                    ))}
                  </AnimatePresence>
                </div>
              )}
            </CardContent>
          </Card>
        </motion.div>
      </motion.div>
    </TooltipProvider>
  )
}

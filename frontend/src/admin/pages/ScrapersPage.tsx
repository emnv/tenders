import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { toast } from 'sonner'
import {
  CheckCircle2,
  Clock,
  Database,
  ExternalLink,
  Loader2,
  Play,
  Settings2,
  XCircle,
} from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { adminFetch } from '../lib/adminFetch'

type Scraper = {
  key: string
  name: string
  command: string
  source_url?: string | null
  params: string[]
  is_enabled: boolean
  settings: Record<string, unknown>
  latest_run?: {
    status: string
    started_at: string
    items_found: number
    items_upserted: number
  } | null
}

const STAGGER = 0.07
const SAVE_DEBOUNCE = 800

export default function ScrapersPage() {
  const [scrapers, setScrapers] = useState<Scraper[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [runningKeys, setRunningKeys] = useState<Set<string>>(new Set())
  const saveTimers = useRef<Record<string, ReturnType<typeof setTimeout>>>({})

  useEffect(() => {
    const controller = new AbortController()

    const load = async () => {
      setIsLoading(true)
      try {
        const response = await adminFetch('/admin/scrapers', {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Failed to load scrapers')
        const payload = await response.json()
        setScrapers(payload.data ?? [])
      } catch {
        setScrapers([])
      } finally {
        setIsLoading(false)
      }
    }

    load()

    return () => controller.abort()
  }, [])

  /* ── persist settings (called after debounce) ────────────────────── */
  const persistSettings = useCallback(
    async (scraperKey: string, settings: Record<string, unknown>) => {
      const response = await adminFetch(`/admin/scrapers/${scraperKey}`, {
        method: 'PATCH',
        body: JSON.stringify({ settings }),
      })

      if (!response.ok) {
        toast.error('Failed to save settings')
        return
      }

      toast.success('Settings saved')
    },
    [],
  )

  /* ── toggle enabled/disabled ─────────────────────────────────────── */
  const toggleEnabled = useCallback(async (scraper: Scraper) => {
    const nextValue = !scraper.is_enabled

    // optimistic update
    setScrapers((current) =>
      current.map((item) =>
        item.key === scraper.key ? { ...item, is_enabled: nextValue } : item,
      ),
    )

    const response = await adminFetch(`/admin/scrapers/${scraper.key}`, {
      method: 'PATCH',
      body: JSON.stringify({
        is_enabled: nextValue,
        settings: scraper.settings,
      }),
    })

    if (!response.ok) {
      // revert
      setScrapers((current) =>
        current.map((item) =>
          item.key === scraper.key ? { ...item, is_enabled: !nextValue } : item,
        ),
      )
      toast.error(`Failed to ${nextValue ? 'enable' : 'disable'} ${scraper.name}`)
      return
    }

    toast.success(`${scraper.name} ${nextValue ? 'enabled' : 'disabled'}`)
  }, [])

  /* ── update setting value + debounced save ───────────────────────── */
  const updateSettingValue = useCallback(
    (scraperKey: string, key: string, value: string) => {
      setScrapers((current) => {
        const updated = current.map((item) => {
          if (item.key !== scraperKey) return item
          const nextSettings = { ...(item.settings ?? {}), [key]: value }

          // schedule debounced save
          clearTimeout(saveTimers.current[scraperKey])
          saveTimers.current[scraperKey] = setTimeout(() => {
            persistSettings(scraperKey, nextSettings)
          }, SAVE_DEBOUNCE)

          return { ...item, settings: nextSettings }
        })
        return updated
      })
    },
    [persistSettings],
  )

  /* ── manually run a scraper ──────────────────────────────────────── */
  const runScraper = useCallback(async (scraper: Scraper) => {
    setRunningKeys((prev) => new Set(prev).add(scraper.key))

    try {
      const response = await adminFetch(`/admin/scrapers/${scraper.key}/run`, {
        method: 'POST',
      })

      if (!response.ok) {
        const body = await response.json().catch(() => ({}))
        toast.error(body.message ?? 'Failed to run scraper')
        return
      }

      const result = await response.json()

      // update latest_run in state
      if (result.latest_run) {
        setScrapers((current) =>
          current.map((item) =>
            item.key === scraper.key
              ? { ...item, latest_run: result.latest_run }
              : item,
          ),
        )
      }

      toast.success(
        `${scraper.name} finished — ${result.latest_run?.items_upserted ?? 0} upserted`,
      )
    } catch {
      toast.error('Scraper run failed unexpectedly')
    } finally {
      setRunningKeys((prev) => {
        const next = new Set(prev)
        next.delete(scraper.key)
        return next
      })
    }
  }, [])

  return (
    <TooltipProvider delayDuration={200}>
      <motion.div
        className="space-y-6 px-6 pb-8 pt-4"
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        {/* ── Header ──────────────────────────────────────────────── */}
        <div className="flex items-end justify-between">
          <div>
            <h2 className="text-2xl font-semibold tracking-tight">Scrapers</h2>
            <p className="text-sm text-muted-foreground">
              Enable or configure individual data sources.
            </p>
          </div>
          <Badge variant="outline" className="gap-1.5 border-slate-200 text-slate-500">
            <Database className="h-3 w-3" />
            {scrapers.length} sources
          </Badge>
        </div>

        {/* ── Card list ───────────────────────────────────────────── */}
        <div className="space-y-4">
          <AnimatePresence mode="popLayout">
            {isLoading
              ? Array.from({ length: 4 }).map((_, idx) => (
                  <div
                    key={`skel-${idx}`}
                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                  >
                    <div className="flex flex-wrap items-start justify-between gap-4">
                      <div className="space-y-2">
                        <Skeleton className="h-5 w-48" />
                        <Skeleton className="h-3 w-32" />
                      </div>
                      <Skeleton className="h-6 w-10 rounded-full" />
                    </div>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
                      <Skeleton className="h-10 w-full" />
                      <Skeleton className="h-10 w-full" />
                    </div>
                    <Skeleton className="mt-4 h-8 w-28 ml-auto" />
                  </div>
                ))
              : scrapers.map((scraper, idx) => {
                  const isRunning = runningKeys.has(scraper.key)

                  return (
                    <motion.div
                      key={scraper.key}
                      initial={{ opacity: 0, y: 16 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, y: -8 }}
                      transition={{ duration: 0.35, delay: idx * STAGGER }}
                      layout
                      className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md"
                    >
                      {/* top row */}
                      <div className="flex flex-wrap items-center justify-between gap-4">
                        <div className="flex-1">
                          <div className="flex items-center gap-2">
                            <Settings2 className="h-4 w-4 text-slate-400" />
                            <h3 className="text-base font-semibold text-slate-800">
                              {scraper.name}
                            </h3>
                          </div>
                          <p className="mt-0.5 pl-6 font-mono text-[11px] text-slate-400">
                            {scraper.command}
                          </p>
                          {scraper.source_url && (
                            <a
                              href={scraper.source_url}
                              target="_blank"
                              rel="noreferrer"
                              className="mt-1.5 inline-flex items-center gap-1 pl-6 text-xs text-blue-600 hover:text-blue-700"
                            >
                              View source website
                              <ExternalLink className="h-3 w-3" />
                            </a>
                          )}
                        </div>

                        <div className="flex items-center gap-2">
                          <Label
                            htmlFor={`switch-${scraper.key}`}
                            className="text-xs text-slate-500"
                          >
                            {scraper.is_enabled ? 'Enabled' : 'Disabled'}
                          </Label>
                          <Switch
                            id={`switch-${scraper.key}`}
                            checked={scraper.is_enabled}
                            onCheckedChange={() => toggleEnabled(scraper)}
                          />
                        </div>
                      </div>

                      {/* settings inputs */}
                      {scraper.params.length > 0 && (
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                          {scraper.params.map((param) => (
                            <div key={param} className="space-y-1.5">
                              <Label className="text-xs capitalize">
                                {param.replace(/_/g, ' ')}
                              </Label>
                              <Input
                                value={
                                  (scraper.settings?.[param] as string | undefined) ?? ''
                                }
                                onChange={(e) =>
                                  updateSettingValue(scraper.key, param, e.target.value)
                                }
                                placeholder={`Set ${param}`}
                              />
                            </div>
                          ))}
                        </div>
                      )}

                      {/* footer */}
                      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        {scraper.latest_run ? (
                          <div className="flex items-center gap-3 text-xs text-slate-500">
                            {scraper.latest_run.status === 'success' ? (
                              <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
                            ) : (
                              <XCircle className="h-3.5 w-3.5 text-rose-400" />
                            )}
                            <span className="flex items-center gap-1">
                              <Clock className="h-3 w-3" />
                              {new Date(
                                scraper.latest_run.started_at,
                              ).toLocaleString()}
                            </span>
                            <Badge
                              variant="outline"
                              className="border-blue-100 bg-blue-50 text-[10px] text-blue-700"
                            >
                              {scraper.latest_run.items_upserted} upserted
                            </Badge>
                          </div>
                        ) : (
                          <p className="text-xs italic text-slate-400">No runs yet</p>
                        )}

                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              size="sm"
                              variant="outline"
                              disabled={isRunning || !scraper.is_enabled}
                              onClick={() => runScraper(scraper)}
                              className="gap-1.5"
                            >
                              {isRunning ? (
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                              ) : (
                                <Play className="h-3.5 w-3.5" />
                              )}
                              {isRunning ? 'Running…' : 'Run now'}
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>
                            {!scraper.is_enabled
                              ? 'Enable the scraper first'
                              : 'Manually trigger this scraper'}
                          </TooltipContent>
                        </Tooltip>
                      </div>
                    </motion.div>
                  )
                })}
          </AnimatePresence>
        </div>
      </motion.div>
    </TooltipProvider>
  )
}

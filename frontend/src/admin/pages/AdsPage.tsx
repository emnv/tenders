import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { toast } from 'sonner'
import {
  Code2,
  LayoutTemplate,
  Megaphone,
} from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  TooltipProvider,
} from '@/components/ui/tooltip'
import { adminFetch } from '../lib/adminFetch'

type AdSpot = {
  key: string
  name: string
  placement: string
  provider: string | null
  is_enabled: boolean
  settings: Record<string, unknown> | null
  embed_code: string | null
}

/* ── constants ─────────────────────────────────────────────────────── */
const STAGGER = 0.07
const SAVE_DEBOUNCE = 800

function placementBadge(placement: string) {
  const p = placement.toLowerCase()
  if (p.includes('header'))
    return 'border-violet-200 bg-violet-50 text-violet-700'
  if (p.includes('footer'))
    return 'border-blue-200 bg-blue-50 text-blue-700'
  if (p.includes('sidebar'))
    return 'border-amber-200 bg-amber-50 text-amber-700'
  return 'border-slate-200 bg-slate-100 text-slate-600'
}

export default function AdsPage() {
  const [spots, setSpots] = useState<AdSpot[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const saveTimers = useRef<Record<string, ReturnType<typeof setTimeout>>>({})

  useEffect(() => {
    const controller = new AbortController()

    const load = async () => {
      setIsLoading(true)
      try {
        const response = await adminFetch('/admin/ads', {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Failed to load ad spots')
        const payload = await response.json()
        setSpots(payload.data ?? [])
      } catch {
        setSpots([])
      } finally {
        setIsLoading(false)
      }
    }

    load()

    return () => controller.abort()
  }, [])

  /* ── persist a spot to the API ───────────────────────────────────── */
  const persistSpot = useCallback(async (spot: AdSpot) => {
    const response = await adminFetch(`/admin/ads/${spot.key}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: spot.name,
        provider: spot.provider,
        is_enabled: spot.is_enabled,
        settings: spot.settings,
        embed_code: spot.embed_code,
      }),
    })

    if (!response.ok) {
      toast.error('Failed to save changes')
      return
    }

    toast.success('Changes saved')
  }, [])

  /* ── toggle enabled/disabled (immediate save) ────────────────────── */
  const toggleEnabled = useCallback(
    async (spot: AdSpot) => {
      const nextValue = !spot.is_enabled

      // optimistic update
      setSpots((current) =>
        current.map((item) =>
          item.key === spot.key ? { ...item, is_enabled: nextValue } : item,
        ),
      )

      const response = await adminFetch(`/admin/ads/${spot.key}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name: spot.name,
          provider: spot.provider,
          is_enabled: nextValue,
          settings: spot.settings,
          embed_code: spot.embed_code,
        }),
      })

      if (!response.ok) {
        // revert
        setSpots((current) =>
          current.map((item) =>
            item.key === spot.key ? { ...item, is_enabled: !nextValue } : item,
          ),
        )
        toast.error(`Failed to ${nextValue ? 'enable' : 'disable'} ${spot.name}`)
        return
      }

      toast.success(`${spot.name} ${nextValue ? 'enabled' : 'disabled'}`)
    },
    [],
  )

  /* ── update a field + debounced auto-save ─────────────────────────── */
  const updateField = useCallback(
    (spotKey: string, field: keyof AdSpot, value: string) => {
      setSpots((current) => {
        const updated = current.map((item) => {
          if (item.key !== spotKey) return item
          const next = { ...item, [field]: value }

          // schedule debounced save
          clearTimeout(saveTimers.current[spotKey])
          saveTimers.current[spotKey] = setTimeout(() => {
            persistSpot(next)
          }, SAVE_DEBOUNCE)

          return next
        })
        return updated
      })
    },
    [persistSpot],
  )

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
            <h2 className="text-2xl font-semibold tracking-tight">Ads</h2>
            <p className="text-sm text-muted-foreground">
              Manage header and footer ad placements.
            </p>
          </div>
          <Badge variant="outline" className="gap-1.5 border-slate-200 text-slate-500">
            <Megaphone className="h-3 w-3" />
            {spots.length} spots
          </Badge>
        </div>

        {/* ── Card list ───────────────────────────────────────────── */}
        <div className="space-y-4">
          <AnimatePresence mode="popLayout">
            {isLoading
              ? Array.from({ length: 2 }).map((_, idx) => (
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
                    <Skeleton className="mt-4 h-40 w-full" />
                  </div>
                ))
              : spots.map((spot, idx) => (
                  <motion.div
                    key={spot.key}
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
                          <LayoutTemplate className="h-4 w-4 text-slate-400" />
                          <h3 className="text-base font-semibold text-slate-800">
                            {spot.name}
                          </h3>
                        </div>
                        <div className="mt-1.5 flex items-center gap-2 pl-6">
                          <Badge
                            variant="outline"
                            className={`text-[10px] ${placementBadge(spot.placement)}`}
                          >
                            {spot.placement}
                          </Badge>
                        </div>
                      </div>

                      <div className="flex items-center gap-2">
                        <Label
                          htmlFor={`switch-${spot.key}`}
                          className="text-xs text-slate-500"
                        >
                          {spot.is_enabled ? 'Enabled' : 'Disabled'}
                        </Label>
                        <Switch
                          id={`switch-${spot.key}`}
                          checked={spot.is_enabled}
                          onCheckedChange={() => toggleEnabled(spot)}
                        />
                      </div>
                    </div>

                    {/* form fields */}
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                      <div className="space-y-1.5">
                        <Label className="text-xs capitalize">Provider</Label>
                        <Input
                          value={spot.provider ?? ''}
                          onChange={(e) =>
                            updateField(spot.key, 'provider', e.target.value)
                          }
                          placeholder="Google AdSense"
                        />
                      </div>
                      <div className="space-y-1.5">
                        <Label className="text-xs capitalize">Name</Label>
                        <Input
                          value={spot.name}
                          onChange={(e) =>
                            updateField(spot.key, 'name', e.target.value)
                          }
                        />
                      </div>
                    </div>

                    <div className="mt-4 space-y-1.5">
                      <Label className="flex items-center gap-1.5 text-xs">
                        <Code2 className="h-3 w-3 text-slate-400" />
                        Embed code
                      </Label>
                      <textarea
                        value={spot.embed_code ?? ''}
                        onChange={(e) =>
                          updateField(spot.key, 'embed_code', e.target.value)
                        }
                        rows={10}
                        className="min-h-[200px] w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs leading-relaxed shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        placeholder="Paste Google Publisher Tags (GPT), AdSense code, or any custom HTML + script snippet here"
                      />
                      <p className="text-[11px] text-slate-400">
                        Changes auto-save after you stop typing. Scripts are injected dynamically on the public page.
                      </p>
                    </div>
                  </motion.div>
                ))}
          </AnimatePresence>
        </div>
      </motion.div>
    </TooltipProvider>
  )
}

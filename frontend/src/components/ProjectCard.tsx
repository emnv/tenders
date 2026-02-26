import { motion } from 'framer-motion'
import {
  Clock,
  Copy,
  ExternalLink,
  Globe,
  MapPin,
  CalendarClock,
  AlertTriangle,
} from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { Project, ProjectStatus } from '@/hooks/use-projects'
import { cn } from '@/lib/utils'

interface ProjectCardProps {
  project: Project
  tabStatus: ProjectStatus
  onClick: () => void
}

/* ── helpers ──────────────────────────────────────────────────────── */

function formatDate(value?: string | null) {
  if (!value) return 'TBD'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-CA', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function isClosingSoon(dateStr?: string | null): boolean {
  if (!dateStr) return false
  const closing = new Date(dateStr)
  if (Number.isNaN(closing.getTime())) return false
  const hoursLeft = (closing.getTime() - Date.now()) / (1000 * 60 * 60)
  return hoursLeft > 0 && hoursLeft <= 48
}

function statusBadge(status?: string | null) {
  const normalized = (status ?? '').toLowerCase()
  if (normalized.includes('open')) {
    return {
      label: 'Open',
      className: 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100',
    }
  }
  if (normalized.includes('award')) {
    return {
      label: 'Awarded',
      className: 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100',
    }
  }
  if (
    normalized.includes('closed') ||
    normalized.includes('cancel') ||
    normalized.includes('expired')
  ) {
    return {
      label: 'Expired',
      className: 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200',
    }
  }
  return {
    label: status ?? 'Unknown',
    className: 'bg-slate-100 text-slate-600 border-slate-200 hover:bg-slate-200',
  }
}

/** Tiny favicon with fallback Globe icon */
function SourceLogo({ url, name }: { url?: string | null; name?: string | null }) {
  return url ? (
    <img
      src={url}
      alt={name ?? 'source'}
      className="h-5 w-5 rounded-sm object-contain"
      loading="lazy"
      onError={(e) => {
        // Replace broken image with nothing – the fallback icon shows via CSS sibling
        e.currentTarget.style.display = 'none'
        e.currentTarget.nextElementSibling?.classList.remove('hidden')
      }}
    />
  ) : (
    <Globe className="h-4 w-4 text-slate-400" />
  )
}

/* ── component ────────────────────────────────────────────────────── */

export function ProjectCard({ project, tabStatus, onClick }: ProjectCardProps) {
  const badge = statusBadge(project.computed_status ?? project.source_status)
  const closingSoon = isClosingSoon(project.date_closing_at)
  const isExpiredTab = tabStatus === 'expired'

  const handleCopyLink = (e: React.MouseEvent) => {
    e.stopPropagation()
    const url = project.source_url || window.location.href
    navigator.clipboard.writeText(url).then(() => {
      toast.success('Link copied to clipboard')
    })
  }

  const handleExternalLink = (e: React.MouseEvent) => {
    e.stopPropagation()
  }

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, scale: 0.96 }}
      transition={{ duration: 0.25, ease: 'easeOut' }}
    >
      <Card
        className={cn(
          'group cursor-pointer border-slate-100 transition-all hover:border-slate-200 hover:shadow-sm',
          isExpiredTab && 'opacity-75'
        )}
        onClick={onClick}
      >
        <CardHeader className="space-y-2 pb-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0 flex-1">
              <CardTitle className="text-lg leading-snug tracking-tight">
                {project.title}
              </CardTitle>
              <p className="mt-1.5 flex flex-wrap items-center gap-x-2 text-sm text-slate-500">
                {project.location && (
                  <span className="inline-flex items-center gap-1">
                    <MapPin className="h-3.5 w-3.5" />
                    {project.location}
                  </span>
                )}
                {!project.location && (
                  <span className="inline-flex items-center gap-1">
                    <MapPin className="h-3.5 w-3.5" />
                    Ontario
                  </span>
                )}
                {project.solicitation_number && (
                  <>
                    <span className="text-slate-300">•</span>
                    <span>{project.solicitation_number}</span>
                  </>
                )}
              </p>
            </div>
            <Badge
              variant="outline"
              className={cn('shrink-0 rounded-full text-xs', badge.className)}
            >
              {badge.label}
            </Badge>
          </div>
        </CardHeader>

        <CardContent className="flex flex-wrap items-center justify-between gap-3 pt-0 text-sm">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-slate-500">
            <span className="inline-flex items-center gap-1">
              <CalendarClock className="h-3.5 w-3.5" />
              Posted: {formatDate(project.published_at)}
            </span>
            <span
              className={cn(
                'inline-flex items-center gap-1',
                isExpiredTab && 'text-rose-500',
                closingSoon && 'font-medium text-rose-600'
              )}
            >
              <Clock className="h-3.5 w-3.5" />
              Closes: {formatDate(project.date_closing_at)}
              {closingSoon && (
                <span className="ml-1 inline-flex items-center gap-0.5 animate-pulse text-xs font-semibold text-rose-600">
                  <AlertTriangle className="h-3 w-3" />
                  Closing soon
                </span>
              )}
            </span>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-500">
              <SourceLogo url={project.logo_url} name={project.source_site_name} />
              <Globe className="hidden h-4 w-4 text-slate-400" />
              {project.source_site_name ?? 'Source'}
            </span>
          </div>

          <div className="flex items-center gap-1.5">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  size="icon"
                  variant="ghost"
                  className="h-8 w-8"
                  onClick={handleCopyLink}
                >
                  <Copy className="h-4 w-4" />
                  <span className="sr-only">Copy share link</span>
                </Button>
              </TooltipTrigger>
              <TooltipContent>Copy share link</TooltipContent>
            </Tooltip>
            {project.source_url && (
              <Button size="sm" variant="outline" className="gap-1.5" asChild>
                <a
                  href={project.source_url}
                  target="_blank"
                  rel="noreferrer"
                  onClick={handleExternalLink}
                >
                  <ExternalLink className="h-3.5 w-3.5" />
                  View posting
                </a>
              </Button>
            )}
          </div>
        </CardContent>
      </Card>
    </motion.div>
  )
}

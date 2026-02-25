import {
  CalendarClock,
  Clock,
  Copy,
  ExternalLink,
  MapPin,
  User,
  Mail,
  Phone,
  FileText,
  Tag,
} from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import type { Project } from '@/hooks/use-projects'
import { cn } from '@/lib/utils'

interface ProjectDetailSheetProps {
  project: Project | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

function formatDate(value?: string | null) {
  if (!value) return 'TBD'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-CA', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function statusBadgeColor(status?: string | null) {
  const normalized = (status ?? '').toLowerCase()
  if (normalized.includes('open'))
    return 'bg-emerald-50 text-emerald-700 border-emerald-200'
  if (normalized.includes('award'))
    return 'bg-amber-50 text-amber-700 border-amber-200'
  if (
    normalized.includes('closed') ||
    normalized.includes('cancel') ||
    normalized.includes('expired')
  )
    return 'bg-slate-100 text-slate-500 border-slate-200'
  return 'bg-slate-100 text-slate-600 border-slate-200'
}

function DetailRow({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ElementType
  label: string
  value?: string | null
}) {
  if (!value) return null
  return (
    <div className="flex items-start gap-3 text-sm">
      <Icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
      <div>
        <span className="text-xs font-medium uppercase tracking-wider text-slate-400">
          {label}
        </span>
        <p className="mt-0.5 text-slate-700">{value}</p>
      </div>
    </div>
  )
}

export function ProjectDetailSheet({
  project,
  open,
  onOpenChange,
}: ProjectDetailSheetProps) {
  if (!project) return null

  const handleCopy = () => {
    const url = project.source_url || window.location.href
    navigator.clipboard.writeText(url).then(() => {
      toast.success('Link copied to clipboard')
    })
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="w-full overflow-y-auto sm:max-w-lg"
      >
        <SheetHeader className="text-left">
          <div className="flex items-start gap-3">
            <SheetTitle className="flex-1 text-xl leading-snug">
              {project.title}
            </SheetTitle>
            {project.source_status && (
              <Badge
                variant="outline"
                className={cn(
                  'shrink-0 rounded-full',
                  statusBadgeColor(project.source_status)
                )}
              >
                {project.source_status}
              </Badge>
            )}
          </div>
          <SheetDescription className="flex items-center gap-1.5 text-slate-500">
            <MapPin className="h-3.5 w-3.5" />
            {project.location ?? 'Ontario'}
            {project.solicitation_number && (
              <>
                <span className="text-slate-300">•</span>
                {project.solicitation_number}
              </>
            )}
          </SheetDescription>
        </SheetHeader>

        <Separator className="my-5" />

        {/* Dates */}
        <section className="space-y-3">
          <h4 className="text-xs font-semibold uppercase tracking-wider text-slate-400">
            Key Dates
          </h4>
          <div className="grid gap-3 sm:grid-cols-2">
            <DetailRow
              icon={CalendarClock}
              label="Published"
              value={formatDate(project.published_at)}
            />
            <DetailRow
              icon={Clock}
              label="Closing"
              value={formatDate(project.date_closing_at)}
            />
            <DetailRow
              icon={CalendarClock}
              label="Issue Date"
              value={formatDate(project.date_issue_at)}
            />
            <DetailRow
              icon={CalendarClock}
              label="Available"
              value={formatDate(project.date_available_at)}
            />
          </div>
        </section>

        <Separator className="my-5" />

        {/* Details */}
        <section className="space-y-3">
          <h4 className="text-xs font-semibold uppercase tracking-wider text-slate-400">
            Details
          </h4>
          <div className="space-y-3">
            <DetailRow
              icon={Tag}
              label="Category"
              value={project.high_level_category}
            />
            <DetailRow
              icon={FileText}
              label="Solicitation Type"
              value={project.solicitation_type}
            />
            <DetailRow
              icon={FileText}
              label="Contract Duration"
              value={project.contract_duration}
            />
            <DetailRow
              icon={FileText}
              label="Pre-Bid Meeting"
              value={project.pre_bid_meeting}
            />
            {project.description && (
              <div className="text-sm">
                <span className="text-xs font-medium uppercase tracking-wider text-slate-400">
                  Description
                </span>
                <p className="mt-1 whitespace-pre-wrap text-slate-700">
                  {project.description}
                </p>
              </div>
            )}
            {project.specific_conditions && (
              <div className="text-sm">
                <span className="text-xs font-medium uppercase tracking-wider text-slate-400">
                  Specific Conditions
                </span>
                <p className="mt-1 whitespace-pre-wrap text-slate-700">
                  {project.specific_conditions}
                </p>
              </div>
            )}
          </div>
        </section>

        {/* Buyer Info */}
        {(project.buyer_name || project.buyer_email || project.buyer_phone) && (
          <>
            <Separator className="my-5" />
            <section className="space-y-3">
              <h4 className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                Buyer Contact
              </h4>
              <div className="space-y-3">
                <DetailRow
                  icon={User}
                  label="Name"
                  value={project.buyer_name}
                />
                <DetailRow
                  icon={Mail}
                  label="Email"
                  value={project.buyer_email}
                />
                <DetailRow
                  icon={Phone}
                  label="Phone"
                  value={project.buyer_phone}
                />
                <DetailRow
                  icon={MapPin}
                  label="Location"
                  value={project.buyer_location}
                />
              </div>
            </section>
          </>
        )}

        <Separator className="my-5" />

        {/* Actions */}
        <div className="flex flex-wrap gap-3">
          {project.source_url && (
            <Button className="gap-1.5" asChild>
              <a
                href={project.source_url}
                target="_blank"
                rel="noreferrer"
              >
                <ExternalLink className="h-4 w-4" />
                View original posting
              </a>
            </Button>
          )}
          <Button variant="outline" className="gap-1.5" onClick={handleCopy}>
            <Copy className="h-4 w-4" />
            Copy link
          </Button>
        </div>

        <p className="mt-4 text-xs text-slate-400">
          Source: {project.source_site_name ?? 'Unknown'}
        </p>
      </SheetContent>
    </Sheet>
  )
}

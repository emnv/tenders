import type { FormEvent } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { toast } from 'sonner'
import {
  PencilIcon,
  PlusIcon,
  Search,
  StarIcon,
  Trash2Icon,
  X,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { SourceComboBox } from '@/components/SourceComboBox'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { adminFetch } from '../lib/adminFetch'

type Project = {
  id: number
  title: string
  description?: string | null
  location: string | null
  source_site_name: string | null
  source_url?: string | null
  published_at: string | null
  date_closing_at?: string | null
  source_status?: string | null
  is_featured?: boolean
}

type PaginatedResponse = {
  data: Project[]
  current_page: number
  last_page: number
  total: number
  per_page: number
}

type ProjectForm = {
  title: string
  location: string
  source_site_name: string
  source_url: string
  source_status: string
  date_closing_at: string
  published_at: string
  description: string
}

const emptyForm: ProjectForm = {
  title: '',
  location: '',
  source_site_name: '',
  source_url: '',
  source_status: '',
  date_closing_at: '',
  published_at: '',
  description: '',
}

const toDateInput = (value?: string | null) => {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value.slice(0, 10)
  return date.toISOString().slice(0, 10)
}

const toNullable = (value: string) => (value.trim() ? value.trim() : null)

function statusBadgeStyles(status?: string | null) {
  const s = (status ?? '').toLowerCase()
  if (s.includes('open'))
    return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (s.includes('award'))
    return 'border-amber-200 bg-amber-50 text-amber-700'
  if (s.includes('closed') || s.includes('cancel'))
    return 'border-rose-200 bg-rose-50 text-rose-700'
  return 'border-slate-200 bg-slate-100 text-slate-600'
}

/* ── pagination helper ────────────────────────────────────────────── */
function buildPageRange(page: number, lastPage: number) {
  const range: Array<number | 'ellipsis'> = []
  const delta = 2
  if (lastPage <= 7) {
    for (let i = 1; i <= lastPage; i += 1) range.push(i)
    return range
  }
  range.push(1)
  const start = Math.max(2, page - delta)
  const end = Math.min(lastPage - 1, page + delta)
  if (start > 2) range.push('ellipsis')
  for (let i = start; i <= end; i += 1) range.push(i)
  if (end < lastPage - 1) range.push('ellipsis')
  range.push(lastPage)
  return range
}

export default function ProjectsPage() {
  const [projects, setProjects] = useState<Project[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [queryInput, setQueryInput] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')
  const [source, setSource] = useState('')
  const [availableSources, setAvailableSources] = useState<string[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [isSheetOpen, setIsSheetOpen] = useState(false)
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false)
  const [detailProject, setDetailProject] = useState<Project | null>(null)
  const [formMode, setFormMode] = useState<'add' | 'edit'>('add')
  const [formData, setFormData] = useState<ProjectForm>(emptyForm)
  const [activeProjectId, setActiveProjectId] = useState<number | null>(null)
  const [isSaving, setIsSaving] = useState(false)

  /* ── debounce search ─────────────────────────────────────────────── */
  useEffect(() => {
    const handle = window.setTimeout(() => {
      setDebouncedQuery(queryInput)
      setPage(1)
    }, 350)
    return () => window.clearTimeout(handle)
  }, [queryInput])

  const params = useMemo(() => {
    const search = new URLSearchParams()
    if (debouncedQuery.trim()) search.set('q', debouncedQuery.trim())
    if (source.trim()) search.set('source', source.trim())
    search.set('page', String(page))
    search.set('per_page', '25')
    return search
  }, [debouncedQuery, source, page])

  useEffect(() => {
    const controller = new AbortController()

    const load = async () => {
      setIsLoading(true)
      try {
        const response = await adminFetch(`/admin/projects?${params.toString()}`, {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Failed to load projects')
        const payload = (await response.json()) as PaginatedResponse
        setProjects(payload.data ?? [])
        setLastPage(payload.last_page ?? 1)
        setTotal(payload.total ?? 0)
      } catch {
        setProjects([])
        setLastPage(1)
        setTotal(0)
      } finally {
        setIsLoading(false)
      }
    }

    load()

    return () => controller.abort()
  }, [params])

  useEffect(() => {
    const controller = new AbortController()

    const loadSources = async () => {
      try {
        const response = await adminFetch('/admin/projects/sources', {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Failed to load sources')
        const payload = await response.json()
        setAvailableSources(Array.isArray(payload.data) ? payload.data : [])
      } catch {
        setAvailableSources([])
      }
    }

    loadSources()

    return () => controller.abort()
  }, [])

  const pageRange = useMemo(() => buildPageRange(page, lastPage), [page, lastPage])

  const toggleFeatured = useCallback(async (project: Project) => {
    const nextValue = !project.is_featured
    const response = await adminFetch(`/admin/projects/${project.id}/featured`, {
      method: 'PATCH',
      body: JSON.stringify({ is_featured: nextValue }),
    })

    if (!response.ok) {
      toast.error('Failed to update featured status')
      return
    }

    const payload = await response.json()
    setProjects((current) =>
      current.map((item) => (item.id === project.id ? payload.data : item)),
    )
    toast.success(nextValue ? 'Project featured' : 'Project unfeatured')
  }, [])

  const openCreate = useCallback(() => {
    setFormMode('add')
    setActiveProjectId(null)
    setFormData(emptyForm)
    setIsSheetOpen(true)
  }, [])

  const openEdit = useCallback((project: Project) => {
    setFormMode('edit')
    setActiveProjectId(project.id)
    setFormData({
      title: project.title ?? '',
      location: project.location ?? '',
      source_site_name: project.source_site_name ?? '',
      source_url: project.source_url ?? '',
      source_status: project.source_status ?? '',
      date_closing_at: toDateInput(project.date_closing_at),
      published_at: toDateInput(project.published_at),
      description: project.description ?? '',
    })
    setIsSheetOpen(true)
  }, [])

  const handleSave = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setIsSaving(true)

    const payload = {
      title: formData.title.trim(),
      location: toNullable(formData.location),
      source_site_name: toNullable(formData.source_site_name),
      source_url: toNullable(formData.source_url),
      source_status: toNullable(formData.source_status),
      date_closing_at: toNullable(formData.date_closing_at),
      published_at: toNullable(formData.published_at),
      description: toNullable(formData.description),
    }

    try {
      const response = await adminFetch(
        formMode === 'add' ? '/admin/projects' : `/admin/projects/${activeProjectId}`,
        {
          method: formMode === 'add' ? 'POST' : 'PATCH',
          body: JSON.stringify(payload),
        },
      )

      if (!response.ok) {
        toast.error('Failed to save project')
        return
      }

      const result = await response.json()
      const updatedProject = result.data as Project

      setProjects((current) => {
        if (formMode === 'add') return [updatedProject, ...current]
        return current.map((item) => (item.id === updatedProject.id ? updatedProject : item))
      })

      if (formMode === 'add') {
        setTotal((prev) => prev + 1)
      }

      setIsSheetOpen(false)
      toast.success(formMode === 'add' ? 'Project created' : 'Project updated')
    } finally {
      setIsSaving(false)
    }
  }

  const handleDelete = useCallback(async (project: Project) => {
    const confirmed = window.confirm(`Delete "${project.title}"? This will be a soft delete.`)
    if (!confirmed) return

    const response = await adminFetch(`/admin/projects/${project.id}`, {
      method: 'DELETE',
    })

    if (!response.ok) {
      toast.error('Failed to delete project')
      return
    }

    setProjects((current) => current.filter((item) => item.id !== project.id))
    setTotal((prev) => Math.max(0, prev - 1))
    toast.success('Project deleted')
  }, [])

  const openDetail = useCallback((project: Project) => {
    setDetailProject(project)
    setIsDetailSheetOpen(true)
  }, [])

  return (
    <TooltipProvider delayDuration={200}>
      <motion.div
        className="space-y-6 px-6 pb-8 pt-4"
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        {/* ── Header ────────────────────────────────────────────────── */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h2 className="text-2xl font-semibold tracking-tight">Projects</h2>
            <p className="text-sm text-muted-foreground">
              {total.toLocaleString()} projects found
            </p>
          </div>
          <div className="grid w-full items-center gap-3 sm:w-auto sm:grid-cols-[220px_190px_auto]">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input
                value={queryInput}
                onChange={(e) => setQueryInput(e.target.value)}
                placeholder="Search projects"
                className="h-10 w-full pl-9 text-sm"
              />
              {queryInput && (
                <button
                  type="button"
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-slate-400 hover:text-slate-600"
                  onClick={() => setQueryInput('')}
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              )}
            </div>
            <SourceComboBox
              sources={availableSources}
              value={source}
              onChange={(next) => {
                setSource(next)
                setPage(1)
              }}
              disabled={isLoading}
              triggerClassName="h-10 text-sm"
            />
            <Button onClick={openCreate} className="h-10 gap-1.5 px-4 text-sm">
              <PlusIcon className="h-4 w-4" />
              Add Project
            </Button>
          </div>
        </div>

        {/* ── Compact project list ──────────────────────────────────── */}
        <div className="space-y-3">
          <AnimatePresence mode="popLayout">
            {isLoading
              ? Array.from({ length: 8 }).map((_, idx) => (
                  <div
                    key={`skel-${idx}`}
                    className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex-1 space-y-2">
                        <Skeleton className="h-4 w-4/5" />
                        <div className="flex gap-2">
                          <Skeleton className="h-5 w-24 rounded-full" />
                          <Skeleton className="h-5 w-20 rounded-full" />
                        </div>
                      </div>
                      <div className="flex gap-2">
                        <Skeleton className="h-8 w-8 rounded-md" />
                        <Skeleton className="h-8 w-8 rounded-md" />
                        <Skeleton className="h-8 w-8 rounded-md" />
                      </div>
                    </div>
                    <div className="mt-3 grid gap-2 sm:grid-cols-3">
                      <Skeleton className="h-3 w-28" />
                      <Skeleton className="h-3 w-24" />
                      <Skeleton className="h-3 w-24" />
                    </div>
                  </div>
                ))
              : projects.map((project) => (
                  <motion.div
                    key={project.id}
                    layout
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -6 }}
                    transition={{ duration: 0.2 }}
                    className="group cursor-pointer rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
                    onClick={() => openDetail(project)}
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="min-w-0 flex-1">
                        <p className="line-clamp-2 text-sm font-semibold leading-snug text-slate-900 sm:text-[15px]">
                          {project.title}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                          {project.source_status && (
                            <Badge
                              variant="outline"
                              className={`text-[10px] ${statusBadgeStyles(project.source_status)}`}
                            >
                              {project.source_status}
                            </Badge>
                          )}
                          <Badge
                            variant="outline"
                            className="border-slate-200 bg-slate-50 text-[10px] text-slate-600"
                          >
                            {project.source_site_name ?? 'Unknown source'}
                          </Badge>
                        </div>
                      </div>

                      <div className="flex items-center gap-1">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              size="icon"
                              variant="ghost"
                              className="h-8 w-8"
                              onClick={(e) => {
                                e.stopPropagation()
                                toggleFeatured(project)
                              }}
                            >
                              <StarIcon
                                className={`h-4 w-4 transition-colors ${
                                  project.is_featured
                                    ? 'fill-amber-400 text-amber-500'
                                    : 'text-slate-300 group-hover:text-amber-300'
                                }`}
                              />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>
                            {project.is_featured ? 'Remove from featured' : 'Feature this project'}
                          </TooltipContent>
                        </Tooltip>

                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              size="icon"
                              variant="ghost"
                              className="h-8 w-8"
                              onClick={(e) => {
                                e.stopPropagation()
                                openEdit(project)
                              }}
                            >
                              <PencilIcon className="h-3.5 w-3.5" />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>Edit</TooltipContent>
                        </Tooltip>

                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              size="icon"
                              variant="ghost"
                              className="h-8 w-8 text-rose-500 hover:bg-rose-50 hover:text-rose-600"
                              onClick={(e) => {
                                e.stopPropagation()
                                handleDelete(project)
                              }}
                            >
                              <Trash2Icon className="h-3.5 w-3.5" />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>Delete</TooltipContent>
                        </Tooltip>
                      </div>
                    </div>

                    <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
                      <p className="truncate">
                        <span className="font-medium text-slate-700">Location:</span>{' '}
                        {project.location ?? '—'}
                      </p>
                      <p className="tabular-nums">
                        <span className="font-medium text-slate-700">Closing:</span>{' '}
                        {project.date_closing_at
                          ? new Date(project.date_closing_at).toLocaleDateString('en-CA')
                          : '—'}
                      </p>
                      <p className="tabular-nums">
                        <span className="font-medium text-slate-700">Published:</span>{' '}
                        {project.published_at
                          ? new Date(project.published_at).toLocaleDateString('en-CA')
                          : '—'}
                      </p>
                    </div>
                  </motion.div>
                ))}
          </AnimatePresence>

          {projects.length === 0 && !isLoading && (
            <div className="rounded-xl border border-slate-200 bg-white px-4 py-12 text-center shadow-sm">
              <Search className="mx-auto mb-2 h-6 w-6 text-slate-300" />
              <p className="text-sm text-slate-500">No projects found.</p>
            </div>
          )}
        </div>

        {/* ── Pagination ────────────────────────────────────────────── */}
        <div className="flex items-center justify-between">
          <Button
            variant="outline"
            size="sm"
            disabled={page <= 1 || isLoading}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </Button>

          <div className="flex flex-nowrap items-center gap-1.5 text-sm">
            {pageRange.map((item, idx) =>
              item === 'ellipsis' ? (
                <span key={`e-${idx}`} className="px-2 text-slate-400">…</span>
              ) : (
                <button
                  key={item}
                  type="button"
                  onClick={() => setPage(item)}
                  disabled={isLoading}
                  className={`rounded-full px-3 py-1 text-sm transition ${
                    item === page
                      ? 'bg-slate-900 text-white'
                      : 'text-slate-500 hover:text-slate-900'
                  }`}
                >
                  {item}
                </button>
              ),
            )}
          </div>

          <Button
            variant="outline"
            size="sm"
            disabled={page >= lastPage || isLoading}
            onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
          >
            Next
          </Button>
        </div>

        {/* ── Sheet ─────────────────────────────────────────────────── */}
        <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
          <SheetContent className="overflow-y-auto sm:max-w-lg">
            <SheetHeader>
              <SheetTitle>
                {formMode === 'add' ? 'Add Project' : 'Edit Project'}
              </SheetTitle>
              <SheetDescription>
                Update project details. Fields marked with * are required.
              </SheetDescription>
            </SheetHeader>
            <form onSubmit={handleSave} className="mt-6 space-y-5">
              <div className="space-y-2">
                <Label className="text-xs font-semibold">Title *</Label>
                <Input
                  required
                  value={formData.title}
                  onChange={(e) =>
                    setFormData((prev) => ({ ...prev, title: e.target.value }))
                  }
                  placeholder="Project title"
                />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label className="text-xs font-semibold">Location</Label>
                  <Input
                    value={formData.location}
                    onChange={(e) =>
                      setFormData((prev) => ({ ...prev, location: e.target.value }))
                    }
                    placeholder="City, Province"
                  />
                </div>
                <div className="space-y-2">
                  <Label className="text-xs font-semibold">Source Name</Label>
                  <Input
                    value={formData.source_site_name}
                    onChange={(e) =>
                      setFormData((prev) => ({
                        ...prev,
                        source_site_name: e.target.value,
                      }))
                    }
                    placeholder="Source site"
                  />
                </div>
              </div>
              <div className="space-y-2">
                <Label className="text-xs font-semibold">Source URL</Label>
                <Input
                  value={formData.source_url}
                  onChange={(e) =>
                    setFormData((prev) => ({ ...prev, source_url: e.target.value }))
                  }
                  placeholder="https://..."
                />
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label className="text-xs font-semibold">Published Date</Label>
                  <Input
                    type="date"
                    value={formData.published_at}
                    onChange={(e) =>
                      setFormData((prev) => ({
                        ...prev,
                        published_at: e.target.value,
                      }))
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label className="text-xs font-semibold">Closing Date</Label>
                  <Input
                    type="date"
                    value={formData.date_closing_at}
                    onChange={(e) =>
                      setFormData((prev) => ({
                        ...prev,
                        date_closing_at: e.target.value,
                      }))
                    }
                  />
                </div>
              </div>
              <div className="space-y-2">
                <Label className="text-xs font-semibold">Status</Label>
                <Input
                  value={formData.source_status}
                  onChange={(e) =>
                    setFormData((prev) => ({
                      ...prev,
                      source_status: e.target.value,
                    }))
                  }
                  placeholder="Open"
                />
              </div>
              <div className="space-y-2">
                <Label className="text-xs font-semibold">Description</Label>
                <textarea
                  value={formData.description}
                  onChange={(e) =>
                    setFormData((prev) => ({
                      ...prev,
                      description: e.target.value,
                    }))
                  }
                  className="min-h-[120px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                  placeholder="Short description"
                />
              </div>
              <SheetFooter>
                <Button type="submit" disabled={isSaving}>
                  {isSaving ? 'Saving…' : 'Save Project'}
                </Button>
              </SheetFooter>
            </form>
          </SheetContent>
        </Sheet>

        <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
          <SheetContent className="overflow-y-auto sm:max-w-xl">
            <SheetHeader>
              <SheetTitle>Project Details</SheetTitle>
              <SheetDescription>
                Review project information and source metadata.
              </SheetDescription>
            </SheetHeader>

            {detailProject && (
              <div className="mt-6 space-y-5 text-sm">
                <div className="space-y-2">
                  <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Title</p>
                  <p className="text-sm font-medium leading-snug text-slate-900">{detailProject.title}</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Source</p>
                    <p className="text-slate-700">{detailProject.source_site_name ?? '—'}</p>
                  </div>
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Status</p>
                    <p className="text-slate-700">{detailProject.source_status ?? '—'}</p>
                  </div>
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Location</p>
                    <p className="text-slate-700">{detailProject.location ?? '—'}</p>
                  </div>
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Featured</p>
                    <p className="text-slate-700">{detailProject.is_featured ? 'Yes' : 'No'}</p>
                  </div>
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Published</p>
                    <p className="tabular-nums text-slate-700">
                      {detailProject.published_at
                        ? new Date(detailProject.published_at).toLocaleString()
                        : '—'}
                    </p>
                  </div>
                  <div className="space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Closing</p>
                    <p className="tabular-nums text-slate-700">
                      {detailProject.date_closing_at
                        ? new Date(detailProject.date_closing_at).toLocaleString()
                        : '—'}
                    </p>
                  </div>
                </div>

                <div className="space-y-1.5">
                  <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Source URL</p>
                  {detailProject.source_url ? (
                    <a
                      href={detailProject.source_url}
                      target="_blank"
                      rel="noreferrer"
                      className="break-all text-blue-600 hover:text-blue-700"
                    >
                      {detailProject.source_url}
                    </a>
                  ) : (
                    <p className="text-slate-700">—</p>
                  )}
                </div>

                <div className="space-y-1.5">
                  <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Description</p>
                  <p className="whitespace-pre-wrap text-slate-700">
                    {detailProject.description || 'No description provided.'}
                  </p>
                </div>
              </div>
            )}
          </SheetContent>
        </Sheet>
      </motion.div>
    </TooltipProvider>
  )
}

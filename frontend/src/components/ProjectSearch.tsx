import { useCallback, useEffect, useMemo, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { Search, X } from 'lucide-react'
import { TooltipProvider } from '@/components/ui/tooltip'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ProjectCard } from '@/components/ProjectCard'
import { ProjectCardSkeleton } from '@/components/ProjectCardSkeleton'
import { ProjectDetailSheet } from '@/components/ProjectDetailSheet'
import { SourceComboBox } from '@/components/SourceComboBox'
import {
  useProjects,
  useSources,
  type Project,
  type ProjectStatus,
} from '@/hooks/use-projects'
import { useAds } from '@/hooks/use-ads'
import DynamicAdSlot from '@/components/DynamicAdSlot'
import ocnLogo from '@/assets/ocn-logo.png'

const PAGE_SIZE = 6

const STATUS_TABS: { value: ProjectStatus; label: string; count?: boolean }[] = [
  { value: 'open', label: 'Open' },
  { value: 'awarded', label: 'Awarded' },
  { value: 'expired', label: 'Expired' },
]

export default function ProjectSearch() {
  /* ── local state ─────────────────────────────────────────────────── */
  const [keywordInput, setKeywordInput] = useState('')
  const [debouncedKeyword, setDebouncedKeyword] = useState('')
  const [source, setSource] = useState('')
  const [status, setStatus] = useState<ProjectStatus>('open')
  const [page, setPage] = useState(() => {
    const saved = Number(localStorage.getItem('ocn-projects-page') || '1')
    return Number.isFinite(saved) && saved > 0 ? saved : 1
  })
  const [selectedProject, setSelectedProject] = useState<Project | null>(null)
  const [sheetOpen, setSheetOpen] = useState(false)

  /* ── debounce keyword ────────────────────────────────────────────── */
  useEffect(() => {
    const handle = window.setTimeout(() => {
      setDebouncedKeyword(keywordInput)
      setPage(1)
    }, 350)
    return () => window.clearTimeout(handle)
  }, [keywordInput])

  /* ── persist page ────────────────────────────────────────────────── */
  useEffect(() => {
    localStorage.setItem('ocn-projects-page', String(page))
  }, [page])

  /* ── data fetching ───────────────────────────────────────────────── */
  const { data: sourcesData } = useSources()
  const sources = sourcesData ?? []

  const filters = useMemo(
    () => ({ keyword: debouncedKeyword, source, status, page }),
    [debouncedKeyword, source, status, page]
  )

  const { data, isLoading, isFetching, isError } = useProjects(filters)

  const projects = data?.data ?? []
  const lastPage = data?.last_page ?? 1
  const total = data?.total ?? 0

  /* ── pagination helpers ──────────────────────────────────────────── */
  const pageRange = useMemo(() => {
    const range: Array<number | 'ellipsis'> = []
    const delta = 2

    if (lastPage <= 6) {
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
  }, [lastPage, page])

  /* ── handlers ────────────────────────────────────────────────────── */
  const handleTabChange = useCallback((value: string) => {
    setStatus(value as ProjectStatus)
    setPage(1)
  }, [])

  const handleSourceChange = useCallback((value: string) => {
    setSource(value)
    setPage(1)
  }, [])

  const handleClear = useCallback(() => {
    setKeywordInput('')
    setDebouncedKeyword('')
    setSource('')
    setPage(1)
  }, [])

  const handleCardClick = useCallback((project: Project) => {
    setSelectedProject(project)
    setSheetOpen(true)
  }, [])

  /* ── ads data (cached at layout level) ──────────────────────────── */
  const { data: ads } = useAds()

  /* ── skeleton grid while loading with no placeholderData ─────────── */
  const showSkeleton = isLoading

  return (
    <TooltipProvider delayDuration={200}>
      <div className="flex min-h-screen flex-col bg-white text-slate-900">
        {/* ── Header ─────────────────────────────────────────────── */}
        <header className="sticky top-0 z-30 border-b border-slate-100 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80">
          <div className="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-5">
            <img
              src={ocnLogo}
              alt="Ontario Construction News"
              className="h-12 w-auto sm:h-14"
            />
            {isFetching && !isLoading && (
              <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-400" />
            )}
          </div>
        </header>

        {/* ── Header Ad Slot ──────────────────────────────────── */}
        {ads?.header?.enabled && ads.header.embed_code && (
          <DynamicAdSlot placement="header" embedCode={ads.header.embed_code} />
        )}

        {/* ── Main ───────────────────────────────────────────────── */}
        <motion.main
          className="mx-auto w-full max-w-6xl flex-1 px-6 py-10"
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, ease: 'easeOut' }}
        >
          {/* Title */}
          <section className="pb-8">
            <h1 className="text-3xl font-semibold tracking-tight">
              Construction Project Opportunities
            </h1>
            <p className="mt-2 max-w-xl text-sm text-slate-500">
              Filter by source, search by keyword, and browse the latest
              publicly available construction postings in one clean view.
            </p>
          </section>

          {/* Search & Filters */}
          <section className="space-y-4 border-b border-slate-100 pb-6">
            <div className="grid gap-4 md:grid-cols-[1.2fr_0.8fr_auto]">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input
                  className="h-12 pl-10 text-base"
                  placeholder="Search keyword, location, or project title…"
                  value={keywordInput}
                  onChange={(e) => setKeywordInput(e.target.value)}
                />
                {keywordInput && (
                  <button
                    type="button"
                    className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-slate-400 hover:text-slate-600"
                    onClick={() => setKeywordInput('')}
                  >
                    <X className="h-4 w-4" />
                  </button>
                )}
              </div>

              <SourceComboBox
                sources={sources}
                value={source}
                onChange={handleSourceChange}
              />

              <Button
                className="h-12 px-6 text-base"
                variant="ghost"
                onClick={handleClear}
              >
                Clear
              </Button>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
              <Tabs
                value={status}
                onValueChange={handleTabChange}
              >
                <TabsList>
                  {STATUS_TABS.map((tab) => (
                    <TabsTrigger key={tab.value} value={tab.value}>
                      {tab.label}
                    </TabsTrigger>
                  ))}
                </TabsList>
              </Tabs>

              <p className="text-xs text-slate-400">
                {total.toLocaleString()} projects &middot; Page {page} of{' '}
                {lastPage}
              </p>
            </div>
          </section>

          {/* Results Grid */}
          <section className="pb-8 pt-6">
            {isError && (
              <div className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                Unable to load projects right now. Please try again shortly.
              </div>
            )}

            <div className="grid min-h-[400px] gap-4">
              <AnimatePresence mode="popLayout">
                {showSkeleton
                  ? Array.from({ length: PAGE_SIZE }).map((_, idx) => (
                      <motion.div
                        key={`skel-${idx}`}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.15, delay: idx * 0.04 }}
                      >
                        <ProjectCardSkeleton />
                      </motion.div>
                    ))
                  : projects.map((project) => (
                      <ProjectCard
                        key={project.id}
                        project={project}
                        tabStatus={status}
                        onClick={() => handleCardClick(project)}
                      />
                    ))}
              </AnimatePresence>

              {/* Invisible spacers to stabilize grid height */}
              {!showSkeleton &&
                projects.length > 0 &&
                projects.length < PAGE_SIZE &&
                Array.from({ length: PAGE_SIZE - projects.length }).map(
                  (_, idx) => (
                    <div
                      key={`spacer-${idx}`}
                      className="pointer-events-none h-0 opacity-0"
                      aria-hidden
                    />
                  )
                )}

              {!showSkeleton && projects.length === 0 && !isError && (
                <motion.div
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  className="flex flex-col items-center justify-center py-16 text-center"
                >
                  <Search className="mb-3 h-8 w-8 text-slate-300" />
                  <p className="text-sm text-slate-500">
                    No projects found. Try adjusting your filters.
                  </p>
                </motion.div>
              )}
            </div>
          </section>
        </motion.main>

        {/* ── Footer Ad Slot ──────────────────────────────────── */}
        {ads?.footer?.enabled && ads.footer.embed_code && (
          <DynamicAdSlot placement="footer" embedCode={ads.footer.embed_code} />
        )}

        {/* ── Footer / Pagination ───────────────────────────────── */}
        <footer className="sticky bottom-0 z-20 border-t border-slate-100 bg-white/95 shadow-[0_-8px_20px_rgba(15,23,42,0.04)] backdrop-blur">
          <div className="mx-auto grid h-20 w-full max-w-6xl grid-rows-[1fr_auto] items-center px-6">
            <div className="flex items-center justify-between gap-3">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1 || isFetching}
              >
                Previous
              </Button>

              <div className="flex flex-nowrap items-center gap-1.5 overflow-x-auto text-sm">
                {pageRange.map((item, idx) =>
                  item === 'ellipsis' ? (
                    <span
                      key={`e-${idx}`}
                      className="px-2 text-sm text-slate-400"
                    >
                      …
                    </span>
                  ) : (
                    <button
                      key={item}
                      type="button"
                      onClick={() => setPage(item)}
                      disabled={isFetching}
                      className={`rounded-full px-3 py-1 text-sm transition ${
                        item === page
                          ? 'bg-slate-900 text-white'
                          : 'text-slate-500 hover:text-slate-900'
                      }`}
                    >
                      {item}
                    </button>
                  )
                )}
              </div>

              <Button
                variant="ghost"
                size="sm"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                disabled={page >= lastPage || isFetching}
              >
                Next
              </Button>
            </div>
            <p className="pt-1 pb-2 text-center text-xs text-slate-400 hover:underline">
              <a
                href="https://www.ontarioconstructionnews.com"
                target="_blank"
                rel="noopener noreferrer"
              >
                2650547 Ontario Ltd. 2026 Ontario Construction News
              </a>
            </p>
          </div>
        </footer>

        {/* ── Side-panel Detail Sheet ───────────────────────────── */}
        <ProjectDetailSheet
          project={selectedProject}
          open={sheetOpen}
          onOpenChange={setSheetOpen}
        />
      </div>
    </TooltipProvider>
  )
}

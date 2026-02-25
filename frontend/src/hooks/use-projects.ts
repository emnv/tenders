import { useQuery } from '@tanstack/react-query'

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export type ProjectStatus = 'open' | 'awarded' | 'expired'

export type Project = {
  id: number
  title: string
  description?: string | null
  location: string | null
  source_site_name: string | null
  source_url?: string | null
  published_at: string | null
  date_closing_at?: string | null
  date_issue_at?: string | null
  date_available_at?: string | null
  solicitation_number?: string | null
  solicitation_type?: string | null
  source_status?: string | null
  buyer_name?: string | null
  buyer_email?: string | null
  buyer_phone?: string | null
  buyer_location?: string | null
  contract_duration?: string | null
  pre_bid_meeting?: string | null
  specific_conditions?: string | null
  high_level_category?: string | null
}

export type PaginatedResponse = {
  data: Project[]
  current_page: number
  last_page: number
  total: number
  per_page: number
}

export interface ProjectFilters {
  keyword: string
  source: string
  status: ProjectStatus
  page: number
}

async function fetchProjects(filters: ProjectFilters): Promise<PaginatedResponse> {
  const params = new URLSearchParams()

  if (filters.keyword.trim()) {
    params.set('q', filters.keyword.trim())
  }
  if (filters.source) {
    params.set('source', filters.source)
  }
  if (filters.status) {
    params.set('status', filters.status)
  }
  params.set('page', String(filters.page))

  const response = await fetch(`${API_BASE}/search?${params.toString()}`)

  if (!response.ok) {
    throw new Error('Failed to load projects')
  }

  return response.json()
}

async function fetchSources(): Promise<string[]> {
  const response = await fetch(`${API_BASE}/sources`)
  if (!response.ok) return []
  const payload = await response.json()
  return Array.isArray(payload.data) ? payload.data : []
}

export function useProjects(filters: ProjectFilters) {
  return useQuery({
    queryKey: ['projects', filters.keyword, filters.source, filters.status, filters.page],
    queryFn: () => fetchProjects(filters),
    placeholderData: (prev) => prev,
    staleTime: 30_000,
  })
}

export function useSources() {
  return useQuery({
    queryKey: ['sources'],
    queryFn: fetchSources,
    staleTime: 5 * 60_000,
  })
}

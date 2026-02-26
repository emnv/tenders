import { useQuery } from '@tanstack/react-query'

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export type AdSlotData = {
  enabled: boolean
  embed_code: string
}

export type ActiveAds = Record<string, AdSlotData>

async function fetchActiveAds(): Promise<ActiveAds> {
  const response = await fetch(`${API_BASE}/ads/active`)
  if (!response.ok) return {}
  return response.json()
}

/**
 * Fetches active ad placements once and caches for 5 minutes.
 * Designed to be called at the layout level so the data is shared
 * across all components without redundant network requests.
 */
export function useAds() {
  return useQuery({
    queryKey: ['ads', 'active'],
    queryFn: fetchActiveAds,
    staleTime: 5 * 60_000,      // 5 minutes before refetch
    gcTime: 10 * 60_000,        // keep in cache for 10 minutes
    retry: 1,                   // single retry on failure
    refetchOnWindowFocus: false, // no refetch on tab switch
  })
}

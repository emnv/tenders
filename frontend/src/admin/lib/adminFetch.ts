const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export const ADMIN_TOKEN_KEY = 'ocn-admin-token'

export const getAdminToken = () => localStorage.getItem(ADMIN_TOKEN_KEY)

export const setAdminToken = (token: string | null) => {
  if (token) {
    localStorage.setItem(ADMIN_TOKEN_KEY, token)
  } else {
    localStorage.removeItem(ADMIN_TOKEN_KEY)
  }
}

export const adminFetch = async (path: string, init: RequestInit = {}) => {
  const token = getAdminToken()
  const headers = new Headers(init.headers)

  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    ...init,
    headers,
  })

  return response
}

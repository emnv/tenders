import { Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import DashboardPage from './pages/DashboardPage.tsx'
import ProjectsPage from './pages/ProjectsPage.tsx'
import ScrapersPage from './pages/ScrapersPage.tsx'
import AdsPage from './pages/AdsPage.tsx'
import { AppSidebar } from '@/components/app-sidebar'
import { SiteHeader } from '@/components/site-header'
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar'
import { Toaster } from '@/components/ui/sonner'
import LoginPage from './pages/LoginPage.tsx'
import { adminFetch, getAdminToken, setAdminToken } from './lib/adminFetch'

export default function AdminApp() {
  const navigate = useNavigate()
  const location = useLocation()
  const token = getAdminToken()
  const isLoginRoute = location.pathname.startsWith('/admin/login')
  const title = (() => {
    if (location.pathname.startsWith('/admin/projects')) return 'Projects'
    if (location.pathname.startsWith('/admin/scrapers')) return 'Scrapers'
    if (location.pathname.startsWith('/admin/ads')) return 'Ads'
    return 'Dashboard'
  })()

  const handleLogout = async () => {
    try {
      await adminFetch('/admin/auth/logout', { method: 'POST' })
    } finally {
      setAdminToken(null)
      navigate('/admin/login', { replace: true })
    }
  }

  if (!token && !isLoginRoute) {
    return <Navigate to="/admin/login" replace />
  }

  return (
    <SidebarProvider>
      {!isLoginRoute && (
        <AppSidebar
          user={{ name: 'OCN Admin', email: 'ocnw@ocn.local', avatar: null }}
          onLogout={handleLogout}
          variant="inset"
        />
      )}
      <SidebarInset>
        {!isLoginRoute && <SiteHeader title={title} />}
        <div className="flex flex-1 flex-col gap-6">
          <Routes>
            <Route path="login" element={<LoginPage />} />
            <Route index element={<DashboardPage />} />
            <Route path="projects" element={<ProjectsPage />} />
            <Route path="scrapers" element={<ScrapersPage />} />
            <Route path="ads" element={<AdsPage />} />
            <Route path="*" element={<Navigate to="." replace />} />
          </Routes>
        </div>
        <Toaster />
      </SidebarInset>
    </SidebarProvider>
  )
}

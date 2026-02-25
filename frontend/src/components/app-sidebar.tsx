import * as React from "react"
import { LayoutDashboardIcon, SettingsIcon, StarIcon, WrenchIcon } from "lucide-react"

import { NavMain } from "@/components/nav-main"
import { NavUser } from "@/components/nav-user"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"
import ocnLogo from "@/assets/ocn-logo.png"

type SidebarUser = {
  name: string
  email: string
  avatar?: string | null
}

const navItems = [
  {
    title: "Dashboard",
    url: "/admin",
    icon: LayoutDashboardIcon,
  },
  {
    title: "Projects",
    url: "/admin/projects",
    icon: StarIcon,
  },
  {
    title: "Scrapers",
    url: "/admin/scrapers",
    icon: WrenchIcon,
  },
  {
    title: "Ads",
    url: "/admin/ads",
    icon: SettingsIcon,
  },
]

export function AppSidebar({ user, onLogout, ...props }: React.ComponentProps<typeof Sidebar> & { user?: SidebarUser; onLogout?: () => void }) {
  return (
    <Sidebar collapsible="offcanvas" {...props}>
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton
              asChild
              className="data-[slot=sidebar-menu-button]:!p-1.5"
            >
              <a href="/admin">
                <img src={ocnLogo} alt="Ontario Construction News" className="h-6 w-6" />
                <span className="text-base font-semibold">OCN Admin</span>
              </a>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>
      <SidebarContent>
        <NavMain items={navItems} />
      </SidebarContent>
      <SidebarFooter>
        {user && <NavUser user={user} onLogout={onLogout} />}
      </SidebarFooter>
    </Sidebar>
  )
}

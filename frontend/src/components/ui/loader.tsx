import { Loader2Icon } from "lucide-react"

import { cn } from "@/lib/utils"

type LoaderProps = {
  label?: string
  className?: string
}

export function Loader({ label = "Loading...", className }: LoaderProps) {
  return (
    <div className={cn("flex items-center gap-2 text-sm text-muted-foreground", className)}>
      <Loader2Icon className="h-4 w-4 animate-spin" />
      <span>{label}</span>
    </div>
  )
}

import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"

type SectionCardsProps = {
  totalProjects?: number
  featuredProjects?: number
  sources?: number
  recentRuns?: number
}

export function SectionCards({ totalProjects, featuredProjects, sources, recentRuns }: SectionCardsProps) {
  const cards = [
    {
      label: "Total Projects",
      value: totalProjects ?? 0,
      helper: "All scraped projects",
    },
    {
      label: "Featured Projects",
      value: featuredProjects ?? 0,
      helper: "Shown on homepage",
    },
    {
      label: "Sources",
      value: sources ?? 0,
      helper: "Active data sources",
    },
    {
      label: "Recent Runs",
      value: recentRuns ?? 0,
      helper: "Last 10 scraper runs",
    },
  ]

  return (
    <div className="*:data-[slot=card]:from-primary/5 *:data-[slot=card]:to-card dark:*:data-[slot=card]:bg-card grid grid-cols-1 gap-4 px-4 *:data-[slot=card]:bg-gradient-to-t *:data-[slot=card]:shadow-xs lg:px-6 @xl/main:grid-cols-2 @5xl/main:grid-cols-4">
      {cards.map((card) => (
        <Card key={card.label} className="@container/card">
          <CardHeader>
            <CardDescription>{card.label}</CardDescription>
            <CardTitle className="@[250px]/card:text-3xl text-2xl font-semibold tabular-nums">
              {card.value}
            </CardTitle>
            <p className="text-muted-foreground text-xs">{card.helper}</p>
          </CardHeader>
        </Card>
      ))}
    </div>
  )
}

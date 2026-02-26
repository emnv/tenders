import { useEffect, useRef, memo } from 'react'

interface DynamicAdSlotProps {
  /** The raw HTML + script embed code from the admin panel */
  embedCode: string
  /** Placement identifier for styling / debugging */
  placement: 'header' | 'footer'
}

/* ── GPT helpers ──────────────────────────────────────────────────── */

/**
 * Extract `div-gpt-*` IDs referenced in `googletag.defineSlot(…)`
 * calls.  These are the IDs GPT expects as target containers.
 */
function extractDefineSlotDivIds(html: string): string[] {
  const ids = new Set<string>()
  // defineSlot('/network/unit', [w,h], 'div-gpt-ad-xxx')
  const re = /defineSlot\s*\([^)]*['"](\bdiv-gpt-[^'"]+)['"]/g
  let m: RegExpExecArray | null
  while ((m = re.exec(html)) !== null) ids.add(m[1])
  return Array.from(ids)
}

/**
 * Extract `div-gpt-*` IDs from `id="…"` attributes in the HTML
 * (i.e. the actual container divs the admin may have included).
 */
function extractHtmlDivIds(html: string): Set<string> {
  const ids = new Set<string>()
  const re = /id\s*=\s*["'](div-gpt-[^"']+)["']/gi
  let m: RegExpExecArray | null
  while ((m = re.exec(html)) !== null) ids.add(m[1])
  return ids
}

/**
 * Check whether `googletag.display('divId')` is already called
 * somewhere in the embed code for a given div ID.
 */
function hasDisplayCall(html: string, divId: string): boolean {
  // Match googletag.display('div-gpt-ad-xxx') with either quote style
  return html.includes(`display('${divId}')`) ||
         html.includes(`display("${divId}")`)
}

/**
 * Destroy any GPT slots registered against the given div IDs.
 * Pushes to `googletag.cmd` so it works whether gpt.js has loaded or not.
 */
function destroyGptSlotsForDivs(divIds: string[]): void {
  if (divIds.length === 0) return

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const gt = (window as unknown as Record<string, any>).googletag
  if (!gt) return

  gt.cmd = gt.cmd || []
  gt.cmd.push(() => {
    try {
      const allSlots: { getSlotElementId(): string }[] = gt.pubads().getSlots()
      const toDestroy = allSlots.filter((s) =>
        divIds.includes(s.getSlotElementId()),
      )
      if (toDestroy.length > 0) {
        gt.destroySlots(toDestroy)
      }
    } catch {
      // pubads not ready
    }
  })
}

/**
 * Check whether an external script with the given `src` is already
 * present in the document.
 */
function isScriptAlreadyLoaded(src: string): boolean {
  try {
    const absolute = new URL(src, document.baseURI).href
    const scripts = document.querySelectorAll<HTMLScriptElement>('script[src]')
    for (const s of scripts) {
      try {
        if (new URL(s.src, document.baseURI).href === absolute) return true
      } catch { /* skip */ }
    }
  } catch { /* malformed */ }
  return false
}

/**
 * Renders raw HTML embed code (e.g. Google Publisher Tags) and ensures
 * `<script>` tags are actually executed.
 *
 * Handles the common case where the admin only pastes the GPT "header"
 * portion (defineSlot + enableServices) without the target `<div>` and
 * `googletag.display()` call.  The component auto-generates missing
 * container divs and display calls so the ad actually renders.
 */
function DynamicAdSlotInner({ embedCode, placement }: DynamicAdSlotProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const injectedRef = useRef<HTMLScriptElement[]>([])

  // IDs that defineSlot targets
  const slotDivIds = extractDefineSlotDivIds(embedCode)
  // IDs that already exist as <div> elements in the embed code
  const existingDivIds = extractHtmlDivIds(embedCode)

  // Strip <script> tags — they'll be injected manually in the effect.
  let htmlWithoutScripts = embedCode.replace(
    /<script[\s\S]*?<\/script>/gi,
    '',
  )

  // Auto-generate any missing target divs.
  // Google's code generator gives you TWO parts:
  //   Part 1 (head):   <script>…defineSlot(…, 'div-gpt-ad-xxx')…</script>
  //   Part 2 (body):   <div id="div-gpt-ad-xxx">…display(…)…</div>
  // Admins often paste only Part 1.  We auto-create Part 2.
  const missingDivIds = slotDivIds.filter((id) => !existingDivIds.has(id))
  for (const id of missingDivIds) {
    htmlWithoutScripts += `<div id="${id}" style="min-width:728px;min-height:90px;margin:0 auto;"></div>`
  }

  useEffect(() => {
    if (!embedCode || !containerRef.current) return

    const allDivIds = slotDivIds

    // 1. Destroy pre-existing GPT slots
    destroyGptSlotsForDivs(allDivIds)

    // 2. Inject <script> tags
    const doc = new DOMParser().parseFromString(embedCode, 'text/html')
    const scriptNodes = doc.querySelectorAll('script')
    const created: HTMLScriptElement[] = []

    scriptNodes.forEach((original) => {
      const src = original.getAttribute('src')
      if (src && isScriptAlreadyLoaded(src)) return

      const script = document.createElement('script')
      Array.from(original.attributes).forEach((attr) => {
        script.setAttribute(attr.name, attr.value)
      })
      if (original.textContent) {
        script.textContent = original.textContent
      }
      containerRef.current!.appendChild(script)
      created.push(script)
    })

    // 3. Auto-call googletag.display() for any div IDs that the embed
    //    code doesn't already display.  This is what actually tells GPT
    //    "render the ad into this div".
    const idsNeedingDisplay = allDivIds.filter(
      (id) => !hasDisplayCall(embedCode, id),
    )
    if (idsNeedingDisplay.length > 0) {
      const displayScript = document.createElement('script')
      displayScript.textContent = `
        window.googletag = window.googletag || {cmd:[]};
        googletag.cmd.push(function() {
          ${idsNeedingDisplay.map((id) => `googletag.display('${id}');`).join('\n          ')}
        });
      `
      containerRef.current.appendChild(displayScript)
      created.push(displayScript)
    }

    injectedRef.current = created

    // ── Cleanup ─────────────────────────────────────────────────
    return () => {
      destroyGptSlotsForDivs(allDivIds)
      created.forEach((s) => {
        try { s.parentNode?.removeChild(s) } catch { /* ok */ }
      })
      injectedRef.current = []
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [embedCode])

  return (
    <div
      ref={containerRef}
      data-ad-placement={placement}
      className="min-h-[90px] w-full flex justify-center items-center bg-gray-50"
      dangerouslySetInnerHTML={{ __html: htmlWithoutScripts }}
    />
  )
}

const DynamicAdSlot = memo(DynamicAdSlotInner)
export default DynamicAdSlot

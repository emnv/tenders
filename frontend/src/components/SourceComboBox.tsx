import { useState } from 'react'
import { Check, ChevronsUpDown } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'

interface SourceComboBoxProps {
  sources: string[]
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  triggerClassName?: string
}

export function SourceComboBox({
  sources,
  value,
  onChange,
  disabled,
  triggerClassName,
}: SourceComboBoxProps) {
  const [open, setOpen] = useState(false)

  const displayLabel = value || 'All sources'

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn(
            'h-12 w-full justify-between text-base font-normal',
            triggerClassName,
          )}
        >
          <span className="truncate">{displayLabel}</span>
          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput placeholder="Search sources…" />
          <CommandList>
            <CommandEmpty>No source found.</CommandEmpty>
            <CommandGroup>
              <CommandItem
                value="__all__"
                onSelect={() => {
                  onChange('')
                  setOpen(false)
                }}
              >
                <Check
                  className={cn(
                    'mr-2 h-4 w-4',
                    !value ? 'opacity-100' : 'opacity-0'
                  )}
                />
                All sources
              </CommandItem>
              {sources.map((source) => (
                <CommandItem
                  key={source}
                  value={source}
                  onSelect={() => {
                    onChange(source === value ? '' : source)
                    setOpen(false)
                  }}
                >
                  <Check
                    className={cn(
                      'mr-2 h-4 w-4',
                      value === source ? 'opacity-100' : 'opacity-0'
                    )}
                  />
                  {source}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}

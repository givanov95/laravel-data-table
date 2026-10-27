<!--
  Published from givanov95/laravel-data-table (php artisan vendor:publish --tag=data-table-shadcn).
  Change it in the package and re-publish with --force: local edits are overwritten.
-->
<script setup lang="ts" generic="TData extends RowData">
import type {
  PaginationState,
  RowData,
  SortingState,
  Updater,
} from "@tanstack/vue-table"
import { FlexRender, useTable } from "@tanstack/vue-table"
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from "@lucide/vue"
import { computed } from "vue"
import type { LaravelDataTableMeta } from "@givanov95/vue-data-table/tanstack"
import { t } from "@givanov95/vue-data-table/tanstack"
import { Button } from "@/components/ui/button"
import type { DataTableColumnDef } from "./features"
import { dataTableFeatures } from "./features"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

// The page spreads the whole `useLaravelDataTable()` object (`v-bind="table"`):
// what is not a prop here (propName, clearSearch…) must not land on the DOM.
defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  columns: DataTableColumnDef<TData, any>[]
  data: TData[]
  rowCount: number
  pageCount: number
  meta: LaravelDataTableMeta
  state: {
    pagination: PaginationState
    sorting: SortingState
    globalFilter: string
  }
  onPaginationChange: (updater: Updater<PaginationState>) => void
  onSortingChange: (updater: Updater<SortingState>) => void
  onGlobalFilterChange: (value: string) => void
  /** Requested by the URL (`null` = the server default); the select shows `meta.per_page`, what the server used. */
  perPage: number | null
  perPageOptions: number[]
  onPerPageChange: (value: number | null) => void
  trashed: boolean
  onTrashedChange: (value: boolean) => void
  goToPage: (page: number) => void
  showTrashed?: boolean
  /** Default: t("Search…"). */
  searchPlaceholder?: string
  /** Default: t("No results."). */
  emptyText?: string
  /** Default: t("Show archived"). */
  trashedLabel?: string
  /** Extra classes on every body cell, e.g. `align-top` for tall rows. */
  cellClass?: string
}>(), {
  showTrashed: false,
})

const table = useTable({
  features: dataTableFeatures,
  get data() {
    return props.data
  },
  get columns() {
    return props.columns
  },
  manualPagination: true,
  manualSorting: true,
  manualFiltering: true,
  get rowCount() {
    return props.rowCount
  },
  get pageCount() {
    return props.pageCount
  },
  state: {
    get pagination() {
      return props.state.pagination
    },
    get sorting() {
      return props.state.sorting
    },
    get globalFilter() {
      return props.state.globalFilter
    },
  },
  onPaginationChange: (updater) => props.onPaginationChange(updater),
  onSortingChange: (updater) => props.onSortingChange(updater),
  onGlobalFilterChange: (updater) => props.onGlobalFilterChange(
    typeof updater === "function" ? updater(props.state.globalFilter) : updater,
  ),
})

// The rows per page the server actually used: its default without `?perPage`,
// or the requested value (capped by `max_per_page`).
const perPageModel = computed(() => String(props.meta.per_page))

// A value outside the options (e.g. an old `?perPage=25` link) is listed too,
// so the select never shows up empty.
const perPageChoices = computed(() =>
  [...new Set([...props.perPageOptions, props.meta.per_page])].sort((a, b) => a - b),
)

function handlePerPageChange(value: unknown) {
  props.onPerPageChange(Number(value))
}

const PAGE_RANGE = 2

const currentPage = computed(() => props.meta.current_page)
const lastPage = computed(() => props.meta.last_page)
const showingFrom = computed(() => props.meta.from)
const showingTo = computed(() => props.meta.to)

/** Page numbers around the current page, clamped to `[1, lastPage]`. */
const pageNumbers = computed(() => {
  const start = Math.max(currentPage.value - PAGE_RANGE, 1)
  const end = Math.min(currentPage.value + PAGE_RANGE, lastPage.value)
  const pages: number[] = []

  for (let p = start; p <= end; p++) {
    pages.push(p)
  }

  return pages
})

const showLeadingJump = computed(() => currentPage.value - PAGE_RANGE > 1)
const showTrailingJump = computed(() => currentPage.value + PAGE_RANGE < lastPage.value)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
      <Input
        :model-value="state.globalFilter"
        :placeholder="searchPlaceholder ?? t('Search…')"
        class="max-w-sm"
        @update:model-value="onGlobalFilterChange(String($event))"
      />

      <slot name="toolbar" :table="table" />

      <div class="ml-auto flex items-center gap-4">
        <div v-if="showTrashed" class="flex items-center gap-2">
          <Switch
            id="server-data-table-trashed"
            :model-value="trashed"
            @update:model-value="onTrashedChange(Boolean($event))"
          />
          <Label for="server-data-table-trashed" class="text-sm">
            {{ trashedLabel ?? t('Show archived') }}
          </Label>
        </div>

        <Select :model-value="perPageModel" @update:model-value="handlePerPageChange">
          <SelectTrigger size="sm" class="w-[8rem]" :aria-label="t('Rows per page')">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in perPageChoices"
              :key="option"
              :value="String(option)"
            >
              {{ option }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>

    <div class="rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
            <TableHead v-for="header in headerGroup.headers" :key="header.id" :colspan="header.colSpan">
              <FlexRender v-if="!header.isPlaceholder" :header="header" />
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <template v-if="table.getRowModel().rows.length">
            <TableRow v-for="row in table.getRowModel().rows" :key="row.id">
              <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id" :class="cellClass">
                <FlexRender :cell="cell" />
              </TableCell>
            </TableRow>
          </template>
          <TableEmpty v-else :colspan="table.getAllColumns().length">
            {{ emptyText ?? t('No results.') }}
          </TableEmpty>
        </TableBody>
      </Table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-muted-foreground text-sm">
        {{ t("Showing :from–:to of :total", { from: showingFrom, to: showingTo, total: rowCount }) }}
      </p>

      <div v-if="lastPage > 1" class="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage <= 1"
          @click="goToPage(1)"
          :aria-label="t('First page')"
        >
          <ChevronsLeft />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage <= 1"
          @click="goToPage(currentPage - 1)"
          :aria-label="t('Previous page')"
        >
          <ChevronLeft />
        </Button>

        <span v-if="showLeadingJump" class="text-muted-foreground px-1">…</span>

        <Button
          v-for="p in pageNumbers"
          :key="p"
          :variant="p === currentPage ? 'default' : 'outline'"
          size="icon-sm"
          @click="goToPage(p)"
        >
          {{ p }}
        </Button>

        <span v-if="showTrailingJump" class="text-muted-foreground px-1">…</span>

        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage >= lastPage"
          @click="goToPage(currentPage + 1)"
          :aria-label="t('Next page')"
        >
          <ChevronRight />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage >= lastPage"
          @click="goToPage(lastPage)"
          :aria-label="t('Last page')"
        >
          <ChevronsRight />
        </Button>
      </div>
    </div>
  </div>
</template>

<!--
  Published from givanov95/laravel-data-table (php artisan vendor:publish --tag=data-table-shadcn).
  Change it in the package and re-publish with --force: local edits are overwritten.
-->
<script setup lang="ts" generic="TData extends RowData">
import type { ColumnVisibilityState, PaginationState, RowData, SortingState, Updater } from "@tanstack/vue-table"
import { FlexRender, useTable } from "@tanstack/vue-table"
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, SlidersHorizontal } from "@lucide/vue"
import type { Ref } from "vue"
import { computed, ref, watch } from "vue"
import { t } from "@givanov95/vue-data-table/tanstack"
import type { DataTableColumnDef } from "./features"
import { dataTableFeatures } from "./features"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

const props = withDefaults(defineProps<{
  columns: DataTableColumnDef<TData, any>[]
  data: TData[]
  /** Default: t("Search…"). */
  searchPlaceholder?: string
  enableGlobalFilter?: boolean
  enableColumnToggle?: boolean
  /** Rows per page at first. */
  pageSize?: number
  /** Offered by the per-page select (`pageSize` is always added); `[]` hides the select. */
  pageSizeOptions?: number[]
  /** Default: t("No results."). */
  emptyText?: string
  /** Default: t("Columns"). */
  columnsLabel?: string
}>(), {
  enableGlobalFilter: true,
  enableColumnToggle: true,
  pageSize: 10,
  pageSizeOptions: () => [10, 20, 50, 100],
})

function valueUpdater<T>(updaterOrValue: Updater<T>, ref: Ref<T>) {
  ref.value = typeof updaterOrValue === "function"
    ? (updaterOrValue as (old: T) => T)(ref.value)
    : updaterOrValue
}

const sorting = ref<SortingState>([])
const columnVisibility = ref<ColumnVisibilityState>({})
const globalFilter = ref("")
const pagination = ref<PaginationState>({ pageIndex: 0, pageSize: props.pageSize })

// A new search starts on the first page, like the server table.
watch(globalFilter, () => {
  pagination.value = { ...pagination.value, pageIndex: 0 }
})

const table = useTable({
  features: dataTableFeatures,
  get data() {
    return props.data
  },
  get columns() {
    return props.columns
  },
  onSortingChange: (updater) => valueUpdater(updater, sorting),
  onColumnVisibilityChange: (updater) => valueUpdater(updater, columnVisibility),
  onGlobalFilterChange: (updater) => valueUpdater(updater, globalFilter),
  onPaginationChange: (updater) => valueUpdater(updater, pagination),
  state: {
    get pagination() {
      return pagination.value
    },
    get sorting() {
      return sorting.value
    },
    get columnVisibility() {
      return columnVisibility.value
    },
    get globalFilter() {
      return globalFilter.value
    },
  },
})

// The same footer as ServerDataTable, counted over the filtered rows.
const PAGE_RANGE = 2

// Plain functions, read on every render: the row model follows the data, the
// search and the sorting, which a cached computed would not all see.
const total = () => table.getFilteredRowModel().rows.length
const lastPage = () => Math.max(Math.ceil(total() / pagination.value.pageSize), 1)
const currentPage = () => Math.min(pagination.value.pageIndex + 1, lastPage())
const showingFrom = () => (total() === 0 ? 0 : (currentPage() - 1) * pagination.value.pageSize + 1)
const showingTo = () => Math.min(currentPage() * pagination.value.pageSize, total())

function pageNumbers(): number[] {
  const start = Math.max(currentPage() - PAGE_RANGE, 1)
  const end = Math.min(currentPage() + PAGE_RANGE, lastPage())

  return Array.from({ length: end - start + 1 }, (_, i) => start + i)
}

function goToPage(page: number) {
  pagination.value = { ...pagination.value, pageIndex: Math.min(Math.max(page, 1), lastPage()) - 1 }
}

const pageSizeChoices = computed(() =>
  [...new Set([...props.pageSizeOptions, pagination.value.pageSize])].sort((a, b) => a - b),
)

// Back to the first page: the current one may not exist at the new size.
function setPageSize(value: unknown) {
  pagination.value = { pageIndex: 0, pageSize: Number(value) }
}
</script>

<template>
  <div class="space-y-4">
    <div
      v-if="enableGlobalFilter || enableColumnToggle || $slots.toolbar || pageSizeOptions.length"
      class="flex flex-wrap items-center gap-2"
    >
      <Input
        v-if="enableGlobalFilter"
        :model-value="globalFilter"
        :placeholder="searchPlaceholder ?? t('Search…')"
        class="max-w-sm"
        @update:model-value="globalFilter = String($event)"
      />

      <slot name="toolbar" :table="table" />

      <div class="ml-auto flex items-center gap-2">
        <DropdownMenu v-if="enableColumnToggle">
          <DropdownMenuTrigger as-child>
            <Button variant="outline">
              <SlidersHorizontal />
              {{ columnsLabel ?? t('Columns') }}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuCheckboxItem
              v-for="column in table.getAllColumns().filter((c) => c.getCanHide())"
              :key="column.id"
              class="capitalize"
              :model-value="column.getIsVisible()"
              @update:model-value="(value) => column.toggleVisibility(!!value)"
            >
              {{ column.id }}
            </DropdownMenuCheckboxItem>
          </DropdownMenuContent>
        </DropdownMenu>

        <Select
          v-if="pageSizeOptions.length"
          :model-value="String(pagination.pageSize)"
          @update:model-value="setPageSize"
        >
          <SelectTrigger size="sm" class="w-[8rem]" :aria-label="t('Rows per page')">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="option in pageSizeChoices" :key="option" :value="String(option)">
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
            <TableRow
              v-for="row in table.getRowModel().rows"
              :key="row.id"
              :data-state="row.getIsSelected() ? 'selected' : undefined"
            >
              <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id">
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
        {{ t("Showing :from–:to of :total", { from: showingFrom(), to: showingTo(), total: total() }) }}
      </p>

      <div v-if="lastPage() > 1" class="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage() <= 1"
          :aria-label="t('First page')"
          @click="goToPage(1)"
        >
          <ChevronsLeft />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage() <= 1"
          :aria-label="t('Previous page')"
          @click="goToPage(currentPage() - 1)"
        >
          <ChevronLeft />
        </Button>

        <span v-if="currentPage() - PAGE_RANGE > 1" class="text-muted-foreground px-1">…</span>

        <Button
          v-for="p in pageNumbers()"
          :key="p"
          :variant="p === currentPage() ? 'default' : 'outline'"
          size="icon-sm"
          @click="goToPage(p)"
        >
          {{ p }}
        </Button>

        <span v-if="currentPage() + PAGE_RANGE < lastPage()" class="text-muted-foreground px-1">…</span>

        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage() >= lastPage()"
          :aria-label="t('Next page')"
          @click="goToPage(currentPage() + 1)"
        >
          <ChevronRight />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="currentPage() >= lastPage()"
          :aria-label="t('Last page')"
          @click="goToPage(lastPage())"
        >
          <ChevronsRight />
        </Button>
      </div>
    </div>
  </div>
</template>

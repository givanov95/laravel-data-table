<!--
  Published from givanov95/laravel-data-table (php artisan vendor:publish --tag=data-table-shadcn).
  Change it in the package and re-publish with --force: local edits are overwritten.
-->
<script setup lang="ts" generic="TData extends RowData">
import type { ColumnVisibilityState, RowData, SortingState, Updater } from "@tanstack/vue-table"
import { FlexRender, useTable } from "@tanstack/vue-table"
import { ChevronLeft, ChevronRight, SlidersHorizontal } from "@lucide/vue"
import type { Ref } from "vue"
import { ref } from "vue"
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
  pageSize?: number
  /** Default: t("No results."). */
  emptyText?: string
  /** Default: t("Columns"). */
  columnsLabel?: string
}>(), {
  enableGlobalFilter: true,
  enableColumnToggle: true,
  pageSize: 10,
})

function valueUpdater<T>(updaterOrValue: Updater<T>, ref: Ref<T>) {
  ref.value = typeof updaterOrValue === "function"
    ? (updaterOrValue as (old: T) => T)(ref.value)
    : updaterOrValue
}

const sorting = ref<SortingState>([])
const columnVisibility = ref<ColumnVisibilityState>({})
const globalFilter = ref("")

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
  initialState: {
    pagination: { pageIndex: 0, pageSize: props.pageSize },
  },
  state: {
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
</script>

<template>
  <div class="space-y-4">
    <div v-if="enableGlobalFilter || enableColumnToggle || $slots.toolbar" class="flex items-center gap-2">
      <Input
        v-if="enableGlobalFilter"
        :model-value="globalFilter"
        :placeholder="searchPlaceholder ?? t('Search…')"
        class="max-w-sm"
        @update:model-value="globalFilter = String($event)"
      />

      <slot name="toolbar" :table="table" />

      <DropdownMenu v-if="enableColumnToggle">
        <DropdownMenuTrigger as-child>
          <Button variant="outline" class="ml-auto">
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

    <div class="flex items-center justify-between gap-2">
      <p class="text-muted-foreground text-sm">
        {{ table.getFilteredRowModel().rows.length }}
      </p>
      <div class="flex items-center gap-2">
        <span class="text-muted-foreground text-sm">
          {{ table.atoms.pagination.get().pageIndex + 1 }} / {{ Math.max(table.getPageCount(), 1) }}
        </span>
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="!table.getCanPreviousPage()"
          :aria-label="t('Previous page')"
          @click="table.previousPage()"
        >
          <ChevronLeft />
        </Button>
        <Button
          variant="outline"
          size="icon-sm"
          :disabled="!table.getCanNextPage()"
          :aria-label="t('Next page')"
          @click="table.nextPage()"
        >
          <ChevronRight />
        </Button>
      </div>
    </div>
  </div>
</template>

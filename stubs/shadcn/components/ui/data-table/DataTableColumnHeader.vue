<!--
  Published from givanov95/laravel-data-table (php artisan vendor:publish --tag=data-table-shadcn).
  Change it in the package and re-publish with --force: local edits are overwritten.
-->
<script setup lang="ts">
import type { Column, RowData } from "@tanstack/vue-table"
import { ArrowDown, ArrowUp, ChevronsUpDown } from "@lucide/vue"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import type { DataTableFeatures } from "./features"

const props = defineProps<{
  /**
   * Only the sorting API is needed, so the prop is narrowed to it: a full
   * `Column<…, TData>` is invariant in its row type and would reject every
   * column coming from an `h()`-built header.
   */
  column: Pick<
    Column<DataTableFeatures, RowData, unknown>,
    "getCanSort" | "getIsSorted" | "toggleSorting"
  >
  title: string
  class?: string
}>()
</script>

<template>
  <div :class="cn('flex items-center', props.class)">
    <Button
      v-if="column.getCanSort()"
      variant="ghost"
      size="sm"
      class="-ml-3 h-8 data-[state=open]:bg-accent"
      @click="column.toggleSorting(column.getIsSorted() === 'asc')"
    >
      <span>{{ title }}</span>
      <ArrowUp v-if="column.getIsSorted() === 'asc'" />
      <ArrowDown v-else-if="column.getIsSorted() === 'desc'" />
      <ChevronsUpDown v-else class="opacity-50" />
    </Button>
    <span v-else>{{ title }}</span>
  </div>
</template>

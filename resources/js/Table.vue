<script setup lang="ts">
import { router } from "@inertiajs/vue3";

import { r, t } from "./config";
import GlobalSearch from "./components/GlobalSearch.vue";
import Pagination from "./components/Pagination.vue";
import TrashedRecords from "./components/TrashedRecords.vue";
import IconRestore from "./icons/Restore.vue";
import IconTableArrowDown from "./icons/TableArrowDown.vue";
import IconTableArrowUp from "./icons/TableArrowUp.vue";
import type { DataTable, ThisRoute } from "./types";
import {
    limitCharacters,
    relationWithColumn,
    replacePlaceholder,
} from "./utils";

const props = withDefaults(
    defineProps<{
        dataTable: DataTable<any>;
        /** Inertia prop key the table reloads when re-fetching. */
        propName?: string;
        globalSearch?: boolean;
        showTrashed?: boolean;
        advancedFilters?: boolean;
        selectedRowIndexes?: Array<string | number>;
        selectedRowColumn?: string;
        rowClickLink?: string;
        perPageOptions?: number[];
    }>(),
    {
        propName: "dataTable",
    },
);

const thisRoute = (r() as ThisRoute | undefined) ?? { params: {} };

const isTrashed = (): boolean => {
    return (
        !!thisRoute.params?.filter?.trashed &&
        thisRoute.params.filter.trashed === "true"
    );
};

let previousOrderingKey: string | null = null;
let previousOrderingDirection: string | null = null;

const isRowClickLinkSet: boolean = props.rowClickLink
    ? props.rowClickLink.includes("?id")
    : false;

const getRelationData = (
    rowData: Record<string, any>,
    relationTable: string,
    relationColumn: string,
): string => {
    const table = rowData[relationTable];

    if (table !== undefined && table[relationColumn] !== undefined) {
        return table[relationColumn] === "" ? "" : table[relationColumn];
    }

    return "Undefined relation";
};

const handleRowClick = (event: MouseEvent, dataId: number) => {
    if (
        typeof props.rowClickLink !== "undefined" &&
        !isTrashed() &&
        isRowClickLinkSet &&
        ["DIV", "TD"].includes((event.target as HTMLElement).tagName)
    ) {
        router.get(replacePlaceholder(props.rowClickLink as string, dataId));
    }
};

const handleSort = async (isOrderable: boolean, key: string) => {
    if (!isOrderable) return;

    let orderingDirection =
        (thisRoute.params as any)?.ordering?.direction || "desc";

    if (key === previousOrderingKey) {
        orderingDirection =
            previousOrderingDirection === "asc" ? "desc" : "asc";
    }

    await new Promise((resolve, reject) => {
        router.reload({
            data: {
                ordering: { key, direction: orderingDirection },
            },
            only: [props.propName],
            onSuccess: resolve,
            onError: reject,
        });
    });

    previousOrderingKey = key;
    previousOrderingDirection = orderingDirection;
};

const handleRestoreRecord = async (id: number) => {
    await new Promise((resolve, reject) => {
        router.reload({
            data: { restore_id: id },
            only: [props.propName],
            onSuccess: resolve,
            onError: reject,
        });
    });

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.delete("restore_id");
    router.replace(currentUrl.toString());
};
</script>

<template>
    <div
        v-if="globalSearch || $slots.advancedFilters || $slots.additionalContent"
        class="bg-white border border-[#E9E7E7] rounded-lg p-4 mb-4 sm:flex items-center justify-between shadow"
    >
        <GlobalSearch v-if="globalSearch" :prop-name="propName" />

        <TrashedRecords v-if="showTrashed" :prop-name="propName" />

        <div
            v-if="$slots.advancedFilters || $slots.additionalContent"
            class="sm:flex items-center gap-2"
        >
            <slot name="advancedFilters" />
            <slot name="additionalContent" />
        </div>
    </div>

    <div class="table-container max-w-full overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs uppercase text-black bg-[#F0F0F0]">
                <tr>
                    <th
                        v-for="(column, key) in dataTable.columns"
                        :key="String(key)"
                        scope="col"
                        class="px-6 py-3 border-r"
                        :class="column.orderable ? 'cursor-pointer' : 'cursor-default'"
                        @click="handleSort(column.orderable, String(key))"
                    >
                        <div class="w-full flex items-center gap-1.5">
                            <span>{{ column.label }}</span>

                            <span v-if="column.orderable">
                                <IconTableArrowUp />
                                <IconTableArrowDown />
                            </span>
                        </div>
                    </th>
                </tr>
            </thead>

            <tbody>
                <template v-if="dataTable.data.length > 0">
                    <tr
                        v-for="(rowData, rowIndex) in dataTable.data"
                        :key="rowIndex"
                        :class="[
                            rowIndex !== dataTable.data.length - 1 ? 'border-b' : '',
                            selectedRowIndexes &&
                            selectedRowColumn &&
                            (selectedRowIndexes.includes(Number(rowData[selectedRowColumn])) ||
                                selectedRowIndexes.includes(rowData[selectedRowColumn]))
                                ? 'bg-blue-400 text-white'
                                : 'bg-white',
                            { 'cursor-pointer': isRowClickLinkSet && !isTrashed() },
                        ]"
                        @click="handleRowClick($event, rowData.id)"
                    >
                        <td
                            v-for="(column, key) in dataTable.columns"
                            :key="String(key)"
                            class="whitespace-nowrap px-6 py-3.5"
                        >
                            <div
                                v-if="
                                    column.relation &&
                                    $slots[
                                        `cell(${relationWithColumn(column.relationWithColumn ?? [])})`
                                    ]
                                "
                            >
                                <slot
                                    :name="`cell(${relationWithColumn(column.relationWithColumn ?? [])})`"
                                    :value="rowData[column.relation as unknown as string]"
                                    :item="rowData"
                                />
                            </div>

                            <div
                                v-else-if="
                                    column.relation && !$slots[`cell(${String(key)})`]
                                "
                            >
                                {{
                                    getRelationData(
                                        rowData,
                                        column.relation.relationTable,
                                        column.relation.relationColumn,
                                    )
                                }}
                            </div>

                            <div v-if="$slots[`cell(${String(key)})`] && !isTrashed()">
                                <slot
                                    :name="`cell(${String(key)})`"
                                    :value="rowData[key]"
                                    :item="rowData"
                                />
                            </div>

                            <div
                                v-else-if="
                                    $slots[`cell(${String(key)})`] && isTrashed() && key !== 'action'
                                "
                            >
                                <slot
                                    :name="`cell(${String(key)})`"
                                    :value="rowData[key]"
                                    :item="rowData"
                                />
                            </div>

                            <div
                                v-else-if="
                                    $slots[`cell(${String(key)})`] && isTrashed() && key === 'action'
                                "
                            >
                                <button
                                    class="border border-[#E9E7E7] rounded-md p-1 active:scale-90 transition bg-blue-100 text-blue-500 duration-300 ease-in-out hover:bg-blue-200"
                                    :title="t('Restore')"
                                    @click="handleRestoreRecord(rowData['id'])"
                                >
                                    <IconRestore classes="w-4 h-4" />
                                </button>
                            </div>

                            <div v-else>
                                {{ limitCharacters(rowData[key], 35) }}
                            </div>
                        </td>
                    </tr>
                </template>

                <tr v-else>
                    <td
                        class="bg-white text-center py-5 text-lg font-semibold"
                        :colspan="Object.keys(props.dataTable.columns).length"
                    >
                        {{ t("No found data") }}
                    </td>
                </tr>
            </tbody>

            <tfoot>
                <tr>
                    <td
                        class="bg-[#F0F0F0]"
                        :colspan="Object.keys(props.dataTable.columns).length"
                    >
                        <Pagination
                            v-if="dataTable.data.length > 1 || dataTable.paginator.currentPage > 1"
                            :paginator="dataTable.paginator"
                            :prop-name="propName"
                            :per-page-options="perPageOptions"
                        />
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>

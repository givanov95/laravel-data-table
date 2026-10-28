export interface ColumnRelation {
    relation: string[];
    relationColumn: string;
    relationString: string;
    relationTable: string;
    relationWithColumn: string[];
    relationsArray: string[];
}

export interface DataTableColumn {
    label: string;
    searchable: boolean;
    orderable: boolean;
    exactMatch?: boolean;
    relation?: ColumnRelation;
    relationWithColumn?: string[];
}

export type ColumnMap = Record<string, DataTableColumn>;

export interface Paginator {
    itemsLength: number;
    perPage: number;
    /** Map of page number → URL. */
    links: Record<string | number, string>;
    currentPage: number;
    lastPage: number;
    lastPageUrl: string;
    pagesRange: number;
}

/** The request keys of the server's config/data-table.php (dotted: `filter.trashed`). */
export interface RequestKeys {
    per_page: string;
    global_filter: string;
    trashed: string;
    ordering: string;
    [key: string]: string;
}

export interface DataTable<TData = Record<string, unknown>> {
    data: TData[];
    paginator: Paginator;
    columns: ColumnMap;
    /** Sent by v4+. */
    requestKeys?: RequestKeys;
    /** Whether the rows are the archive, as the server applied it (v4+). */
    trashed?: boolean;
    /** Whether this user may see the archive at all (v4.2+). */
    trashedAllowed?: boolean;
    /** The model's key, the field that identifies a row (v4.0.1+; `id` otherwise). */
    keyName?: string;
}

export interface FilterValues extends Record<string, unknown> {
    filter: {
        columns: Record<string, string>;
        [key: string]: unknown;
    };
}

export interface RouteParams {
    filter?: {
        trashed?: string;
        global?: string;
        columns?: Record<string, string>;
    };
    ordering?: {
        key?: string;
        direction?: "asc" | "desc" | string;
    };
    page?: number | string;
}

export interface ThisRoute {
    params: RouteParams;
    query?: Record<string, unknown>;
    current?: () => string;
}

export interface RadioToggleInput {
    name: string;
    value: boolean;
}

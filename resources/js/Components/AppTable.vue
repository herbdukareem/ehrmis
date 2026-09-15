<script setup>
defineProps({
    headers: {
        type: Array,
        default: () => [],
    },
    rows: {
        type: Array,
        default: () => [],
    },
    rowKey: { type: String, default: 'id' },
});
</script>

<template>
    <div class="ehrmis-table-wrap">
        <div class="overflow-x-auto">
            <table class="ehrmis-table">
                <thead>
                    <tr>
                        <th
                            v-for="(header, index) in headers"
                            :key="header.key ?? index"
                            scope="col"
                        >
                            {{ header.label ?? header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="row[rowKey] ?? index"
                    >
                        <td
                            v-for="(header, cellIndex) in headers"
                            :key="`${index}-${cellIndex}`"
                        >
                            <slot :name="`cell-${header.key ?? cellIndex}`" :row="row" :value="row[header.key ?? cellIndex]">
                                <slot name="cell" :row="row" :value="row[header.key ?? cellIndex]" :column="header">
                                    {{ row[header.key ?? cellIndex] }}
                                </slot>
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

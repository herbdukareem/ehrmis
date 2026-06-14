<script setup>
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
});
</script>

<template>
    <div class="civic-table-wrap">
        <table class="civic-table">
            <thead>
                <tr><th v-for="column in columns" :key="column.key">{{ column.label }}</th></tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row[rowKey]">
                    <td v-for="column in columns" :key="column.key">
                        <slot :name="column.key" :row="row">{{ row[column.key] ?? '—' }}</slot>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td :colspan="columns.length" class="civic-empty-cell">No records match this view.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

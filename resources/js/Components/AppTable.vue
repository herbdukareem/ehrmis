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
});
</script>

<template>
    <div class="ehrmis-table-wrap">
        <div class="overflow-x-auto">
            <table class="ehrmis-table">
                <thead>
                    <tr>
                        <th
                            v-for="header in headers"
                            :key="header"
                        >
                            {{ header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="index"
                    >
                        <td
                            v-for="(value, cellIndex) in row"
                            :key="`${index}-${cellIndex}`"
                        >
                            {{ value }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import StatusPill from '../StatusPill.vue';
import { titleCase } from '../../lib/serviceReporting';

defineProps({
    template: { type: Object, required: true },
});
</script>

<template>
    <article class="civic-workspace civic-reporting-panel">
        <div class="civic-workspace-header">
            <div>
                <div class="civic-eyebrow">{{ template.code }}</div>
                <h2>{{ template.name }}</h2>
                <p class="civic-section-note">{{ template.description || 'No description provided.' }}</p>
            </div>
            <StatusPill :value="template.status" />
        </div>

        <dl class="civic-detail-grid civic-reporting-detail-grid">
            <div><dt>Owner MDA</dt><dd>{{ template.owner_mda?.name ?? 'Platform' }}</dd></div>
            <div><dt>Frequency</dt><dd>{{ titleCase(template.frequency) }}</dd></div>
            <div><dt>Deadline</dt><dd>{{ template.submission_deadline_day ? `Day ${template.submission_deadline_day}` : 'No deadline' }}</dd></div>
            <div><dt>Assignments</dt><dd>{{ template.assignments.length }}</dd></div>
        </dl>

        <section class="civic-reporting-template-detail civic-reporting-template-detail-wide">
            <div>
                <h3>Sections and indicators</h3>
                <div class="civic-reporting-builder-list">
                    <article v-for="section in template.sections" :key="section.id">
                        <strong>{{ section.title }}</strong>
                        <small>{{ section.code }}</small>
                        <ul>
                            <li v-for="indicator in section.indicators" :key="indicator.id">
                                {{ indicator.label }}
                                <span>{{ indicator.value_type }}</span>
                                <em v-if="indicator.dimensions.length">
                                    {{ indicator.dimensions.map((dimension) => `${dimension.dimension_label}: ${dimension.dimension_values.join(', ')}`).join(' / ') }}
                                </em>
                            </li>
                        </ul>
                    </article>
                </div>
            </div>

            <div>
                <h3>Assignments</h3>
                <div class="civic-reporting-chip-grid">
                    <span v-for="assignment in template.assignments" :key="assignment.id">
                        <strong>{{ assignment.station?.name ?? assignment.mda?.name ?? 'MDA-level' }}</strong>
                        <small>{{ assignment.mda?.code }} / {{ titleCase(assignment.status) }}</small>
                    </span>
                    <span v-if="!template.assignments.length">
                        <strong>No assignments</strong>
                        <small>Use Assign to make this template available.</small>
                    </span>
                </div>
            </div>
        </section>
    </article>
</template>

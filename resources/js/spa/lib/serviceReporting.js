export function titleCase(value) {
    return String(value ?? '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function formatNumber(value) {
    return new Intl.NumberFormat().format(Number(value ?? 0));
}

export function formatDateTime(value) {
    if (!value) return 'Not submitted';

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

export function assignmentSummary(template) {
    const count = template.assignments?.length ?? 0;
    if (count === 0) return 'No active assignments';
    if (count === 1) return template.assignments[0].station?.name ?? template.assignments[0].mda?.code ?? 'MDA-level';

    return `${count} facilities / stations`;
}

export function submittedBy(submission) {
    return submission.submitted_by?.name ?? submission.created_by?.name ?? 'Not submitted';
}

export function valueKey(indicatorOrCode, dimensionKey = '', dimensionValue = '') {
    const code = typeof indicatorOrCode === 'string' ? indicatorOrCode : indicatorOrCode.code;

    return [code, dimensionKey, dimensionValue].join(':');
}

export function primaryDimension(indicator) {
    return indicator?.dimensions?.[0] ?? null;
}

export function sectionPrimaryDimension(section) {
    return primaryDimension(section.indicators.find((indicator) => indicator.dimensions?.length));
}

export function sectionHasDimensions(section) {
    return section.indicators.some((indicator) => indicator.dimensions?.length);
}

export function dimensionTotal(indicator, values) {
    const dimension = primaryDimension(indicator);
    if (!dimension) return '';

    return dimension.dimension_values.reduce((sum, dimensionValue) => sum + Number(values[valueKey(indicator, dimension.dimension_key, dimensionValue)] ?? 0), 0);
}

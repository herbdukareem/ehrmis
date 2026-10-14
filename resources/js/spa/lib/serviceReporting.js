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

export function matrixSignature(dimensions = {}) {
    return Object.keys(dimensions)
        .sort()
        .map((key) => `${encodeURIComponent(key)}=${encodeURIComponent(dimensions[key] ?? '')}`)
        .join('|');
}

export function matrixValueKey(indicatorOrCode, dimensions = {}) {
    const code = typeof indicatorOrCode === 'string' ? indicatorOrCode : indicatorOrCode.code;

    return [code, 'matrix', matrixSignature(dimensions)].join(':');
}

export function primaryDimension(indicator) {
    return indicator?.dimensions?.[0] ?? null;
}

export function hasMatrixDimensions(indicator) {
    return (indicator?.dimensions?.length ?? 0) > 1;
}

export function matrixRowDimension(indicator) {
    return indicator?.dimensions?.[0] ?? null;
}

export function matrixColumnDimension(indicator) {
    return indicator?.dimensions?.[1] ?? null;
}

export function matrixCellDimensions(indicator, rowValue, columnValue) {
    const rowDimension = matrixRowDimension(indicator);
    const columnDimension = matrixColumnDimension(indicator);

    if (!rowDimension || !columnDimension) return {};

    return {
        [rowDimension.dimension_key]: rowValue,
        [columnDimension.dimension_key]: columnValue,
    };
}

export function matrixCellKey(indicator, rowValue, columnValue) {
    return matrixValueKey(indicator, matrixCellDimensions(indicator, rowValue, columnValue));
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

export function matrixRowTotal(indicator, rowValue, values) {
    const columnDimension = matrixColumnDimension(indicator);
    if (!columnDimension) return 0;

    return columnDimension.dimension_values.reduce((sum, columnValue) => sum + Number(values[matrixCellKey(indicator, rowValue, columnValue)] ?? 0), 0);
}

export function matrixGrandTotal(indicator, values) {
    const rowDimension = matrixRowDimension(indicator);
    if (!rowDimension) return 0;

    return rowDimension.dimension_values.reduce((sum, rowValue) => sum + matrixRowTotal(indicator, rowValue, values), 0);
}

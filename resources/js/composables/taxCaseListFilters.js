// Both requests use the same filter values; only the list adds pagination.
export function taxCaseListParams(values) {
  return new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null))
}

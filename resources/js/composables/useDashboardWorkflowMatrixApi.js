import { ref } from 'vue'

const fieldLabels = {
  include_completed: 'Show completed',
  to_period: 'To period',
  from_period: 'From period',
  tax_category: 'Tax category',
  fiscal_year_id: 'Fiscal year',
  entity_id: 'Entity',
  status: 'Status',
  per_page: 'Rows per page'
}

export function serializeMatrixFilters(filters = {}) {
  const params = new URLSearchParams()

  Object.entries(filters).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return

    if (typeof value === 'boolean') {
      params.append(key, value ? '1' : '0')
      return
    }

    params.append(key, value)
  })

  return params
}

export function formatValidationMessage(field, message) {
  if (field === 'include_completed') {
    return 'Show completed filter is invalid.'
  }

  if (field === 'to_period' && /VAT ranges may not exceed 24 months/i.test(message)) {
    return 'VAT period range may not exceed 24 months.'
  }

  const label = fieldLabels[field]
  if (!label || typeof message !== 'string') return message

  return message
    .replace(new RegExp(`the ${field.replaceAll('_', ' ')} field`, 'i'), `the ${label} field`)
    .replace(new RegExp(field.replaceAll('_', ' '), 'gi'), label)
}

export function extractMatrixError(error) {
  const data = error?.response?.data || error?.data || error

  if (data?.errors && typeof data.errors === 'object') {
    const messages = Object.entries(data.errors)
      .flatMap(([field, fieldMessages]) => Array.isArray(fieldMessages)
        ? fieldMessages.map((message) => formatValidationMessage(field, message))
        : [])
      .filter(Boolean)

    return {
      title: 'Unable to load workflow matrix. Please review the filters and try again.',
      messages: [...new Set(messages)]
    }
  }

  return {
    title: 'Unable to load workflow matrix.',
    messages: [data?.message || error?.message || 'Please try again.']
  }
}

export async function parseMatrixResponse(response) {
  const body = await response.text()

  if (!body) {
    return {}
  }

  try {
    return JSON.parse(body)
  } catch {
    const responseError = new Error('The workflow matrix service returned an unexpected response.')
    responseError.data = {
      message: response.ok
        ? 'The workflow matrix data could not be read. Please try again.'
        : 'The workflow matrix service is unavailable. Please try again.'
    }
    throw responseError
  }
}

export function useDashboardWorkflowMatrixApi() {
  const data = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const fetchMatrix = async (filters = {}) => {
    loading.value = true
    error.value = null

    try {
      const params = serializeMatrixFilters(filters)

      const response = await fetch(`/api/dashboard/workflow-matrix?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'include'
      })
      const payload = await parseMatrixResponse(response)

      if (!response.ok) {
        const requestError = new Error(payload.message || 'Failed to load workflow matrix')
        requestError.data = payload
        throw requestError
      }

      data.value = payload.data
      return payload.data
    } catch (err) {
      error.value = extractMatrixError(err)
      data.value = null
      throw err
    } finally {
      loading.value = false
    }
  }

  return { data, loading, error, fetchMatrix }
}

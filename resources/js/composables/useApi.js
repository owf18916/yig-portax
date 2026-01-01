import { ref } from 'vue'

export function useApi() {
  const loading = ref(false)
  const error = ref(null)
  const data = ref(null)

  const fetchData = async (url, options = {}) => {
    loading.value = true
    error.value = null
    data.value = null

    try {
      const response = await fetch(url, {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          ...options.headers
        },
        ...options
      })

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`)
      }

      data.value = await response.json()
      return data.value
    } catch (err) {
      error.value = err.message
      console.error('API Error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  const post = async (url, body, options = {}) => {
    return fetchData(url, {
      method: 'POST',
      body: JSON.stringify(body),
      ...options
    })
  }

  const put = async (url, body, options = {}) => {
    return fetchData(url, {
      method: 'PUT',
      body: JSON.stringify(body),
      ...options
    })
  }

  const delete_ = async (url, options = {}) => {
    return fetchData(url, {
      method: 'DELETE',
      ...options
    })
  }

  return {
    loading,
    error,
    data,
    fetchData,
    post,
    put,
    delete: delete_
  }
}

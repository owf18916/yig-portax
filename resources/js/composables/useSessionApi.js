import { session } from './useCurrentUser.js'
import { createTransport } from '../auth/transport.js'
export const { apiFetch, installAxios, inspectUpload } = createTransport(session)

// Private links use authenticated bytes with status/MIME checks, never a public file URL.
export async function downloadPrivateDocument(id, filename) {
  const response = await apiFetch(`/api/documents/${id}/download`)
  if (!response.ok) {
    const result = await response.json()
    throw new Error(result.message || `Download failed (${response.status})`)
  }
  const blob = await response.blob()
  const disposition = response.headers.get('content-disposition') || ''
  const serverName = disposition.match(/filename="?([^";]+)"?/i)?.[1]
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename || serverName || 'document.pdf'
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

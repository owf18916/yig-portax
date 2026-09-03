<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
        <p class="text-gray-600">Manage user access, roles, entities, and account status.</p>
      </div>
      <button class="rounded-lg bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700" @click="openCreate">
        Create User
      </button>
    </div>

    <div class="grid gap-3 rounded-lg border bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
      <label class="lg:col-span-2">
        <span class="mb-1 block text-sm font-medium text-gray-700">Search</span>
        <input v-model="filters.search" class="w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Name, email, department, or position" @keyup.enter="applyFilters">
      </label>
      <label>
        <span class="mb-1 block text-sm font-medium text-gray-700">Role</span>
        <select v-model="filters.role_id" class="w-full rounded-lg border border-gray-300 px-3 py-2" @change="applyFilters">
          <option value="">All roles</option>
          <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
        </select>
      </label>
      <label>
        <span class="mb-1 block text-sm font-medium text-gray-700">Entity</span>
        <select v-model="filters.entity_id" class="w-full rounded-lg border border-gray-300 px-3 py-2" @change="applyFilters">
          <option value="">All entities</option>
          <option v-for="entity in entities" :key="entity.id" :value="entity.id">{{ entity.name }}</option>
        </select>
      </label>
      <label>
        <span class="mb-1 block text-sm font-medium text-gray-700">Status</span>
        <select v-model="filters.status" class="w-full rounded-lg border border-gray-300 px-3 py-2" @change="applyFilters">
          <option value="all">All</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </label>
      <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
        <button :disabled="loading" class="rounded-lg bg-blue-600 px-4 py-2 text-white disabled:opacity-50" @click="applyFilters">Search</button>
        <button :disabled="loading" class="rounded-lg bg-gray-100 px-4 py-2 text-gray-700 disabled:opacity-50" @click="resetFilters">Clear filters</button>
      </div>
    </div>

    <div v-if="pageError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ pageError }}</div>

    <div class="overflow-x-auto rounded-lg border bg-white">
      <div v-if="loading" class="p-10 text-center text-gray-500">Loading users...</div>
      <table v-else class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600">
          <tr>
            <th class="p-3 font-medium">Name</th><th class="p-3 font-medium">Email</th><th class="p-3 font-medium">Role</th>
            <th class="p-3 font-medium">Entity</th><th class="p-3 font-medium">Department</th><th class="p-3 font-medium">Position</th>
            <th class="p-3 font-medium">Status</th><th class="p-3 font-medium">Last Login</th><th class="p-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in users" :key="user.id" class="border-t">
            <td class="p-3 font-medium text-gray-900">{{ user.name }}</td>
            <td class="p-3 text-gray-600">{{ user.email }}</td>
            <td class="p-3">{{ user.role?.name || 'Unassigned' }}</td>
            <td class="p-3">{{ user.entity?.name || 'Unassigned' }}</td>
            <td class="p-3">{{ user.department || '—' }}</td>
            <td class="p-3">{{ user.position || '—' }}</td>
            <td class="p-3"><span :class="user.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-700'" class="rounded-full px-2 py-1 text-xs font-medium">{{ user.is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="whitespace-nowrap p-3">{{ formatDate(user.last_login_at) }}</td>
            <td class="whitespace-nowrap p-3">
              <button class="mr-3 text-blue-700 hover:underline" @click="openEdit(user)">Edit</button>
              <button :disabled="statusLoadingId === user.id || (user.id === currentUserId && user.is_active)" :class="user.is_active ? 'text-red-700' : 'text-green-700'" class="hover:underline disabled:cursor-not-allowed disabled:text-gray-400" :title="user.id === currentUserId && user.is_active ? 'You cannot deactivate your own account.' : ''" @click="confirmStatus(user)">
                {{ statusLoadingId === user.id ? 'Updating...' : (user.is_active ? 'Deactivate' : 'Activate') }}
              </button>
            </td>
          </tr>
          <tr v-if="users.length === 0"><td colspan="9" class="p-10 text-center text-gray-500">No users match the current filters.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="pagination.total > 0" class="flex items-center justify-between text-sm text-gray-600">
      <span>Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }}</span>
      <div class="flex gap-2">
        <button :disabled="loading || pagination.current_page <= 1" class="rounded border px-3 py-2 disabled:opacity-40" @click="loadUsers(pagination.current_page - 1)">Previous</button>
        <span class="px-2 py-2">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <button :disabled="loading || pagination.current_page >= pagination.last_page" class="rounded border px-3 py-2 disabled:opacity-40" @click="loadUsers(pagination.current_page + 1)">Next</button>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="formOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeForm">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl">
          <div class="flex items-center justify-between border-b px-6 py-4"><h2 class="text-xl font-semibold">{{ editingUser ? 'Edit User' : 'Create User' }}</h2><button type="button" class="text-gray-500" @click="closeForm">Close</button></div>
          <form class="space-y-4 p-6" @submit.prevent="saveUser">
            <div v-if="formError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ formError }}</div>
            <div class="grid gap-4 sm:grid-cols-2">
              <FormField v-model="form.name" label="Name" required :error="fieldError('name')" />
              <FormField v-model="form.email" label="Email" type="email" name="email" autocomplete="email" required :error="fieldError('email')" />
              <label><span class="mb-1 block text-sm font-medium text-gray-700">Role <span class="text-red-500">*</span></span><select v-model="form.role_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2"><option disabled value="">Select a role</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select><span v-if="fieldError('role_id')" class="mt-1 block text-sm text-red-500">{{ fieldError('role_id') }}</span></label>
              <label><span class="mb-1 block text-sm font-medium text-gray-700">Entity <span class="text-red-500">*</span></span><select v-model="form.entity_id" required class="w-full rounded-lg border border-gray-300 px-3 py-2"><option disabled value="">Select an entity</option><option v-for="entity in entities" :key="entity.id" :value="entity.id">{{ entity.name }}</option></select><span v-if="fieldError('entity_id')" class="mt-1 block text-sm text-red-500">{{ fieldError('entity_id') }}</span></label>
              <FormField v-model="form.phone" label="Phone" :error="fieldError('phone')" />
              <FormField v-model="form.department" label="Department" :error="fieldError('department')" />
              <FormField v-model="form.position" label="Position" :error="fieldError('position')" />
              <div></div>
              <FormField v-model="form.password" :label="editingUser ? 'New Password (optional)' : 'Password'" type="password" name="password" autocomplete="new-password" :required="!editingUser" :error="fieldError('password')" />
              <FormField v-model="form.password_confirmation" label="Password Confirmation" type="password" name="password_confirmation" autocomplete="new-password" :required="!editingUser" />
            </div>
            <div class="flex justify-end gap-3 border-t pt-4"><button type="button" class="rounded-lg bg-gray-100 px-4 py-2" :disabled="saving" @click="closeForm">Cancel</button><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-white disabled:opacity-50" :disabled="saving">{{ saving ? 'Saving...' : (editingUser ? 'Save Changes' : 'Create User') }}</button></div>
          </form>
        </div>
      </div>
    </Teleport>

    <ConfirmationDialog :is-open="statusDialog.open" :title="`${statusDialog.user?.is_active ? 'Deactivate' : 'Activate'} User`" :message="`Are you sure you want to ${statusDialog.user?.is_active ? 'deactivate' : 'activate'} ${statusDialog.user?.name || 'this user'}?`" :confirm-label="statusDialog.user?.is_active ? 'Deactivate User' : 'Activate User'" :variant="statusDialog.user?.is_active ? 'danger' : 'default'" @confirm="updateStatus" @cancel="statusDialog.open = false" />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import ConfirmationDialog from '../components/ConfirmationDialog.vue'
import FormField from '../components/FormField.vue'
import { useToast } from '../composables/useToast'

const { showSuccess, showError } = useToast()
const storedUser = JSON.parse(localStorage.getItem('user') || '{}')
const currentUserId = storedUser.id
const users = ref([]), roles = ref([]), entities = ref([]), loading = ref(false), pageError = ref('')
const saving = ref(false), formOpen = ref(false), editingUser = ref(null), formError = ref(''), validationErrors = ref({}), statusLoadingId = ref(null)
const filters = reactive({ search: '', role_id: '', entity_id: '', status: 'all' })
const pagination = reactive({ current_page: 1, last_page: 1, from: 0, to: 0, total: 0 })
const statusDialog = reactive({ open: false, user: null })
const emptyForm = () => ({ name: '', email: '', role_id: '', entity_id: '', phone: '', position: '', department: '', password: '', password_confirmation: '' })
const form = reactive(emptyForm())

function request(url, options = {}) {
  return fetch(url, { credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', ...options.headers }, ...options })
}
async function responseJson(response) { try { return await response.json() } catch { return {} } }
async function loadOptions() {
  const [roleResponse, entityResponse] = await Promise.all([request('/api/roles'), request('/api/user-management/entities')])
  if (!roleResponse.ok || !entityResponse.ok) throw new Error('Unable to load role and entity options.')
  roles.value = (await responseJson(roleResponse)).data || []
  entities.value = (await responseJson(entityResponse)).data || []
}
async function loadUsers(page = 1) {
  loading.value = true; pageError.value = ''
  const params = new URLSearchParams({ page: String(page), status: filters.status })
  if (filters.search.trim()) params.set('search', filters.search.trim())
  if (filters.role_id) params.set('role_id', filters.role_id)
  if (filters.entity_id) params.set('entity_id', filters.entity_id)
  try {
    const response = await request(`/api/users?${params}`), payload = await responseJson(response)
    if (!response.ok) throw new Error(payload.message || 'Unable to load users.')
    users.value = payload.data?.data || []
    Object.assign(pagination, { current_page: payload.data.current_page, last_page: payload.data.last_page, from: payload.data.from || 0, to: payload.data.to || 0, total: payload.data.total })
  } catch (error) { pageError.value = error.message || 'Unable to load users.' } finally { loading.value = false }
}
function applyFilters() { loadUsers(1) }
function resetFilters() { Object.assign(filters, { search: '', role_id: '', entity_id: '', status: 'all' }); loadUsers(1) }
function openCreate() { editingUser.value = null; Object.assign(form, emptyForm()); validationErrors.value = {}; formError.value = ''; formOpen.value = true }
function openEdit(user) { editingUser.value = user; Object.assign(form, { ...emptyForm(), name: user.name, email: user.email, role_id: user.role_id, entity_id: user.entity_id, phone: user.phone || '', position: user.position || '', department: user.department || '' }); validationErrors.value = {}; formError.value = ''; formOpen.value = true }
function closeForm() { if (!saving.value) formOpen.value = false }
function fieldError(field) { return validationErrors.value[field]?.[0] || '' }
async function saveUser() {
  saving.value = true; validationErrors.value = {}; formError.value = ''
  const payload = { ...form }
  if (editingUser.value && !payload.password) { delete payload.password; delete payload.password_confirmation }
  try {
    const response = await request(editingUser.value ? `/api/users/${editingUser.value.id}` : '/api/users', { method: editingUser.value ? 'PATCH' : 'POST', body: JSON.stringify(payload) })
    const result = await responseJson(response)
    if (!response.ok) { validationErrors.value = result.errors || {}; throw new Error(result.message === 'The given data was invalid.' ? 'Please correct the highlighted fields.' : (result.message || 'Unable to save the user.')) }
    formOpen.value = false; showSuccess(result.message || 'User saved successfully.'); await loadUsers(pagination.current_page)
  } catch (error) { formError.value = error.message || 'Unable to save the user.' } finally { saving.value = false }
}
function confirmStatus(user) { if (user.id === currentUserId && user.is_active) return; statusDialog.user = user; statusDialog.open = true }
async function updateStatus() {
  const user = statusDialog.user; if (!user) return
  statusDialog.open = false; statusLoadingId.value = user.id
  try {
    const response = await request(`/api/users/${user.id}/status`, { method: 'PATCH', body: JSON.stringify({ is_active: !user.is_active }) }), result = await responseJson(response)
    if (!response.ok) throw new Error(result.message || 'Unable to update the user status.')
    showSuccess(result.message); await loadUsers(pagination.current_page)
  } catch (error) { showError(error.message || 'Unable to update the user status.') } finally { statusLoadingId.value = null }
}
function formatDate(value) { return value ? new Date(value).toLocaleString() : 'Never' }
onMounted(async () => { try { await loadOptions(); await loadUsers() } catch (error) { pageError.value = error.message || 'Unable to load User Management.' } })
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation Header (hanya tampil saat bukan login) -->
    <nav v-if="$route.path !== '/login' && authStatus === 'authenticated'" class="bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center space-x-8">
            <div class="flex items-center space-x-3 hover:opacity-80 transition cursor-pointer" @click="$router.push('/')">
              <img :src="logoUrl" alt="PorTax Logo" class="h-8 w-8">
              <span class="text-xl font-bold text-gray-900">PORTAX</span>
            </div>
            <div class="hidden md:flex space-x-4">
              <router-link
                to="/"
                :class="['px-3 py-2 rounded-md text-sm font-medium transition-colors',
                  $route.path === '/' ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-gray-900']"
              >
                Dashboard
              </router-link>
              <router-link
                to="/tax-cases"
                :class="['px-3 py-2 rounded-md text-sm font-medium transition-colors',
                  $route.path.startsWith('/tax-cases') ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-gray-900']"
              >
                Tax SPT
              </router-link>
              <router-link
                to="/notification-logs"
                :class="['px-3 py-2 rounded-md text-sm font-medium transition-colors', $route.path.startsWith('/notification-logs') ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-gray-900']"
              >
                Notification Logs
              </router-link>
              <router-link
                v-if="canManageUsers"
                to="/admin/users"
                :class="['px-3 py-2 rounded-md text-sm font-medium transition-colors', $route.path.startsWith('/admin/users') ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-gray-900']"
              >
                User Management
              </router-link>
            </div>
          </div>
          <div class="flex items-center space-x-4">
            <span v-if="currentUser" class="text-sm text-gray-700">
              <span class="font-medium">{{ currentUser.name }}</span>
              <span class="text-gray-500"> ({{ currentUser.entity?.name || 'No Entity' }})</span>
            </span>
            <button
              v-if="currentUser"
              @click="handleLogout"
              class="px-3 py-1 bg-red-50 text-red-700 rounded text-sm hover:bg-red-100 transition"
            >
              Logout
            </button>
            <div v-else class="w-8 h-8 bg-blue-600 rounded-full"></div>
          </div>
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <main :class="getMainClasses()">
      <p v-if="authNotice" role="alert" class="p-4 bg-amber-100">{{ authNotice }}</p>
      <div v-if="$route.path !== '/login' && authStatus !== 'authenticated'" role="status" class="p-8">
        <p>{{ authStatus === 'unavailable' ? 'Unable to verify your session. Check your connection and retry.' : 'Verifying session...' }}</p>
        <button v-if="authStatus === 'unavailable'" class="underline" @click="verifySession({ force: true })">Retry verification</button>
      </div>
      <router-view v-if="$route.path === '/login' || currentUser" v-show="$route.path === '/login' || canDisplayPrivate" :key="authGeneration" />
    </main>

    <!-- Footer (hanya tampil saat bukan login) -->
    <footer v-if="$route.path !== '/login' && authStatus === 'authenticated'" class="bg-gray-100 border-t mt-12">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-center text-sm text-gray-600">
        <p>PORTAX Tax SPT Management System</p>
      </div>
    </footer>

    <!-- Global Toast Component -->
    <Toast ref="toastRef" />
  </div>
</template>

<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Toast from './components/Toast.vue'
import { setToastComponent } from './composables/useToast'
import { session, logoutPending, useCurrentUser } from './composables/useCurrentUser'

const logoUrl = '/images/portax-logo.webp'

const router = useRouter()
const route = useRoute()
const { currentUser, authStatus, authGeneration, authNotice, verifySession, clearCurrentUser } = useCurrentUser()
const toastRef = ref(null)
const canDisplayPrivate = computed(() => authStatus.value === 'authenticated' && (!route.meta.permission || currentUser.value?.role?.permissions?.includes(route.meta.permission)))
const canManageUsers = computed(() => currentUser.value?.role?.permissions?.includes('manage_users') === true)

// Check if current route should be full-screen (no max-width constraint)
const isFullScreenRoute = () => {
  // Routes that need full-screen layout: workflow pages, tax case forms, etc
  return route.path === '/login' || 
         route.path.includes('/workflow/') || 
         route.path.includes('/spt-filing') ||
         route.path.includes('/objection-decision')
}

// Get main content classes based on route
const getMainClasses = () => {
  if (route.path === '/login') {
    return ''
  }
  
  // For workflow routes: minimal padding
  if (route.path.includes('/workflow/') || 
      route.path.includes('/spt-filing') ||
      route.path.includes('/objection-decision')) {
    return 'px-2 py-2 h-full'
  }
  
  // For other routes: normal padding and max-width
  return 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12'
}

onMounted(() => {
  // Initialize toast component reference
  setToastComponent(toastRef)

})

let redirecting = false
watch(authStatus, async status => {
  if (status === 'authenticated' && route.meta.permission && !currentUser.value?.role?.permissions?.includes(route.meta.permission)) { await router.replace('/'); return }
  if (status !== 'unauthenticated' || route.path === '/login' || !route.matched.length || redirecting) return
  redirecting = true
  try { await router.replace('/login') } finally { redirecting = false }
})
const handleLogout = async () => {
  if (!window.confirm('Log out? Unsaved changes will be lost.')) return
  logoutPending.value = true
  clearCurrentUser()
  const logoutGeneration = session.state.generation
  try {
    const response = await fetch('/api/logout', {
      method: 'POST', credentials: 'include', signal: AbortSignal.timeout(10000),
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }
    })
    if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json') || !(await response.json()).success) throw new Error('Logout not confirmed')
  } catch {
    if (logoutGeneration !== session.state.generation) return
    authNotice.value = 'You are locked out locally. Server logout was not confirmed; reconnect and sign in to recover.'
  } finally {
    logoutPending.value = false
  }
}
</script>

<style scoped>
</style>

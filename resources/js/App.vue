<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation Header (hanya tampil saat bukan login) -->
    <nav v-if="$route.path !== '/login'" class="bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center space-x-8">
            <h1 class="text-2xl font-bold text-blue-600">PORTAX</h1>
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
                Tax Cases
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
      <router-view />
    </main>

    <!-- Footer (hanya tampil saat bukan login) -->
    <footer v-if="$route.path !== '/login'" class="bg-gray-100 border-t mt-12">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-center text-sm text-gray-600">
        <p>PORTAX Tax Case Management System | Vue.js 3 + Laravel REST API</p>
      </div>
    </footer>

    <!-- Global Toast Component -->
    <Toast ref="toastRef" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Toast from './components/Toast.vue'
import { setToastComponent } from './composables/useToast'

const router = useRouter()
const route = useRoute()
const currentUser = ref(null)
const toastRef = ref(null)

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

  // Get user from localStorage
  const userStr = localStorage.getItem('user')
  if (userStr) {
    currentUser.value = JSON.parse(userStr)
  }
})

const handleLogout = async () => {
  try {
    await fetch('/api/logout', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      credentials: 'include'
    })
  } catch (err) {
    console.error('Logout error:', err)
  } finally {
    // Clear local storage and redirect
    localStorage.removeItem('user')
    await router.push('/login')
  }
}
</script>

<style scoped>
</style>
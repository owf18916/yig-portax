<template>
  <div class="min-h-screen bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <!-- Logo/Title -->
      <div class="text-center mb-8">
        <div class="flex justify-center mb-4">
          <img :src="logoUrl" alt="PorTax Logo" class="h-16 w-16 drop-shadow-lg">
        </div>
        <h1 class="text-3xl font-bold text-white mb-2">PorTax</h1>
        <p class="text-blue-100">Tax Case Management System</p>
      </div>

      <!-- Login Card -->
      <div class="bg-white rounded-lg shadow-xl p-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Login</h2>

        <!-- Error Alert -->
        <div v-if="error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
          <p class="text-red-700 text-sm">{{ error }}</p>
        </div>

        <!-- Form -->
        <form @submit.prevent="handleLogin" class="space-y-4">
          <!-- Email -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Email Address
            </label>
            <input
              v-model="email"
              type="email"
              required
              :disabled="loading"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
              placeholder="your@email.com"
            />
          </div>

          <!-- Password -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Password
            </label>
            <input
              v-model="password"
              type="password"
              required
              :disabled="loading"
              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
              placeholder="••••••••"
            />
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="loading"
            class="w-full py-2 px-4 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed transition"
          >
            {{ loading ? 'Logging in...' : 'Login' }}
          </button>
        </form>

        <!-- Test Credentials -->
        <div class="mt-6 pt-6 border-t border-gray-200">
          <p class="text-sm text-gray-600 mb-3 font-medium">Test Credentials:</p>
          <div class="space-y-2 text-xs text-gray-600">
            <p><strong>Admin:</strong> admin@portax.co.id / admin123</p>
            <p><strong>User 1:</strong> user1@pasi.co.id / password123</p>
            <p><strong>User 2:</strong> user1@pemi.co.id / password123</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

const logoUrl = '/images/portax-logo.webp'

const router = useRouter()

const email = ref('admin@portax.co.id')
const password = ref('admin123')
const error = ref('')
const loading = ref(false)

const handleLogin = async () => {
  error.value = ''
  loading.value = true

  try {
    const response = await fetch('/api/login', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
      },
      body: JSON.stringify({
        email: email.value,
        password: password.value
      }),
      credentials: 'include'
    })

    if (!response.ok) {
      const errorData = await response.json()
      throw new Error(errorData.message || 'Login failed')
    }

    const data = await response.json()
    
    console.log('Login response:', data)
    
    // Check if login response is successful
    if (!data.success || !data.data?.user) {
      throw new Error(data.message || 'Login response invalid')
    }
    
    // Store user info in localStorage
    const userToStore = data.data.user
    console.log('Storing user:', userToStore)
    localStorage.setItem('user', JSON.stringify(userToStore))
    
    console.log('User stored, verifying localStorage:', localStorage.getItem('user'))
    
    // Small delay to ensure localStorage is written before redirect
    await new Promise(resolve => setTimeout(resolve, 100))
    
    console.log('Redirecting to dashboard...')
    // Redirect to dashboard
    await router.push('/')
  } catch (err) {
    error.value = err.message || 'Failed to login. Please try again.'
    console.error('Login error:', err)
  } finally {
    loading.value = false
  }
}
</script>

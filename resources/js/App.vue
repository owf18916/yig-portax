<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation Header -->
    <nav class="bg-white shadow-sm border-b">
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
            <span class="text-sm text-gray-600">January 1, 2026</span>
            <div class="w-8 h-8 bg-blue-600 rounded-full"></div>
          </div>
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <router-view />
    </main>

    <!-- Footer -->
    <footer class="bg-gray-100 border-t mt-12">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-center text-sm text-gray-600">
        <p>PORTAX Tax Case Management System | Vue.js 3 + Laravel REST API</p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<style scoped>
</style>
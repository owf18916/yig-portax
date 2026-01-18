import { createRouter, createWebHistory } from 'vue-router'
import Login from '../pages/Login.vue'
import Dashboard from '../pages/Dashboard.vue'
import TaxCaseList from '../pages/TaxCaseList.vue'
import TaxCaseDetail from '../pages/TaxCaseDetail.vue'
import WorkflowForm from '../pages/WorkflowForm.vue'
import CreateCITCase from '../pages/CreateCITCase.vue'
import CreateVATCase from '../pages/CreateVATCase.vue'
import SptFilingForm from '../pages/SptFilingForm.vue'
import Sp2FilingForm from '../pages/Sp2FilingForm.vue'
import SphpFilingForm from '../pages/SphpFilingForm.vue'
import SkpFilingForm from '../pages/SkpFilingForm.vue'
import ObjectionSubmissionForm from '../pages/ObjectionSubmissionForm.vue'
import ObjectionDecisionForm from '../pages/ObjectionDecisionForm.vue'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: { requiresAuth: false }
  },
  {
    path: '/',
    name: 'Dashboard',
    component: Dashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases',
    name: 'TaxCaseList',
    component: TaxCaseList,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/create/cit',
    name: 'CreateCITCase',
    component: CreateCITCase,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/create/vat',
    name: 'CreateVATCase',
    component: CreateVATCase,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/1',
    name: 'SptFilingForm',
    component: SptFilingForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/2',
    name: 'Sp2FilingForm',
    component: Sp2FilingForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/3',
    name: 'SphpFilingForm',
    component: SphpFilingForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/4',
    name: 'SkpFilingForm',
    component: SkpFilingForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/5',
    name: 'ObjectionSubmissionForm',
    component: ObjectionSubmissionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/7',
    name: 'ObjectionDecisionForm',
    component: ObjectionDecisionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id',
    name: 'TaxCaseDetail',
    component: TaxCaseDetail,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/:stageId',
    name: 'WorkflowForm',
    component: WorkflowForm,
    meta: { requiresAuth: true }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Auth guard
router.beforeEach(async (to, from, next) => {
  const requiresAuth = to.meta.requiresAuth !== false
  const isLoggedIn = localStorage.getItem('user')

  console.log('Router guard check:', { 
    toPath: to.path, 
    requiresAuth, 
    isLoggedIn: !!isLoggedIn 
  })

  if (requiresAuth && !isLoggedIn) {
    // Redirect to login if not authenticated
    console.log('Not authenticated, redirecting to login')
    next('/login')
  } else if (to.path === '/login' && isLoggedIn) {
    // Redirect to dashboard if already logged in
    console.log('Already logged in, redirecting to dashboard')
    next('/')
  } else {
    next()
  }
})

export default router
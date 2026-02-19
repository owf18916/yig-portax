import { createRouter, createWebHistory } from 'vue-router'
import { defineAsyncComponent } from 'vue'

// Lazy load all page components for better code splitting
const Login = defineAsyncComponent(() => import('../pages/Login.vue'))
const Dashboard = defineAsyncComponent(() => import('../pages/Dashboard.vue'))
const TaxCaseList = defineAsyncComponent(() => import('../pages/TaxCaseList.vue'))
const TaxCaseDetail = defineAsyncComponent(() => import('../pages/TaxCaseDetail.vue'))
const WorkflowForm = defineAsyncComponent(() => import('../pages/WorkflowForm.vue'))
const CreateCITCase = defineAsyncComponent(() => import('../pages/CreateCITCase.vue'))
const CreateVATCase = defineAsyncComponent(() => import('../pages/CreateVATCase.vue'))
const SptFilingForm = defineAsyncComponent(() => import('../pages/SptFilingForm.vue'))
const Sp2FilingForm = defineAsyncComponent(() => import('../pages/Sp2FilingForm.vue'))
const SphpFilingForm = defineAsyncComponent(() => import('../pages/SphpFilingForm.vue'))
const SkpFilingForm = defineAsyncComponent(() => import('../pages/SkpFilingForm.vue'))
const ObjectionSubmissionForm = defineAsyncComponent(() => import('../pages/ObjectionSubmissionForm.vue'))
const SpuhRecordForm = defineAsyncComponent(() => import('../pages/SpuhRecordForm.vue'))
const ObjectionDecisionForm = defineAsyncComponent(() => import('../pages/ObjectionDecisionForm.vue'))
const AppealSubmissionForm = defineAsyncComponent(() => import('../pages/AppealSubmissionForm.vue'))
const AppealExplanationRequestForm = defineAsyncComponent(() => import('../pages/AppealExplanationRequestForm.vue'))
const AppealDecisionForm = defineAsyncComponent(() => import('../pages/AppealDecisionForm.vue'))
const SupremeCourtSubmissionForm = defineAsyncComponent(() => import('../pages/SupremeCourtSubmissionForm.vue'))
const SupremeCourtDecisionForm = defineAsyncComponent(() => import('../pages/SupremeCourtDecisionForm.vue'))
const BankTransferRequestForm = defineAsyncComponent(() => import('../pages/BankTransferRequestForm.vue'))
const SuratInstruksiTransferForm = defineAsyncComponent(() => import('../pages/SuratInstruksiTransferForm.vue'))
const RefundReceivedForm = defineAsyncComponent(() => import('../pages/RefundReceivedForm.vue'))
const KianSubmissionForm = defineAsyncComponent(() => import('../pages/KianSubmissionForm.vue'))

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
    path: '/tax-cases/:id/workflow/6',
    name: 'SpuhRecordForm',
    component: SpuhRecordForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/7',
    name: 'ObjectionDecisionForm',
    component: ObjectionDecisionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/8',
    name: 'AppealSubmissionForm',
    component: AppealSubmissionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/9',
    name: 'AppealExplanationRequestForm',
    component: AppealExplanationRequestForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/10',
    name: 'AppealDecisionForm',
    component: AppealDecisionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/11',
    name: 'SupremeCourtSubmissionForm',
    component: SupremeCourtSubmissionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/12',
    name: 'SupremeCourtDecisionForm',
    component: SupremeCourtDecisionForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/13',
    name: 'BankTransferRequestForm',
    component: BankTransferRequestForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/14',
    name: 'SuratInstruksiTransferForm',
    component: SuratInstruksiTransferForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/15',
    name: 'RefundReceivedForm',
    component: RefundReceivedForm,
    meta: { requiresAuth: true }
  },
  {
    path: '/tax-cases/:id/workflow/16',
    name: 'KianSubmissionForm',
    component: KianSubmissionForm,
    meta: { requiresAuth: true }
  },
  // ✅ NEW: Per-stage KIAN submission routes (convenience for direct navigation)
  {
    path: '/tax-cases/:id/kian/:stageId',
    name: 'KianSubmissionStage',
    component: KianSubmissionForm,
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
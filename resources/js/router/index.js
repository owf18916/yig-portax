import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'
import TaxCaseList from '../pages/TaxCaseList.vue'
import TaxCaseDetail from '../pages/TaxCaseDetail.vue'
import WorkflowForm from '../pages/WorkflowForm.vue'
import CreateCITCase from '../pages/CreateCITCase.vue'
import CreateVATCase from '../pages/CreateVATCase.vue'
import SptFilingForm from '../pages/SptFilingForm.vue'
import SkpRecordForm from '../pages/SkpRecordForm.vue'
import ObjectionDecisionForm from '../pages/ObjectionDecisionForm.vue'

const routes = [
  {
    path: '/',
    name: 'Dashboard',
    component: Dashboard
  },
  {
    path: '/tax-cases',
    name: 'TaxCaseList',
    component: TaxCaseList
  },
  {
    path: '/tax-cases/create/cit',
    name: 'CreateCITCase',
    component: CreateCITCase
  },
  {
    path: '/tax-cases/create/vat',
    name: 'CreateVATCase',
    component: CreateVATCase
  },
  {
    path: '/tax-cases/:id/workflow/1',
    name: 'SptFilingForm',
    component: SptFilingForm
  },
  {
    path: '/tax-cases/:id/workflow/4',
    name: 'SkpRecordForm',
    component: SkpRecordForm
  },
  {
    path: '/tax-cases/:id/workflow/7',
    name: 'ObjectionDecisionForm',
    component: ObjectionDecisionForm
  },
  {
    path: '/tax-cases/:id',
    name: 'TaxCaseDetail',
    component: TaxCaseDetail
  },
  {
    path: '/tax-cases/:id/workflow/:stageId',
    name: 'WorkflowForm',
    component: WorkflowForm
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
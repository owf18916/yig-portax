import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import * as metadata from '../resources/js/composables/useRevisionFields.js'
import { component } from './support/vue-presentation-fixture.mjs'

const makeModal = async (stageId = 4) => {
  const requests = []
  const props = { stageId, caseId: 9, fields: [], currentDocuments: [], taxCase: {
    currency: { code: 'USD' }, spt_position_type: 'LB', skp_record: { correction_notes: 'Saved note', continue_to_next_stage: false, royalty_correction: '0.00' },
  } }
  const fixture = await component('components/RequestRevisionModalV2.vue', { ...metadata,
    defineProps: () => props, useToast: () => ({ showSuccess() {}, showError() {} }),
    fetch: async (url, options) => {
      requests.push({ url, payload: JSON.parse(options.body.get('payload')) })
      return { ok: true, json: async () => ({ revision: { id: 1 } }) }
    },
  })
  fixture.state.reason.value = 'Explicit G2 correction'
  return { ...fixture, requests }
}

test('all twelve clearability sets match backend final/storage requirements', async () => {
  const php = await readFile(new URL('../app/Config/RevisionFieldConfig.php', import.meta.url), 'utf8')
  const requiredBlock = php.split('private const REQUIRED_VALUES = [')[1].split('];')[0]
  const contracts = php.split('private const CONTRACTS = [')[1].split('    ];')[0]
  for (let stage = 1; stage <= 12; stage++) {
    const line = requiredBlock.split('\n').find(line => new RegExp(`^\\s*${stage} =>`).test(line)) || ''
    const required = [...line.matchAll(/'([^']+)'/g)].map(m => m[1])
    const contract = contracts.split('\n').find(line => new RegExp(`^\\s*${stage} =>`).test(line))
    for (const [, field, rule] of contract.matchAll(/'([^']+)'=>'([^']+)'/g)) {
      assert.equal(metadata.revisionCanClear(stage, field), !required.includes(field) && rule !== 'boolean', `stage ${stage}: ${field}`)
      assert.throws(() => metadata.revisionProposal(stage, field, {}), /proposed value/)
      if (metadata.revisionCanClear(stage, field)) {
        assert.equal(metadata.revisionProposal(stage, field, { [field]: null }), null)
        assert.equal(metadata.revisionProposal(stage, field, { [field]: '' }), null)
      } else {
        assert.throws(() => metadata.revisionProposal(stage, field, { [field]: null }), /cannot be cleared/)
        assert.throws(() => metadata.revisionProposal(stage, field, { [field]: '' }), /cannot be cleared/)
      }
    }
  }
})

test('selecting an unchanged field supplies context without inventing a proposal', async () => {
  const fixture = await makeModal()
  const state = fixture.state
  state.selectedFields.value = ['correction_notes', 'continue_to_next_stage']
  state.handleFieldToggle()
  assert.equal(Object.keys(state.proposedValues.value).length, 0)
  assert.equal(state.savedValue('correction_notes'), 'Saved note')
  assert.equal(state.savedValue('continue_to_next_stage'), 'No')
  assert.equal(state.savedValue('royalty_correction'), 'USD 0.00')
  await state.submit()
  assert.equal(fixture.requests.length, 0)
  assert.match(state.fieldError.value, /Enter a proposed value/)
  assert.match(await fixture.render(metadata), /Saved value: Saved note/)
})

test('actual clear control and checkbox emit multipart null, false and exact zero', async () => {
  const fixture = await makeModal()
  const state = fixture.state
  state.selectedFields.value = ['correction_notes', 'continue_to_next_stage', 'royalty_correction']
  state.handleFieldToggle()
  const flatten = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])]
  const nodes = flatten(fixture.client(metadata))
  const clear = nodes.find(node => node.type === 'button' && String(node.children).includes('Clear'))
  clear.props.onClick()
  // Locate the proposal checkbox (selection checkboxes use the selected-fields array).
  const proposalCheckbox = nodes.filter(node => node.type === 'input' && node.props?.type === 'checkbox').at(-1)
  proposalCheckbox.props['onUpdate:modelValue'](false)
  const money = nodes.find(node => node.type === 'input' && node.props?.inputmode === 'decimal')
  money.props['onUpdate:modelValue']('0')
  await state.submit()
  assert.equal(fixture.requests.length, 1)
  assert.deepEqual(fixture.requests[0].payload.proposed_values, { correction_notes: null, continue_to_next_stage: false, royalty_correction: '0' })
  assert.match(await fixture.render(metadata), /Proposed value: \(empty\)/)
  state.selectedFields.value = ['royalty_correction']; state.handleFieldToggle()
  assert.equal('continue_to_next_stage' in state.proposedValues.value, false)
})

test('required clear, unconfirmed boolean and malformed money stop before network', async () => {
  for (const [field, value] of [['skp_amount', null], ['continue_to_next_stage', ''], ['continue_to_next_stage', undefined],
    ['royalty_correction', '1e3'], ['royalty_correction', '1.234'], ['royalty_correction', 'abc']]) {
    const fixture = await makeModal()
    fixture.state.selectedFields.value = [field]
    fixture.state.proposedValues.value[field] = value
    await fixture.state.submit()
    assert.equal(fixture.requests.length, 0)
    assert.ok(fixture.state.fieldError.value)
    assert.equal(fixture.state.proposedValues.value[field], value)
  }
  assert.equal(metadata.revisionProposal(4, 'royalty_correction', { royalty_correction: 0 }), 0)
})

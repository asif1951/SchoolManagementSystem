<script setup>
/**
 * The subjects that have no plan yet, grouped by class with its shift, medium and group. The whole list arrives once,
 * so every filter works in the browser: choosing, searching and "show more" ask the server for nothing.
 */
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useT } from '@/composables/useT'

const props = defineProps({ items: { type: Array, required: true }, seesAll: Boolean, canMakeMine: Boolean, session: { type: String, required: true } })
const emit = defineEmits(['mine'])
const t = useT('teaching')

const KEYS = ['shift', 'medium', 'level', 'section', 'group', 'subject', 'teacher']
const blank = () => ({ ...Object.fromEntries(KEYS.map((k) => [k, ''])), search: '' })
// remembered for this visit, so coming back from a new plan keeps the place
const stored = () => { try { return JSON.parse(sessionStorage.getItem('teaching.missing') || '{}') } catch { return {} } }
const f = reactive({ ...blank(), ...stored() })
const PAGE = 8
const shown = ref(PAGE)
watch(f, () => { shown.value = PAGE; try { sessionStorage.setItem('teaching.missing', JSON.stringify(f)) } catch { /* private window */ } })

const value = (m, k) => String(m[k] ?? '')
const matches = (m, except = null) => {
    const q = f.search.trim().toLowerCase()
    return KEYS.every((k) => k === except || (k === 'teacher' && !props.seesAll) || !f[k] || value(m, k) === f[k])
        && (!q || `${m.subject} ${m.class} ${m.teacher} ${m.shift ?? ''} ${m.medium ?? ''} ${m.group ?? ''}`.toLowerCase().includes(q))
}
// each list offers only what the other choices still leave, with how many are behind each option
const options = computed(() => Object.fromEntries(KEYS.map((k) => {
    const n = new Map()
    for (const m of props.items) if (value(m, k) && matches(m, k)) n.set(value(m, k), (n.get(value(m, k)) ?? 0) + 1)
    if (f[k] && !n.has(f[k])) n.set(f[k], 0)
    return [k, [...n].sort((a, b) => a[0].localeCompare(b[0], undefined, { numeric: true }))]
})))
// a filter with only one possible answer is not worth showing
const visible = computed(() => KEYS.filter((k) => (k !== 'teacher' || props.seesAll) && (f[k] || new Set(props.items.map((m) => value(m, k)).filter(Boolean)).size > 1)))

const found = computed(() => props.items.filter((m) => matches(m)))
const groups = computed(() => {
    const by = new Map()
    for (const m of found.value) { if (!by.has(m.class_id)) by.set(m.class_id, { ...m, list: [] }); by.get(m.class_id).list.push(m) }
    const text = (g) => `${g.level} ${g.section ?? ''} ${g.shift ?? ''} ${g.medium ?? ''} ${g.group ?? ''}`
    return [...by.values()].sort((a, b) => text(a).localeCompare(text(b), undefined, { numeric: true }))
})
const filtering = computed(() => Object.values(f).some(Boolean))
const clear = () => Object.assign(f, blank())

const making = ref(null)
const key = (m) => `${m.class_id}-${m.subject_id}-${m.teacher_id}`
const make = (m) => { making.value = key(m); router.post(route('teaching.plans.store'), { class_id: m.class_id, subject_id: m.subject_id, teacher_id: m.teacher_id }, { onFinish: () => { making.value = null } }) }
// after a change of session an old choice may match nothing
watch(() => props.session, clear)
</script>

<template>
    <div class="p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">{{ t('plans.missing') }} <span v-if="filtering" class="badge ml-1 bg-brand-100 text-brand-900 dark:bg-brand-900 dark:text-brand-100" role="status">{{ found.length }} / {{ items.length }}</span></h2>
            <button v-if="canMakeMine" type="button" class="btn btn-primary" @click="emit('mine')">{{ t('now.make_mine') }}</button>
        </div>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">ⓘ {{ t('budget.publish') }}</p>

        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            <label class="col-span-2 sm:col-span-1"><span class="field-label">{{ t('missing.search') }}</span><input v-model="f.search" type="search" class="field" :placeholder="t('missing.search_hint')" /></label>
            <label v-for="k in visible" :key="k"><span class="field-label">{{ t(`missing.${k}`) }}</span>
                <select v-model="f[k]" class="field" :class="f[k] && '!border-brand-600 font-semibold'"><option value="">{{ t('all') }}</option><option v-for="[name, n] in options[k]" :key="name" :value="name">{{ name }} ({{ n }})</option></select></label>
            <div v-if="filtering" class="flex items-end"><button type="button" class="btn w-full" @click="clear">× {{ t('missing.clear') }}</button></div>
        </div>

        <p v-if="!found.length" class="mt-3 rounded-md border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-500">{{ t('missing.none') }}</p>
        <ul class="mt-3 divide-y divide-slate-200 dark:divide-slate-800">
            <li v-for="g in groups.slice(0, shown)" :key="g.class_id" class="py-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <h3 class="mr-1 text-sm font-semibold text-slate-900 dark:text-white">{{ g.class }}</h3>
                    <span v-if="g.shift" class="badge border-yellow-400 bg-yellow-50 text-yellow-900">{{ t('missing.shift') }}: {{ g.shift }}</span>
                    <span v-if="g.medium" class="badge border-green-400 bg-green-50 text-green-900">{{ t('missing.medium') }}: {{ g.medium }}</span>
                    <span v-if="g.group" class="badge border-slate-300 bg-slate-100 text-slate-800">{{ g.group }}</span>
                    <span class="text-xs text-slate-500">{{ t('missing.count', { n: g.list.length }) }}</span>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button v-for="m in g.list" :key="key(m)" type="button" class="btn" :disabled="making !== null" @click="make(m)">
                        + {{ m.subject }}<span v-if="seesAll" class="text-slate-400"> · {{ m.teacher }}</span>
                    </button>
                </div>
            </li>
        </ul>
        <button v-if="groups.length > shown" type="button" class="btn mt-1" @click="shown += PAGE * 2">{{ t('missing.more', { n: groups.length - shown }) }}</button>
    </div>
</template>

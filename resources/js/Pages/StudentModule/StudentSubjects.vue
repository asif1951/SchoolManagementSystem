<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    classes: { type: Array, default: () => [] },
    classId: { type: Number, default: null },
    matrix: { type: Object, default: null },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()
const f = reactive({ session: props.session, class_id: props.classId || '' })
const go = () => router.get(route('sm.subjects.index'), { session: f.session, class_id: f.class_id || undefined }, { preserveState: true, replace: true })

// editable copy of every row
const rows = ref([])
watch(() => props.matrix, (m) => { rows.value = (m?.students || []).map((s) => ({ ...s, optional: [...s.optional], group: s.group_id })) }, { immediate: true })

const selected = ref([])
const bulk = reactive({ group_id: '', optional_id: '' })
const allSelected = computed({ get: () => rows.value.length > 0 && selected.value.length === rows.value.length, set: (v) => { selected.value = v ? rows.value.map((r) => r.id) : [] } })

const optionsFor = (row) => (props.matrix.allOptional || []).filter((o) => !o.group_ids.length || (row.group || row.effective_group_id) && o.group_ids.includes(Number(row.group || row.effective_group_id)))

const toggle = (row, id) => {
    const i = row.optional.indexOf(id)
    if (i >= 0) row.optional.splice(i, 1)
    else if (row.optional.length < props.matrix.max) row.optional.push(id)
    else row.optional.splice(0, row.optional.length, id)   // at the limit: the new choice replaces the old
}

const busy = reactive({})
const save = (row) => {
    busy[row.id] = true
    router.put(route('sm.subjects.update', row.id), { group_id: row.group || null, optional: row.optional }, { preserveScroll: true, onFinish: () => { busy[row.id] = false } })
}
const apply = () => router.post(route('sm.subjects.bulk', props.classId), { students: selected.value, group_id: bulk.group_id || null, optional_id: bulk.optional_id || null }, { preserveScroll: true, onSuccess: () => { selected.value = [] } })

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
</script>

<template>
    <Head :title="t('ss_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('ss_title') }}</span></nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div><h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('ss_title') }}</h1><p class="mt-1 text-sm text-slate-500">{{ t('ss_hint') }}</p></div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error || errors.optional || errors.group || errors.student || errors.students" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error || errors.optional || errors.group || errors.student || errors.students }}</div>

                <section class="mb-4 grid grid-cols-1 gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
                    <div><label :class="label" for="s">{{ t('session') }}</label><select id="s" v-model="f.session" :class="input" @change="f.class_id = ''; go()"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
                    <div class="sm:col-span-2"><label :class="label" for="c">{{ t('class') }}</label><select id="c" v-model="f.class_id" :class="input" @change="go"><option value="">—</option><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.label }}</option></select></div>
                </section>

                <p v-if="!matrix" class="rounded-md border border-slate-200 bg-white px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">{{ t('ss_pick') }}</p>

                <template v-else>
                    <section v-if="selected.length" class="mb-3 flex flex-wrap items-end gap-3 rounded-md border border-blue-300 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-950/30">
                        <span class="text-sm font-medium">{{ selected.length }} {{ t('ss_selected') }}</span>
                        <div v-if="matrix.groups.length"><label :class="label" for="bg">{{ t('ss_group') }}</label><select id="bg" v-model="bulk.group_id" :class="[input, 'w-44']"><option value="">—</option><option v-for="g in matrix.groups" :key="g.id" :value="g.id">{{ g.name }}</option></select></div>
                        <div v-if="matrix.allOptional.length"><label :class="label" for="bo">{{ t('ss_optional') }}</label><select id="bo" v-model="bulk.optional_id" :class="[input, 'w-44']"><option value="">—</option><option v-for="o in matrix.allOptional" :key="o.id" :value="o.id">{{ o.name }}</option></select></div>
                        <button type="button" :disabled="!bulk.group_id && !bulk.optional_id" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="apply">{{ t('ss_apply') }}</button>
                    </section>

                    <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="overflow-x-auto"><table class="w-full min-w-[820px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr>
                                <th class="w-10 px-3 py-2.5"><input v-model="allSelected" type="checkbox" :aria-label="t('select_all')" /></th>
                                <th class="px-3 py-2.5">{{ t('roll') }}</th><th class="px-3 py-2.5">{{ t('name_en') }}</th>
                                <th v-if="matrix.groups.length" class="px-3 py-2.5">{{ t('ss_group') }}</th>
                                <th class="px-3 py-2.5">{{ t('ss_compulsory') }}</th><th class="px-3 py-2.5">{{ t('ss_optional') }}</th><th class="px-3 py-2.5"></th></tr></thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="r in rows" :key="r.id">
                                    <td class="px-3 py-2"><input v-model="selected" type="checkbox" :value="r.id" :aria-label="r.name" /></td>
                                    <td class="px-3 py-2 tabular-nums">{{ r.roll }}</td>
                                    <td class="px-3 py-2">{{ r.name }} <span class="font-mono text-xs text-slate-500">{{ r.code }}</span></td>
                                    <td v-if="matrix.groups.length" class="px-3 py-2"><select v-model="r.group" :class="[input, 'w-36']" :aria-label="t('ss_group')"><option :value="null">—</option><option v-for="g in matrix.groups" :key="g.id" :value="g.id">{{ g.name }}</option></select></td>
                                    <td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-400">{{ r.compulsory.join(', ') || '—' }}</td>
                                    <td class="px-3 py-2">
                                        <label v-for="o in optionsFor(r)" :key="o.id" class="mr-3 inline-flex items-center gap-1 text-sm"><input type="checkbox" :checked="r.optional.includes(o.id)" @change="toggle(r, o.id)" />{{ o.name }}</label>
                                        <span v-if="!optionsFor(r).length" class="text-xs text-slate-400">—</span>
                                    </td>
                                    <td class="px-3 py-2 text-right"><button type="button" :disabled="busy[r.id]" class="h-8 rounded-md border border-slate-300 px-3 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="save(r)">{{ t('save') }}</button></td>
                                </tr>
                                <tr v-if="!rows.length"><td colspan="7" class="px-4 py-10 text-center text-slate-500">—</td></tr>
                            </tbody>
                        </table></div>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

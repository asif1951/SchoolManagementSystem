<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    certificates: { type: Object, required: true },
    types: { type: Object, default: () => ({}) },
    allTypes: { type: Object, default: () => ({}) },
    filters: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()
const f = reactive({ ...props.filters })

let filterTimer = null
const load = () => {
    const q = { ...f }
    Object.keys(q).forEach((k) => { if (!q[k]) delete q[k] })
    router.get(route('sm.certificates.index'), q, { preserveState: true, preserveScroll: true, replace: true })
}
watch(() => f.q, () => { clearTimeout(filterTimer); filterTimer = setTimeout(load, 350) })

// issue a certificate
const find = ref('')
const found = ref([])
const chosen = ref(null)
const form = useForm({ profile_id: null, type: Object.keys(props.types)[0] || 'testimonial', issue_date: '', remarks: '' })
let timer = null

watch(find, (q) => {
    clearTimeout(timer)
    if (q.trim().length < 2) { found.value = []; return }
    timer = setTimeout(async () => {
        const res = await fetch(route('sm.images.find', { q }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        found.value = res.ok ? await res.json() : []
    }, 300)
})
onBeforeUnmount(() => { clearTimeout(timer); clearTimeout(filterTimer) })

const pick = (s) => { chosen.value = s; form.profile_id = s.id; found.value = []; find.value = '' }
const issue = () => form.post(route('sm.certificates.store'), { preserveScroll: true, onSuccess: () => { form.reset('remarks', 'issue_date'); chosen.value = null } })

// void
const voiding = ref(null)
const reason = ref('')
const doVoid = () => router.post(route('sm.certificates.void', voiding.value), { reason: reason.value }, { preserveScroll: true, onSuccess: () => { voiding.value = null; reason.value = '' } })

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
</script>

<template>
    <Head :title="t('cert_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('cert_title') }}</span></nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('cert_title') }}</h1>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>

                <section v-if="can.issue" class="mb-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold">{{ t('cert_issue') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">{{ t('cert_tc_note') }}</p>

                    <div v-if="!chosen" class="relative mt-3 max-w-md">
                        <label :class="label" for="find">{{ t('cert_pick_student') }}</label>
                        <input id="find" v-model="find" type="search" autocomplete="off" :placeholder="t('pay_find')" :class="input" />
                        <ul v-if="found.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            <li v-for="s in found" :key="s.id"><button type="button" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="pick(s)"><span>{{ s.name }}</span><span class="font-mono text-xs text-slate-500">{{ s.code }}</span></button></li>
                        </ul>
                    </div>
                    <form v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4" @submit.prevent="issue">
                        <div class="text-sm sm:col-span-4"><b>{{ chosen.name }}</b> <span class="font-mono text-xs text-slate-500">{{ chosen.code }}</span> <button type="button" class="ml-2 text-xs text-blue-700 hover:underline" @click="chosen = null; form.profile_id = null">×</button></div>
                        <div><label :class="label" for="type">{{ t('cert_type') }}</label><select id="type" v-model="form.type" :class="input"><option v-for="(n, k) in types" :key="k" :value="k">{{ n }}</option></select></div>
                        <div><label :class="label" for="date">{{ t('cert_date') }}</label><input id="date" v-model="form.issue_date" type="date" :max="new Date().toISOString().slice(0, 10)" :class="input" /></div>
                        <div class="sm:col-span-2"><label :class="label" for="rem">{{ t('cert_remarks') }}</label><input id="rem" v-model="form.remarks" type="text" maxlength="500" :class="input" /></div>
                        <p v-if="form.errors.type || form.errors.profile_id" class="text-xs text-red-600 sm:col-span-4" role="alert">{{ form.errors.type || form.errors.profile_id }}</p>
                        <div class="sm:col-span-4"><button type="submit" :disabled="form.processing" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">{{ t('cert_issue') }}</button></div>
                    </form>
                </section>

                <section class="mb-3 grid grid-cols-1 gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
                    <div><label :class="label" for="q">{{ t('cert_search') }}</label><input id="q" v-model="f.q" type="search" :class="input" /></div>
                    <div><label :class="label" for="ft">{{ t('cert_type') }}</label><select id="ft" v-model="f.type" :class="input" @change="load"><option value="">{{ t('cert_all_types') }}</option><option v-for="(n, k) in allTypes" :key="k" :value="k">{{ n }}</option></select></div>
                    <div><label :class="label" for="fy">{{ t('session') }}</label><input id="fy" v-model="f.year" type="text" inputmode="numeric" maxlength="4" placeholder="2026" :class="input" @change="load" /></div>
                </section>

                <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-sm">
                        <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th class="px-3 py-2.5">{{ t('cert_serial') }}</th><th class="px-3 py-2.5">{{ t('cert_type') }}</th><th class="px-3 py-2.5">{{ t('cert_pick_student') }}</th><th class="px-3 py-2.5">{{ t('cert_date') }}</th><th class="px-3 py-2.5">{{ t('cert_by') }}</th><th class="px-3 py-2.5 text-right">{{ t('cert_prints') }}</th><th class="px-3 py-2.5"></th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <tr v-for="c in certificates.data" :key="c.id" :class="c.void ? 'opacity-60' : ''">
                                <td class="px-3 py-2 font-mono text-xs" :class="c.void ? 'line-through' : ''">{{ c.serial }}</td>
                                <td class="px-3 py-2">{{ c.type_label }}<span v-if="c.void" class="ml-2 rounded border border-red-300 px-1.5 text-xs text-red-700">{{ t('cert_voided') }}</span></td>
                                <td class="px-3 py-2"><Link :href="route('sm.students.show', c.profile_id)" class="font-medium hover:text-blue-700 hover:underline">{{ c.student }}</Link> <span class="font-mono text-xs text-slate-500">{{ c.code }}</span></td>
                                <td class="px-3 py-2">{{ c.issue_date }}</td><td class="px-3 py-2">{{ c.by }}</td><td class="px-3 py-2 text-right tabular-nums">{{ c.prints }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <a v-if="!c.void" :href="route('sm.certificates.print', c.id)" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ t('cert_print') }}</a>
                                    <button v-if="!c.void && can.issue" type="button" class="ml-1 text-xs font-medium text-red-700 hover:underline" @click="voiding = c.id">{{ t('cert_void') }}</button>
                                </td>
                            </tr>
                            <tr v-if="!certificates.data.length"><td colspan="7" class="px-4 py-10 text-center text-slate-500">—</td></tr>
                        </tbody>
                    </table></div>
                    <div v-if="voiding" class="border-t border-slate-200 p-3 dark:border-slate-800">
                        <label :class="label" for="vr">{{ t('cert_void_reason') }}</label>
                        <div class="flex gap-2"><input id="vr" v-model="reason" type="text" maxlength="255" :class="input" /><button type="button" :disabled="!reason.trim()" class="h-9 rounded-md bg-red-700 px-3 text-sm font-semibold text-white disabled:opacity-50" @click="doVoid">{{ t('cert_void') }}</button><button type="button" class="h-9 rounded-md border border-slate-300 px-3 text-sm dark:border-slate-700" @click="voiding = null">{{ t('cancel') }}</button></div>
                    </div>
                    <div v-if="certificates.links && certificates.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                        <template v-for="(link, i) in certificates.links" :key="i">
                            <span v-if="link.url === null" class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800" v-html="link.label"></span>
                            <Link v-else :href="link.url" preserve-scroll preserve-state :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200']" v-html="link.label" />
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

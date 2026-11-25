<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, reactive, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    students: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()

const f = reactive({
    session: props.filters.session,
    search: props.filters.search || '',
    class_id: props.filters.class_id || '',
    status: props.filters.status || '',
    gender: props.filters.gender || '',
    brn_missing: !!props.filters.brn_missing,
    sort: props.filters.sort || 'roll',
})

const hasFilters = computed(() => f.search || f.class_id || f.status || f.gender || f.brn_missing)

const load = (extra = {}) => {
    const query = { ...f, ...extra }

    Object.keys(query).forEach((k) => {
        if (query[k] === '' || query[k] === false || query[k] == null) delete query[k]
    })

    router.get(route('sm.students.index'), query, { preserveState: true, preserveScroll: true, replace: true })
}

// typing waits for a pause before asking the server; every other filter acts at once
let timer = null
watch(() => f.search, () => {
    clearTimeout(timer)
    timer = setTimeout(() => load(), 350)
})
onBeforeUnmount(() => clearTimeout(timer))

const changeSession = () => {
    f.class_id = ''           // classes belong to a session
    load()
}

const clear = () => {
    Object.assign(f, { search: '', class_id: '', status: '', gender: '', brn_missing: false })
    load()
}

const badge = (status) => (status === 'active'
    ? 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'
    : status === 'suspended'
        ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300'
        : 'border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300')

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
</script>

<template>
    <Head :title="t('students')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-[1500px] px-4 py-5 sm:px-6 lg:px-8 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('entry') }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('students') }}</h1>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('showing', { from: students.from ?? 0, to: students.to ?? 0, total: (students.total ?? 0) + (students.capped ? '+' : '') }) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <LanguageSwitch />
                        <Link v-if="can.create" :href="route('sm.students.quick')" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ t('quick_entry') }}</Link>
                        <Link v-if="can.create" :href="route('sm.students.create')" class="inline-flex h-9 items-center rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800">+ {{ t('add_student') }}</Link>
                    </div>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900">{{ flash.error }}</div>

                <!-- filters -->
                <section class="mb-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12">
                        <div class="lg:col-span-3">
                            <label :class="label" for="f-search">{{ t('students') }}</label>
                            <input id="f-search" v-model="f.search" type="search" :placeholder="t('search_placeholder')" :class="input" autocomplete="off" />
                        </div>
                        <div class="lg:col-span-2">
                            <label :class="label" for="f-session">{{ t('session') }}</label>
                            <select id="f-session" v-model="f.session" :class="input" @change="changeSession">
                                <option v-for="s in sessions" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div class="lg:col-span-3">
                            <label :class="label" for="f-class">{{ t('class_section') }}</label>
                            <select id="f-class" v-model="f.class_id" :class="input" @change="load()">
                                <option value="">{{ t('all_classes') }}</option>
                                <option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                            </select>
                        </div>
                        <div class="lg:col-span-2">
                            <label :class="label" for="f-status">{{ t('status') }}</label>
                            <select id="f-status" v-model="f.status" :class="input" @change="load()">
                                <option value="">{{ t('all_statuses') }}</option>
                                <option v-for="(name, key) in statuses" :key="key" :value="key">{{ name }}</option>
                            </select>
                        </div>
                        <div class="lg:col-span-2">
                            <label :class="label" for="f-gender">{{ t('gender') }}</label>
                            <select id="f-gender" v-model="f.gender" :class="input" @change="load()">
                                <option value="">—</option>
                                <option value="Male">{{ t('male') }}</option>
                                <option value="Female">{{ t('female') }}</option>
                                <option value="Other">{{ t('other') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input v-model="f.brn_missing" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-600" @change="load()" />
                            {{ t('brn_missing') }}
                        </label>

                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                                {{ t('sort_by') }}
                                <select v-model="f.sort" class="h-8 rounded-md border border-slate-300 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-900" @change="load()">
                                    <option value="roll">{{ t('class') }} / {{ t('roll') }}</option>
                                    <option value="name">{{ t('name_en') }}</option>
                                    <option value="code">{{ t('code') }}</option>
                                </select>
                            </label>
                            <button v-if="hasFilters" type="button" class="text-sm font-medium text-blue-700 hover:underline dark:text-blue-400" @click="clear">{{ t('clear_filters') }}</button>
                        </div>
                    </div>
                </section>

                <!-- table -->
                <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[960px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th scope="col" class="px-3 py-2.5">{{ t('roll') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('code') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('name_en') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('class_section') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('gender') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('mobile') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('brn') }}</th>
                                    <th scope="col" class="px-3 py-2.5">{{ t('status') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="s in students.data" :key="s.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-3 py-2.5 tabular-nums">{{ s.roll || '—' }}</td>
                                    <td class="px-3 py-2.5 font-mono text-xs">{{ s.code }}</td>
                                    <td class="px-3 py-2.5 font-semibold">
                                        <Link :href="route('sm.students.show', s.profile_id)" class="text-slate-900 hover:text-blue-700 hover:underline dark:text-white">{{ s.name }}</Link>
                                    </td>
                                    <td class="px-3 py-2.5">{{ s.class }}</td>
                                    <td class="px-3 py-2.5">{{ s.gender || '—' }}</td>
                                    <td class="px-3 py-2.5">{{ s.mobile || '—' }}</td>
                                    <td class="px-3 py-2.5">
                                        <span v-if="s.brn_missing" class="inline-block rounded border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ t('brn_missing') }}</span>
                                        <span v-else class="font-mono text-xs">{{ s.brn }}</span>
                                    </td>
                                    <td class="px-3 py-2.5"><span class="inline-block rounded border px-2 py-0.5 text-xs font-semibold" :class="badge(s.status)">{{ s.status_label }}</span></td>
                                    <td class="px-3 py-2.5 text-right">
                                        <Link :href="route('sm.students.show', s.profile_id)" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">{{ t('view') }}</Link>
                                    </td>
                                </tr>
                                <tr v-if="!students.data.length">
                                    <td colspan="9" class="px-4 py-14 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_students') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="students.links && students.links.length > 2" class="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row dark:border-slate-800">
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ t('showing', { from: students.from ?? 0, to: students.to ?? 0, total: (students.total ?? 0) + (students.capped ? '+' : '') }) }}</p>
                        <div class="flex flex-wrap items-center justify-center gap-1">
                            <template v-for="(link, i) in students.links" :key="i">
                                <span v-if="link.url === null" class="cursor-not-allowed rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800 dark:text-slate-600" v-html="link.label"></span>
                                <Link v-else :href="link.url" preserve-scroll preserve-state
                                      :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200']"
                                      v-html="link.label" />
                            </template>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

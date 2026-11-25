<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useT } from '@/composables/useT'

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    columns: { type: Array, default: () => [] },
    maxRows: { type: Number, default: null },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()

const form = useForm({ file: null, session: props.sessions[0] || String(new Date().getFullYear()) })

const onFile = (e) => { form.file = e.target.files?.[0] || null }
const upload = () => form.post(route('sm.import.upload'), { forceFormData: true })

const statusText = (s) => ({ previewed: t('preview'), running: t('import_running'), done: t('finished'), failed: t('failed'), cancelled: t('cancel') })[s] || s
</script>

<template>
    <Head :title="t('import_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-4xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('import_title') }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('import_title') }}</h1>
                        <p class="mt-0.5 max-w-2xl text-sm text-slate-500 dark:text-slate-400">{{ t('import_intro') }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-sm font-semibold">{{ t('step_template') }}</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a :href="route('sm.import.template', { format: 'xlsx' })" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ t('template_xlsx') }}</a>
                            <a :href="route('sm.import.template', { format: 'csv' })" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ t('template_csv') }}</a>
                        </div>
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ t('columns_help') }}</p>
                        <p class="mt-2 break-words font-mono text-[11px] leading-relaxed text-slate-500">{{ columns.join(' · ') }}</p>
                    </section>

                    <form class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900" novalidate @submit.prevent="upload">
                        <h2 class="text-sm font-semibold">{{ t('step_fill') }}</h2>

                        <label class="mt-3 block text-xs font-medium text-slate-600 dark:text-slate-400" for="session">{{ t('session') }}</label>
                        <select id="session" v-model="form.session" class="mt-1 h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <option v-for="s in sessions" :key="s" :value="s">{{ s }}</option>
                        </select>

                        <label class="mt-3 block text-xs font-medium text-slate-600 dark:text-slate-400" for="file">{{ t('choose_sheet') }}</label>
                        <input id="file" type="file" accept=".xlsx,.csv,.txt" class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:cursor-pointer file:rounded-md file:border file:border-slate-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-slate-300" @change="onFile" />
                        <p v-if="form.errors.file" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.file }}</p>

                        <button type="submit" :disabled="!form.file || form.processing" class="mt-4 h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">{{ form.processing ? t('working') : t('check_file') }}</button>
                    </form>
                </div>

                <section v-if="recent.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('recent_imports') }}</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr><th class="px-4 py-2">{{ t('file') }}</th><th class="px-4 py-2">{{ t('status') }}</th><th class="px-4 py-2 text-right">{{ t('rows_total') }}</th><th class="px-4 py-2 text-right">{{ t('imported') }}</th><th class="px-4 py-2">{{ t('when') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="r in recent" :key="r.id">
                                    <td class="px-4 py-2"><Link :href="route('sm.import.show', r.id)" class="font-medium text-blue-700 hover:underline dark:text-blue-400">{{ r.name }}</Link></td>
                                    <td class="px-4 py-2">{{ statusText(r.status) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ r.total }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ r.created ?? '—' }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-500">{{ r.at }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

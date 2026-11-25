<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    batch: { type: Object, required: true },
    invalidRows: { type: Array, default: () => [] },
    checkTaskId: { type: Number, default: null },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()
const busy = ref(false)

const confirm = () => {
    busy.value = true
    router.post(route('sm.import.confirm', props.batch.id), {}, { onFinish: () => { busy.value = false } })
}

const cancel = () => router.post(route('sm.import.cancel', props.batch.id))

// when the background task ends, reload to show the final result
const finished = () => router.reload()

const card = 'rounded-md border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900'
</script>

<template>
    <Head :title="t('preview')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-4xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    {{ t('module') }} / <Link :href="route('sm.import.index')" class="hover:underline">{{ t('import_title') }}</Link> /
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('preview') }}</span>
                </nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ batch.name }}</h1>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('session') }} {{ batch.session }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <div class="grid grid-cols-3 gap-3">
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('rows_total') }}</p><p class="text-2xl font-semibold tabular-nums">{{ batch.total }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('rows_valid') }}</p><p class="text-2xl font-semibold tabular-nums text-green-700 dark:text-green-400">{{ batch.valid }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('rows_invalid') }}</p><p class="text-2xl font-semibold tabular-nums" :class="batch.invalid ? 'text-amber-700 dark:text-amber-400' : ''">{{ batch.invalid }}</p></div>
                </div>

                <!-- the file is being checked in the background (a large file takes a while) -->
                <section v-if="batch.status === 'checking'" class="mt-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-2 text-sm font-semibold">{{ t('import_checking') }}</h2>
                    <TaskProgress :task-id="checkTaskId" @finished="finished" />
                </section>

                <!-- waiting for the user: nothing has been saved yet -->
                <template v-else-if="batch.status === 'previewed'">
                    <section v-if="invalidRows.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                            <h2 class="text-sm font-semibold">{{ t('rows_to_fix') }}</h2>
                            <a :href="route('sm.import.report', batch.id)" class="text-sm font-medium text-blue-700 hover:underline dark:text-blue-400">{{ t('download_rows') }}</a>
                        </div>
                        <div class="max-h-96 overflow-auto">
                            <table class="w-full min-w-[520px] text-sm">
                                <thead class="sticky top-0 bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr><th class="px-4 py-2">{{ t('row') }}</th><th class="px-4 py-2">{{ t('name_en') }}</th><th class="px-4 py-2">{{ t('reason') }}</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                    <tr v-for="r in invalidRows" :key="r.row">
                                        <td class="px-4 py-2 tabular-nums">{{ r.row }}</td>
                                        <td class="px-4 py-2">{{ r.name || '—' }}</td>
                                        <td class="px-4 py-2 text-slate-700 dark:text-slate-300">{{ r.reason }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <p v-if="batch.valid === 0" class="mt-5 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">{{ t('nothing_valid') }}</p>
                    <p v-else-if="batch.invalid" class="mt-4 text-sm text-slate-600 dark:text-slate-400">{{ t('fix_and_reupload') }}</p>

                    <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                        <button type="button" class="h-9 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" @click="cancel">{{ t('cancel_import') }}</button>
                        <button type="button" :disabled="batch.valid === 0 || busy" class="h-9 rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="confirm">{{ t('confirm_import', { count: batch.valid }) }}</button>
                    </div>
                </template>

                <!-- saving in the background -->
                <section v-else-if="batch.status === 'running'" class="mt-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-2 text-sm font-semibold">{{ t('import_running') }}</h2>
                    <TaskProgress :task-id="batch.task_id" @finished="finished" />
                </section>

                <!-- finished -->
                <section v-else-if="batch.status === 'done'" class="mt-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold">{{ t('import_done') }}</h2>
                    <p class="mt-1 text-sm text-green-700 dark:text-green-400">{{ t('import_created', { count: batch.summary?.created ?? 0 }) }}</p>
                    <p v-if="batch.summary?.failed_count" class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        {{ t('import_failed_rows', { count: batch.summary.failed_count }) }}
                        <a v-if="batch.task_id" :href="route('sm.tasks.report', batch.task_id)" class="ml-2 font-medium text-blue-700 hover:underline dark:text-blue-400">{{ t('download_rows') }}</a>
                    </p>
                    <Link :href="route('sm.students.index')" class="mt-3 inline-flex h-9 items-center rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800">{{ t('view_students') }}</Link>
                </section>

                <p v-else-if="batch.status === 'failed'" class="mt-5 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300" role="alert">{{ batch.summary?.error }}</p>
                <p v-else class="mt-5 text-sm text-slate-500">{{ t('import_cancelled') }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

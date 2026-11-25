<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ taskId: { type: Number, required: true } })

const t = useT()
const result = ref(null)

const finished = (task) => { result.value = task }
</script>

<template>
    <Head :title="t('export_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
                <header class="mb-5 flex items-start justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('export_title') }}</h1>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ t('export_hint') }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <TaskProgress :task-id="taskId" @finished="finished" />

                    <div v-if="result?.status === 'done'" class="mt-4">
                        <p class="text-sm text-green-700 dark:text-green-400">{{ t('export_ready', { count: Number(result.summary?.rows ?? 0).toLocaleString() }) }}</p>
                        <a :href="route('sm.tasks.download', taskId)" class="mt-3 inline-flex h-10 items-center rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800">{{ t('export_download') }}</a>
                    </div>
                    <p v-else-if="result?.status === 'failed'" class="mt-4 text-sm text-red-700 dark:text-red-400" role="alert">{{ result.error }}</p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

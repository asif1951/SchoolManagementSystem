<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import ClassForm from '@/Components/ClassForm.vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    classes: { type: Object, default: () => ({ data: [] }) },
    teachers: { type: Array, default: () => [] },
    subjectOptions: { type: Array, default: () => [] },
    studyGroups: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    mediums: { type: Array, default: () => [] },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})

const blank = () => ({
    studyGroup: '', shiftId: props.shifts[0]?.id ?? '', mediumId: props.mediums[0]?.id ?? '',
    className: '', classTitle: '', section: '', sectionTitle: '',
    academicYear: String(new Date().getFullYear()), classTeacher: '', status: 'active', subjects: [], maxStudents: null, description: '',
})

const form = useForm(blank())

const save = () => form.post('/classes', { preserveScroll: true, onSuccess: () => form.defaults(blank()).reset() })
const reset = () => { form.defaults(blank()).reset(); form.clearErrors() }

const latest = computed(() => props.classes?.data || [])
</script>

<template>
    <Head title="Create class" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    <Link href="/classesmanagement" class="hover:underline">Classes</Link> / <span class="font-medium text-slate-700 dark:text-slate-200">Create class</span>
                </nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Create class</h1>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Give the class its name, an optional title of your own, its sections, shift, medium, teacher and subjects.</p>
                    </div>
                    <Link href="/classesmanagement" class="btn">All classes</Link>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <section class="panel p-5">
                    <ClassForm :form="form" mode="create" :teachers="teachers" :subject-options="subjectOptions" :study-groups="studyGroups" :shifts="shifts" :mediums="mediums" submit-label="Create class" @submit="save" @cancel="reset" />
                </section>

                <section v-if="latest.length" class="panel mt-6 overflow-hidden">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                        <h2 class="text-sm font-semibold">Latest classes</h2>
                        <Link href="/classesmanagement" class="text-sm font-medium text-brand-700 hover:underline">View all</Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[560px]">
                            <thead><tr><th>Class</th><th>Section</th><th>Shift · Medium</th><th>Year</th></tr></thead>
                            <tbody>
                                <tr v-for="c in latest" :key="c.id">
                                    <td class="font-medium text-slate-900 dark:text-white">{{ c.class_label }}</td>
                                    <td>{{ c.section_label || '—' }}</td>
                                    <td>{{ [c.shift_name, c.medium_name].filter(Boolean).join(' · ') || '—' }}</td>
                                    <td class="tabular-nums">{{ c.academic_year }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

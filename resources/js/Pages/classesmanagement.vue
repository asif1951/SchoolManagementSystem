<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import ClassForm from '@/Components/ClassForm.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
    classes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
    teachers: { type: Array, default: () => [] },
    subjectOptions: { type: Array, default: () => [] },
    studyGroups: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    mediums: { type: Array, default: () => [] },
    availableYears: { type: Array, default: () => [] },
    selectedYear: { type: String, default: '' },
})

// ---- filters ------------------------------------------------------------------------------------------------------
const year = ref(String(props.selectedYear || props.filters?.academic_year || new Date().getFullYear()))
const search = ref(props.filters.search || '')
const status = ref(props.filters.status || '')
const sortField = ref(props.filters.sortField || 'class_name')
const sortDirection = ref(props.filters.sortDirection || 'asc')

// the years that have classes, this year, and next year (choosing it copies the class list forward)
const years = computed(() => {
    const set = new Set((props.availableYears || []).map(String))
    const now = new Date().getFullYear()
    set.add(String(now)); set.add(String(now + 1))
    const max = Math.max(...[...set].map(Number))
    set.add(String(max))
    return [...set].sort((a, b) => b.localeCompare(a))
})

const load = (page = 1) => router.get('/classesmanagement', {
    page, academic_year: year.value, search: search.value || undefined, status: status.value || undefined,
    sortField: sortField.value, sortDirection: sortDirection.value,
}, { preserveState: true, preserveScroll: true, replace: true })

let timer = null
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
onBeforeUnmount(() => clearTimeout(timer))

const reset = () => { search.value = ''; status.value = ''; sortField.value = 'class_name'; sortDirection.value = 'asc'; load(1) }
const sortBy = (field) => {
    if (sortField.value === field) sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    else { sortField.value = field; sortDirection.value = 'asc' }
    load(1)
}
const arrow = (field) => (sortField.value === field ? (sortDirection.value === 'asc' ? '↑' : '↓') : '')

const rows = computed(() => props.classes?.data || [])
const total = computed(() => props.classes?.total ?? rows.value.length)
const activeCount = computed(() => rows.value.filter((c) => c.status === 'active').length)

// ---- messages -----------------------------------------------------------------------------------------------------
const message = ref(null)   // { type, text }
const show = (type, text) => { message.value = { type, text }; if (type === 'success') setTimeout(() => { message.value = null }, 5000) }
onMounted(() => {
    ['success', 'error', 'warning'].forEach((t) => { if (props.flash?.[t]) show(t, props.flash[t]) })
    window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
watch(() => props.flash, (f) => { ['success', 'error', 'warning'].forEach((t) => { if (f?.[t]) show(t, f[t]) }) }, { deep: true })

// ---- create / edit ------------------------------------------------------------------------------------------------
const blank = () => ({
    studyGroup: '', shiftId: props.shifts[0]?.id ?? '', mediumId: props.mediums[0]?.id ?? '',
    className: '', classTitle: '', section: '', sectionTitle: '',
    academicYear: String(year.value), classTeacher: '', status: 'active', subjects: [], maxStudents: null, description: '',
})
const createForm = useForm(blank())
const editForm = useForm(blank())

const dialog = ref(null)       // 'create' | 'edit' | 'delete'
const current = ref(null)
const close = () => { dialog.value = null; current.value = null; createForm.clearErrors(); editForm.clearErrors() }
const onKey = (e) => { if (e.key === 'Escape' && dialog.value) close() }

const openCreate = () => { createForm.defaults(blank()).reset(); createForm.academicYear = String(year.value); dialog.value = 'create' }

const openEdit = (c) => {
    current.value = c
    let subjects = c.subjects || []
    if (typeof subjects === 'string') { try { subjects = JSON.parse(subjects) } catch { subjects = [] } }
    const teacher = props.teachers.find((t) => t.name === c.class_teacher)

    editForm.defaults({
        studyGroup: c.study_group_id || '', shiftId: c.shift_id || props.shifts[0]?.id || '', mediumId: c.medium_id || props.mediums[0]?.id || '',
        className: c.class_name || '', classTitle: c.class_title || '', section: c.section || '', sectionTitle: c.section_title || '',
        academicYear: c.academic_year || '', classTeacher: teacher?.id || '', status: c.status || 'active',
        subjects: (Array.isArray(subjects) ? subjects : []).map((s) => String(s).trim()).filter(Boolean),
        maxStudents: c.max_students ?? null, description: c.description || '',
    }).reset()
    dialog.value = 'edit'
}

const save = () => createForm.post('/classesmanagement', { preserveScroll: true, onSuccess: close })
const update = () => editForm.put(`/classesmanagement/${current.value.id}`, { preserveScroll: true, onSuccess: close })

const askDelete = (c) => { current.value = c; dialog.value = 'delete' }
const destroy = () => router.delete(`/classesmanagement/${current.value.id}`, { preserveScroll: true, onSuccess: close, onError: () => show('error', 'The class could not be deleted.') })

const subjectsOf = (c) => (Array.isArray(c.subjects) ? c.subjects : [])
</script>

<template>
    <Head title="Classes" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">Classes / <span class="font-medium text-slate-700 dark:text-slate-200">All classes</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Classes</h1>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ total }} {{ total === 1 ? 'class' : 'classes' }} in {{ year }}<template v-if="rows.length"> · {{ activeCount }} active on this page</template></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link href="/class-settings" class="btn">Shifts &amp; mediums</Link>
                        <button type="button" class="btn btn-primary" @click="openCreate">Create class</button>
                    </div>
                </header>

                <div v-if="message" class="mb-4 flex items-start justify-between gap-3 rounded-md border border-l-4 bg-white px-4 py-3 text-sm dark:bg-slate-900"
                     :class="{ 'border-slate-200 border-l-green-600': message.type === 'success', 'border-slate-200 border-l-red-600': message.type === 'error', 'border-slate-200 border-l-amber-500': message.type === 'warning' }"
                     :role="message.type === 'error' ? 'alert' : 'status'">
                    <span>{{ message.text }}</span>
                    <button type="button" class="text-slate-400 hover:text-slate-700" aria-label="Dismiss" @click="message = null">×</button>
                </div>

                <!-- filters -->
                <section class="panel mb-3 p-4">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <div>
                            <label class="field-label" for="f-year">Academic year</label>
                            <select id="f-year" v-model="year" class="field" @change="load(1)"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                        </div>
                        <div class="col-span-2 md:col-span-2">
                            <label class="field-label" for="f-search">Search</label>
                            <input id="f-search" v-model="search" type="search" class="field" placeholder="Class name or title, section title" />
                        </div>
                        <div>
                            <label class="field-label" for="f-status">Status</label>
                            <select id="f-status" v-model="status" class="field" @change="load(1)"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        </div>
                    </div>
                    <div v-if="search || status" class="mt-3"><button type="button" class="text-sm font-medium text-brand-700 hover:underline" @click="reset">Clear filters</button></div>
                </section>

                <!-- the table -->
                <section class="panel overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[860px]">
                            <thead>
                                <tr>
                                    <th><button type="button" class="uppercase tracking-[0.06em]" @click="sortBy('class_name')">Class {{ arrow('class_name') }}</button></th>
                                    <th><button type="button" class="uppercase tracking-[0.06em]" @click="sortBy('section')">Section {{ arrow('section') }}</button></th>
                                    <th>Class teacher</th>
                                    <th>Shift · Medium</th>
                                    <th>Subjects</th>
                                    <th><button type="button" class="uppercase tracking-[0.06em]" @click="sortBy('academic_year')">Year {{ arrow('academic_year') }}</button></th>
                                    <th><button type="button" class="uppercase tracking-[0.06em]" @click="sortBy('status')">Status {{ arrow('status') }}</button></th>
                                    <th class="num">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in rows" :key="c.id">
                                    <td>
                                        <span class="font-medium text-slate-900 dark:text-white">{{ c.class_label }}</span>
                                        <span v-if="c.group_name" class="badge ml-2 border-brand-200 bg-brand-50 text-brand-800">{{ c.group_name }}</span>
                                    </td>
                                    <td>{{ c.section_label || '—' }}</td>
                                    <td>{{ c.class_teacher || '—' }}</td>
                                    <td class="whitespace-nowrap">{{ [c.shift_name, c.medium_name].filter(Boolean).join(' · ') || '—' }}</td>
                                    <td :title="subjectsOf(c).join(', ')"><span class="tabular-nums">{{ subjectsOf(c).length }}</span> <span class="muted">{{ subjectsOf(c).length === 1 ? 'subject' : 'subjects' }}</span></td>
                                    <td class="tabular-nums">{{ c.academic_year }}</td>
                                    <td>
                                        <span class="badge" :class="c.status === 'active' ? 'border-green-200 bg-green-50 text-green-800' : 'border-slate-200 bg-slate-100 text-slate-600'">{{ c.status === 'active' ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="num whitespace-nowrap">
                                        <button type="button" class="text-sm font-medium text-brand-700 hover:underline" @click="openEdit(c)">Edit</button>
                                        <button type="button" class="ml-3 text-sm font-medium text-red-700 hover:underline" @click="askDelete(c)">Delete</button>
                                    </td>
                                </tr>
                                <tr v-if="!rows.length"><td colspan="8" class="px-4 py-12 text-center text-slate-500">No classes match. <button type="button" class="font-medium text-brand-700 hover:underline" @click="openCreate">Create a class</button></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="classes.links && classes.links.length > 3" class="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row dark:border-slate-800">
                        <p class="text-sm text-slate-600 dark:text-slate-400">Showing {{ classes.from ?? 0 }}–{{ classes.to ?? 0 }} of {{ classes.total }}</p>
                        <div class="flex flex-wrap gap-1">
                            <template v-for="(link, i) in classes.links" :key="i">
                                <span v-if="link.url === null" class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300" v-html="link.label"></span>
                                <Link v-else :href="link.url" preserve-scroll preserve-state :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50']" v-html="link.label" />
                            </template>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- dialogs -->
        <div v-if="dialog" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" @mousedown.self="close">
            <div class="w-full rounded-lg bg-white shadow-xl dark:bg-slate-900" :class="dialog === 'delete' ? 'max-w-md' : 'max-w-3xl'" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ dialog === 'create' ? 'Create class' : dialog === 'edit' ? 'Edit class' : 'Delete class' }}</h2>
                    <button type="button" class="text-xl leading-none text-slate-400 hover:text-slate-700" aria-label="Close" @click="close">×</button>
                </div>

                <div class="px-5 py-5">
                    <ClassForm v-if="dialog === 'create'" :form="createForm" mode="create" :teachers="teachers" :subject-options="subjectOptions" :study-groups="studyGroups" :shifts="shifts" :mediums="mediums" submit-label="Create class" @submit="save" @cancel="close" />
                    <ClassForm v-else-if="dialog === 'edit'" :form="editForm" mode="edit" :teachers="teachers" :subject-options="subjectOptions" :study-groups="studyGroups" :shifts="shifts" :mediums="mediums" submit-label="Save changes" @submit="update" @cancel="close" />

                    <div v-else>
                        <p class="text-sm text-slate-700 dark:text-slate-300">Delete <b>{{ current?.class_label }}<template v-if="current?.section_label"> — {{ current.section_label }}</template></b> ({{ current?.academic_year }})? This cannot be undone. A class that has students, marks or attendance cannot be deleted.</p>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="btn" @click="close">Cancel</button>
                            <button type="button" class="btn btn-primary !border-red-700 !bg-red-700 hover:!bg-red-800" @click="destroy">Delete class</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

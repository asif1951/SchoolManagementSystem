<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, router, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    teachers: { type: Array, default: () => [] },
    filters:  { type: Object, default: () => ({}) },
})

const page = usePage()

// ============================================================
// STATE
// ============================================================
const showModal = ref(false)
const editingTeacher = ref(null)
const search = ref(props.filters?.search ?? '')
const statusFilter = ref(props.filters?.status ?? 'all')

// ============================================================
// FORM
// ============================================================
const form = useForm({
    name:               '',
    photo:              null,
    designation:        '',
    department:         '',
    qualification:      '',
    experience:         '',
    bio:                '',
    phone:              '',
    email:              '',
    display_on_website: true,
    status:             'active',
    order:              0,
})

// ============================================================
// TOAST
// ============================================================
const toast = ref({ show: false, message: '', type: 'success' })
let toastTimeout = null

const showToast = (message, type = 'success') => {
    if (toastTimeout) clearTimeout(toastTimeout)
    toast.value = { show: true, message, type }
    toastTimeout = setTimeout(() => (toast.value.show = false), 3500)
}

onMounted(() => {
    if (page.props.flash?.success) showToast(page.props.flash.success, 'success')
    if (page.props.flash?.error) showToast(page.props.flash.error, 'error')
})

// ============================================================
// AUTO NEXT ORDER
// ============================================================
const nextOrder = computed(() => {
    if (props.teachers.length === 0) return 1
    const max = Math.max(...props.teachers.map(t => Number(t.order) || 0))
    return max + 1
})

// ============================================================
// MODAL
// ============================================================
const openCreateModal = () => {
    editingTeacher.value = null
    form.reset()
    form.clearErrors()
    form.display_on_website = true
    form.status = 'active'
    form.order = nextOrder.value
    photoPreview.value = null
    showModal.value = true
    document.body.style.overflow = 'hidden'
}

const openEditModal = (teacher) => {
    editingTeacher.value = teacher
    form.clearErrors()
    form.name               = teacher.name ?? ''
    form.designation        = teacher.designation ?? ''
    form.department         = teacher.department ?? ''
    form.qualification      = teacher.qualification ?? ''
    form.experience         = teacher.experience ?? ''
    form.bio                = teacher.bio ?? ''
    form.phone              = teacher.phone ?? ''
    form.email              = teacher.email ?? ''
    form.display_on_website = Boolean(teacher.display_on_website)
    form.status             = teacher.status ?? 'active'
    form.order              = teacher.order ?? 0
    form.photo              = null
    photoPreview.value      = null
    showModal.value = true
    document.body.style.overflow = 'hidden'
}

const closeModal = () => {
    showModal.value = false
    editingTeacher.value = null
    form.reset()
    form.clearErrors()
    photoPreview.value = null
    if (fileInputRef.value) fileInputRef.value.value = ''
    document.body.style.overflow = ''
}

// ============================================================
// PHOTO
// ============================================================
const photoPreview = ref(null)
const isDragging = ref(false)
const fileInputRef = ref(null)

const handleFile = (file) => {
    if (!file) return
    form.photo = file
    if (file.type.startsWith('image/')) photoPreview.value = URL.createObjectURL(file)
}

const onPhotoChange = (e) => handleFile(e.target.files?.[0])
const onDrop = (e) => {
    e.preventDefault()
    isDragging.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file) handleFile(file)
}
const onDragOver = (e) => { e.preventDefault(); isDragging.value = true }
const onDragLeave = () => { isDragging.value = false }
const openFilePicker = () => fileInputRef.value?.click()
const removePhoto = () => {
    form.photo = null
    photoPreview.value = null
    if (fileInputRef.value) fileInputRef.value.value = ''
}

// ============================================================
// SUBMIT
// ============================================================
const submit = () => {
    if (editingTeacher.value) {
        form.post(route('website-management.teachers-staff.update', editingTeacher.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (resp) => {
                showToast(resp.props.flash?.success || 'Teacher updated! ✅', 'success')
                closeModal()
            },
            onError: (errors) => showToast(Object.values(errors)[0] || 'Please fix the errors.', 'error'),
        })
    } else {
        form.post(route('website-management.teachers-staff.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (resp) => {
                showToast(resp.props.flash?.success || 'Teacher added! ✅', 'success')
                closeModal()
            },
            onError: (errors) => showToast(Object.values(errors)[0] || 'Please fix the errors.', 'error'),
        })
    }
}

// ============================================================
// DELETE
// ============================================================
const deleteTeacher = (teacher) => {
    if (!confirm(`Delete "${teacher.name}"?`)) return
    router.delete(route('website-management.teachers-staff.destroy', teacher.id), {
        preserveScroll: true,
        onSuccess: (resp) => showToast(resp.props.flash?.success || 'Deleted! ✅', 'success'),
        onError: () => showToast('Failed to delete.', 'error'),
    })
}

// ============================================================
// SEARCH + FILTER
// ============================================================
let searchTimeout = null
watch(search, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        router.get(route('website-management.teachers-staff'), {
            search: search.value,
            status: statusFilter.value,
        }, { preserveScroll: true, preserveState: true, replace: true })
    }, 400)
})

watch(statusFilter, () => {
    router.get(route('website-management.teachers-staff'), {
        search: search.value,
        status: statusFilter.value,
    }, { preserveScroll: true, preserveState: true, replace: true })
})

// ============================================================
// HELPERS
// ============================================================
const statusMeta = (status) => {
    const map = {
        active:   { label: 'Active',   cls: 'bg-emerald-100 text-emerald-700 border-emerald-300 dark:bg-emerald-900/50 dark:text-emerald-300 dark:border-emerald-700', dot: 'bg-emerald-500' },
        inactive: { label: 'Inactive', cls: 'bg-slate-100 text-slate-600 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-600',       dot: 'bg-slate-400' },
    }
    return map[status] ?? map.active
}
</script>

<template>
    <Head title="Teachers & Staff" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 px-2.5 py-3 xs:px-3 xs:py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-16 -left-16 h-32 w-32 xs:h-40 xs:w-40 sm:-top-24 sm:-left-24 sm:h-64 sm:w-64 md:h-72 md:w-72 rounded-full bg-indigo-200/30 blur-3xl dark:bg-indigo-800/20"></div>
                <div class="absolute top-1/3 -right-16 h-32 w-32 xs:h-40 xs:w-40 sm:-right-24 sm:h-64 sm:w-64 md:h-72 md:w-72 rounded-full bg-purple-200/30 blur-3xl dark:bg-purple-800/20"></div>
            </div>

            <div class="relative w-full">

                <!-- ============================================================ -->
                <!-- INLINE TOAST -->
                <!-- ============================================================ -->
                <Transition
                    enter-active-class="transition ease-out duration-300"
                    enter-from-class="opacity-0 -translate-y-3 scale-[0.98]"
                    enter-to-class="opacity-100 translate-y-0 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 scale-100"
                    leave-to-class="opacity-0 -translate-y-3 scale-[0.98]"
                >
                    <div
                        v-if="toast.show"
                        :class="[
                            'mb-3 xs:mb-4 sm:mb-6 flex items-start gap-2.5 xs:gap-3 rounded-xl xs:rounded-2xl border-2 p-2.5 xs:p-3 sm:p-4 shadow-lg backdrop-blur-sm',
                            toast.type === 'success' ? 'bg-emerald-50/90 border-emerald-300 text-emerald-800 dark:bg-emerald-950/80 dark:border-emerald-700 dark:text-emerald-200' : '',
                            toast.type === 'error'   ? 'bg-rose-50/90 border-rose-300 text-rose-800 dark:bg-rose-950/80 dark:border-rose-700 dark:text-rose-200' : '',
                            toast.type === 'info'    ? 'bg-blue-50/90 border-blue-300 text-blue-800 dark:bg-blue-950/80 dark:border-blue-700 dark:text-blue-200' : '',
                        ]"
                    >
                        <span class="text-base xs:text-lg sm:text-xl flex-shrink-0 leading-none mt-0.5">
                            {{ toast.type === 'success' ? '✅' : toast.type === 'error' ? '❌' : 'ℹ️' }}
                        </span>
                        <p class="text-[11px] xs:text-xs sm:text-sm font-semibold flex-1 break-words leading-relaxed">{{ toast.message }}</p>
                        <button type="button" @click="toast.show = false" class="flex-shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100 dark:text-slate-500 dark:hover:bg-slate-800">
                            <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </Transition>

                <!-- ============================================================ -->
                <!-- PAGE HEADER -->
                <!-- ============================================================ -->
                <div class="mb-4 xs:mb-5 sm:mb-6 lg:mb-8">
                    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-3 xs:gap-4">
                        <!-- Title -->
                        <div class="flex items-center gap-2.5 xs:gap-3 sm:gap-4 w-full lg:w-auto">
                            <div class="p-2 xs:p-2.5 sm:p-3 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-xl xs:rounded-2xl shadow-lg shadow-indigo-500/20 flex-shrink-0">
                                <svg class="w-5 h-5 xs:w-6 xs:h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent truncate dark:from-slate-100 dark:to-slate-400">
                                    Teachers & Staff
                                </h1>
                                <p class="text-[10px] xs:text-xs sm:text-sm text-slate-500 flex items-center gap-1.5 xs:gap-2 mt-0.5 xs:mt-1 dark:text-slate-400">
                                    <span class="inline-block w-1 h-1 xs:w-1.5 xs:h-1.5 rounded-full bg-indigo-500"></span>
                                    <span class="truncate">Manage teachers — reflect on public website automatically</span>
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-row items-stretch sm:items-center gap-2 sm:gap-3 w-full lg:w-auto">
                            <Link
                                :href="route('website-management.settings')"
                                class="group inline-flex items-center justify-center gap-1.5 xs:gap-2 rounded-lg xs:rounded-xl border border-slate-200 bg-white/80 backdrop-blur-sm px-3 xs:px-4 sm:px-5 py-2 xs:py-2.5 text-xs xs:text-sm font-medium text-slate-700 transition hover:bg-white hover:border-slate-300 hover:shadow-lg flex-1 sm:flex-initial dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:border-slate-600"
                            >
                                <svg class="w-3.5 h-3.5 xs:w-4 xs:h-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                <span>Back</span>
                            </Link>
                            <button
                                type="button"
                                @click="openCreateModal"
                                class="inline-flex items-center justify-center gap-1.5 xs:gap-2 rounded-lg xs:rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 px-3 xs:px-4 sm:px-6 py-2 xs:py-2.5 text-xs xs:text-sm font-semibold text-white transition hover:from-indigo-700 hover:to-purple-700 hover:shadow-lg hover:shadow-indigo-500/30 active:scale-95 flex-1 sm:flex-initial"
                            >
                                <svg class="w-3.5 h-3.5 xs:w-4 xs:h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <span class="whitespace-nowrap">Add Teacher</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- FILTERS -->
                <!-- ============================================================ -->
                <div class="mb-4 xs:mb-5 sm:mb-6 rounded-xl xs:rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-2.5 xs:p-3 sm:p-4 dark:bg-slate-900/80 dark:border-slate-800">
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 xs:gap-3">
                        <!-- Search -->
                        <div class="relative flex-1">
                            <svg class="absolute left-3 xs:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 xs:w-4 xs:h-4 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search..."
                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 pl-8 xs:pl-10 pr-3 xs:pr-4 text-xs xs:text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                            />
                        </div>

                        <!-- Status filters — scrollable on mobile -->
                        <div class="flex items-center gap-1.5 xs:gap-2 overflow-x-auto pb-1 sm:pb-0 -mx-2.5 xs:-mx-3 sm:mx-0 px-2.5 xs:px-3 sm:px-0 scrollbar-thin">
                            <button
                                v-for="opt in [
                                    { val: 'all', label: 'All' },
                                    { val: 'active', label: 'Active' },
                                    { val: 'inactive', label: 'Inactive' },
                                ]"
                                :key="opt.val"
                                type="button"
                                @click="statusFilter = opt.val"
                                :class="[
                                    'px-2.5 xs:px-3 py-1.5 xs:py-2 text-[10px] xs:text-xs font-semibold rounded-lg xs:rounded-xl transition whitespace-nowrap flex-shrink-0',
                                    statusFilter === opt.val
                                        ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-md shadow-indigo-500/30'
                                        : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700 dark:hover:border-slate-600'
                                ]"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- EMPTY STATE -->
                <!-- ============================================================ -->
                <div v-if="teachers.length === 0" class="rounded-xl xs:rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-6 xs:p-8 sm:p-16 text-center dark:bg-slate-900/80 dark:border-slate-800">
                    <div class="mx-auto flex h-14 w-14 xs:h-16 xs:w-16 sm:h-20 sm:w-20 items-center justify-center rounded-2xl xs:rounded-3xl bg-gradient-to-br from-indigo-100 to-purple-100 mb-3 xs:mb-4 dark:from-indigo-900/40 dark:to-purple-900/40">
                        <svg class="h-7 w-7 xs:h-8 xs:w-8 sm:h-10 sm:w-10 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                        </svg>
                    </div>
                    <h3 class="text-base xs:text-lg font-bold text-slate-800 dark:text-slate-100">No teachers yet</h3>
                    <p class="mt-1 text-xs xs:text-sm text-slate-500 dark:text-slate-400">Add your first teacher to display on the website.</p>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="mt-4 xs:mt-5 inline-flex items-center gap-1.5 xs:gap-2 rounded-lg xs:rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 px-4 xs:px-5 py-2 xs:py-2.5 text-xs xs:text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:from-indigo-700 hover:to-purple-700 active:scale-95"
                    >
                        <svg class="w-3.5 h-3.5 xs:w-4 xs:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Your First Teacher
                    </button>
                </div>

                <!-- ============================================================ -->
                <!-- TEACHERS GRID -->
                <!-- ============================================================ -->
                <div v-else class="grid grid-cols-1 xs:grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3 xs:gap-4 sm:gap-5">
                    <div
                        v-for="teacher in teachers"
                        :key="teacher.id"
                        class="group relative overflow-hidden rounded-xl xs:rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm hover:shadow-xl hover:border-indigo-200 transition-all duration-300 flex flex-col h-full dark:bg-slate-900/80 dark:border-slate-800 dark:hover:border-indigo-700"
                    >
                        <!-- Photo -->
                        <div class="relative aspect-[4/5] xs:aspect-square w-full overflow-hidden bg-gradient-to-br from-indigo-500 to-purple-600 flex-shrink-0">
                            <img
                                v-if="teacher.photo"
                                :src="`/storage/${teacher.photo}`"
                                :alt="teacher.name"
                                class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                loading="lazy"
                            />
                            <div v-else class="flex h-full items-center justify-center">
                                <span class="text-4xl xs:text-5xl font-bold text-white/60">{{ teacher.name?.charAt(0)?.toUpperCase() }}</span>
                            </div>

                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/70 via-transparent to-transparent"></div>

                            <!-- Status + Order — stacked vertically, smaller on mobile -->
                            <div class="absolute top-2 xs:top-3 left-2 xs:left-3 flex flex-col gap-1 xs:gap-1.5">
                                <span :class="[
                                    'inline-flex items-center gap-1 xs:gap-1.5 px-1.5 xs:px-2.5 py-0.5 xs:py-1 rounded-full text-[8px] xs:text-[10px] font-bold border xs:border-2 backdrop-blur-sm',
                                    statusMeta(teacher.status).cls
                                ]">
                                    <span :class="['h-1 w-1 xs:h-1.5 xs:w-1.5 rounded-full', statusMeta(teacher.status).dot]"></span>
                                    {{ statusMeta(teacher.status).label }}
                                </span>
                                <span class="inline-flex items-center gap-0.5 xs:gap-1 rounded-full bg-white/95 backdrop-blur-sm px-1.5 xs:px-2.5 py-0.5 xs:py-1 text-[8px] xs:text-[10px] font-bold text-indigo-700 border xs:border-2 border-white shadow-lg dark:bg-slate-800/95 dark:text-indigo-300 dark:border-slate-700">
                                    <svg class="h-2 w-2 xs:h-3 xs:w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                                    </svg>
                                    #{{ teacher.order ?? 0 }}
                                </span>
                            </div>

                            <!-- Website visibility badge — smaller mobile -->
                            <div class="absolute top-2 xs:top-3 right-2 xs:right-3">
                                <span
                                    v-if="teacher.display_on_website"
                                    class="inline-flex items-center gap-0.5 xs:gap-1 rounded-full bg-emerald-500/95 backdrop-blur-sm px-1.5 xs:px-2.5 py-0.5 xs:py-1 text-[8px] xs:text-[10px] font-bold text-white border xs:border-2 border-emerald-400 shadow-lg"
                                >
                                    <span class="hidden xs:inline">🌐</span>
                                    <span class="xs:hidden">✓</span>
                                    <span class="hidden xs:inline">On Website</span>
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-0.5 xs:gap-1 rounded-full bg-slate-500/95 backdrop-blur-sm px-1.5 xs:px-2.5 py-0.5 xs:py-1 text-[8px] xs:text-[10px] font-bold text-white border xs:border-2 border-slate-400 shadow-lg"
                                >
                                    <span class="hidden xs:inline">🚫</span>
                                    <span class="xs:hidden">✗</span>
                                    <span class="hidden xs:inline">Hidden</span>
                                </span>
                            </div>

                            <!-- Name/designation -->
                            <div class="absolute bottom-2 xs:bottom-3 left-2 xs:left-3 right-2 xs:right-3">
                                <h3 class="text-xs xs:text-sm sm:text-base lg:text-lg font-bold text-white drop-shadow-md truncate">
                                    {{ teacher.name }}
                                </h3>
                                <p class="text-[9px] xs:text-[11px] sm:text-xs text-white/90 font-medium truncate">
                                    {{ teacher.designation || '—' }}
                                </p>
                            </div>
                        </div>

                        <!-- Content — flex-1 to fill remaining space -->
                        <div class="p-2.5 xs:p-3 sm:p-4 space-y-1.5 xs:space-y-2 flex-1 flex flex-col">
                            <div v-if="teacher.department" class="flex items-center gap-1 xs:gap-1.5 text-[10px] xs:text-xs text-slate-500 dark:text-slate-400">
                                <svg class="h-3 w-3 xs:h-3.5 xs:w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                                <span class="truncate">{{ teacher.department }}</span>
                            </div>

                            <div v-if="teacher.qualification" class="flex items-center gap-1 xs:gap-1.5 text-[10px] xs:text-xs text-slate-500 dark:text-slate-400">
                                <svg class="h-3 w-3 xs:h-3.5 xs:w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                </svg>
                                <span class="truncate">{{ teacher.qualification }}</span>
                            </div>

                            <!-- Actions — mt-auto pushes to bottom -->
                            <div class="flex items-center gap-1.5 pt-1.5 xs:pt-2 border-t border-slate-100 mt-auto dark:border-slate-800">
                                <button
                                    type="button"
                                    @click="openEditModal(teacher)"
                                    class="flex-1 inline-flex items-center justify-center gap-1 xs:gap-1.5 rounded-lg bg-gradient-to-r from-indigo-500 to-purple-600 px-2 xs:px-3 py-1.5 xs:py-2 text-[10px] xs:text-xs font-bold text-white shadow-sm transition hover:from-indigo-600 hover:to-purple-700 active:scale-95"
                                >
                                    <svg class="h-3 w-3 xs:h-3.5 xs:w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit
                                </button>

                                <button
                                    type="button"
                                    @click="deleteTeacher(teacher)"
                                    class="inline-flex h-7 w-7 xs:h-8 xs:w-8 items-center justify-center rounded-lg border xs:border-2 border-rose-200 bg-white text-rose-600 transition hover:bg-rose-50 hover:border-rose-300 active:scale-95 flex-shrink-0 dark:border-rose-800 dark:bg-slate-800 dark:text-rose-400 dark:hover:bg-rose-950/40 dark:hover:border-rose-700"
                                >
                                    <svg class="h-3 w-3 xs:h-3.5 xs:w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- MODAL -->
                <!-- ============================================================ -->
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModal"
                        class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-0 xs:p-2 sm:p-6 bg-slate-900/60 backdrop-blur-sm overflow-y-auto"
                        @click.self="closeModal"
                    >
                        <Transition
                            enter-active-class="transition duration-200 ease-out"
                            enter-from-class="opacity-0 translate-y-4 sm:scale-95"
                            enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                            leave-active-class="transition duration-150 ease-in"
                            leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                            leave-to-class="opacity-0 translate-y-4 sm:scale-95"
                        >
                            <div
                                v-if="showModal"
                                class="relative w-full xs:max-w-[95%] sm:max-w-2xl md:max-w-3xl my-0 xs:my-4 sm:my-8 rounded-none xs:rounded-2xl sm:rounded-3xl bg-white shadow-2xl overflow-hidden dark:bg-slate-900"
                                @click.stop
                            >
                                <!-- Modal header -->
                                <div class="sticky top-0 z-10 relative overflow-hidden bg-gradient-to-r from-indigo-600 via-indigo-700 to-purple-700 px-3 xs:px-4 sm:px-5 md:px-6 py-3 xs:py-4 sm:py-5">
                                    <div class="absolute inset-0 opacity-10">
                                        <div class="absolute top-0 right-0 w-48 xs:w-64 h-48 xs:h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    </div>
                                    <div class="relative flex items-center justify-between gap-2 xs:gap-3">
                                        <div class="flex items-center gap-2 xs:gap-3 min-w-0">
                                            <div class="flex h-8 w-8 xs:h-9 xs:w-9 sm:h-10 sm:w-10 items-center justify-center rounded-lg xs:rounded-xl bg-white/20 backdrop-blur-sm flex-shrink-0">
                                                <svg class="h-4 w-4 xs:h-4.5 xs:w-4.5 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <h3 class="text-sm xs:text-base sm:text-lg font-bold text-white truncate">
                                                    {{ editingTeacher ? 'Edit Teacher' : 'Add Teacher' }}
                                                </h3>
                                                <p class="text-[10px] xs:text-[11px] sm:text-xs text-indigo-100/90 truncate">
                                                    {{ editingTeacher ? 'Update teacher details' : 'Add a new teacher to your team' }}
                                                </p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            @click="closeModal"
                                            class="flex-shrink-0 rounded-lg xs:rounded-xl p-1.5 xs:p-2 text-white/80 hover:bg-white/20 transition"
                                        >
                                            <svg class="h-4 w-4 xs:h-5 xs:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Modal body -->
                                <form @submit.prevent="submit" class="p-3 xs:p-4 sm:p-6 space-y-3 xs:space-y-4 sm:space-y-5 max-h-[calc(100vh-6rem)] xs:max-h-[80vh] overflow-y-auto">
                                    <div class="grid grid-cols-1 gap-3 xs:gap-4 sm:gap-5 sm:grid-cols-2">

                                        <!-- Name -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Name <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.name"
                                                type="text"
                                                placeholder="e.g. Md. Rahim Uddin"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                            <p v-if="form.errors.name" class="mt-1 text-[10px] xs:text-xs font-medium text-rose-500">{{ form.errors.name }}</p>
                                        </div>

                                        <!-- Photo -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-1.5 xs:mb-2 flex flex-wrap items-center gap-1.5 xs:gap-2 text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Photo
                                                <span class="text-[9px] xs:text-[10px] font-bold text-emerald-600 bg-emerald-50 px-1.5 xs:px-2 py-0.5 rounded-full dark:text-emerald-300 dark:bg-emerald-900/40">Auto-compressed</span>
                                            </label>
                                            <div
                                                @drop="onDrop"
                                                @dragover="onDragOver"
                                                @dragleave="onDragLeave"
                                                @click="openFilePicker"
                                                :class="[
                                                    'group relative cursor-pointer overflow-hidden rounded-xl xs:rounded-2xl border-2 border-dashed transition-all duration-300',
                                                    isDragging
                                                        ? 'border-indigo-500 bg-gradient-to-br from-indigo-50 to-purple-50 scale-[1.01] shadow-xl shadow-indigo-200/50 dark:from-indigo-950/40 dark:to-purple-950/40 dark:shadow-indigo-900/30'
                                                        : 'border-slate-200 bg-slate-50/50 hover:border-indigo-400 hover:bg-indigo-50/40 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-indigo-600 dark:hover:bg-indigo-950/20',
                                                ]"
                                            >
                                                <template v-if="photoPreview || (editingTeacher && editingTeacher.photo)">
                                                    <div class="flex items-center gap-2 xs:gap-3 p-2.5 xs:p-3 sm:p-4">
                                                        <div class="flex h-14 w-14 xs:h-16 xs:w-16 sm:h-20 sm:w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg xs:rounded-xl bg-white shadow-md ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                                                            <img
                                                                :src="photoPreview || `/storage/${editingTeacher.photo}`"
                                                                class="h-full w-full object-cover"
                                                            />
                                                        </div>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="text-[11px] xs:text-xs sm:text-sm font-bold text-slate-700 truncate dark:text-slate-200">
                                                                {{ form.photo ? 'New photo selected' : 'Current photo' }}
                                                            </p>
                                                            <p class="text-[9px] xs:text-[10px] sm:text-xs text-slate-500 truncate dark:text-slate-400">
                                                                Click to replace
                                                            </p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            @click.stop="removePhoto"
                                                            class="inline-flex h-8 w-8 xs:h-9 xs:w-9 items-center justify-center rounded-lg xs:rounded-xl bg-rose-500/90 backdrop-blur-sm text-white shadow-lg transition hover:bg-rose-600 active:scale-95 flex-shrink-0"
                                                        >
                                                            <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </template>

                                                <template v-else>
                                                    <div class="flex flex-col items-center justify-center gap-1.5 xs:gap-2 px-3 xs:px-4 py-6 xs:py-8 text-center">
                                                        <div class="flex h-12 w-12 xs:h-14 xs:w-14 sm:h-16 sm:w-16 items-center justify-center rounded-xl xs:rounded-2xl bg-gradient-to-br from-indigo-100 to-purple-100 group-hover:from-indigo-200 group-hover:to-purple-200 transition dark:from-indigo-900/40 dark:to-purple-900/40 dark:group-hover:from-indigo-900/60 dark:group-hover:to-purple-900/60">
                                                            <svg class="h-6 w-6 xs:h-7 xs:w-7 sm:h-8 sm:w-8 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                                            </svg>
                                                        </div>
                                                        <p class="text-[11px] xs:text-xs sm:text-sm font-bold text-slate-700 dark:text-slate-200">Upload teacher photo</p>
                                                        <p class="text-[9px] xs:text-[10px] text-slate-500 dark:text-slate-400">Drag & drop or click · Any size</p>
                                                        <div class="flex flex-wrap items-center justify-center gap-1 xs:gap-1.5 mt-0.5 xs:mt-1">
                                                            <span class="rounded-full bg-indigo-100 px-1.5 xs:px-2 py-0.5 text-[9px] xs:text-[10px] font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">JPG</span>
                                                            <span class="rounded-full bg-indigo-100 px-1.5 xs:px-2 py-0.5 text-[9px] xs:text-[10px] font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">PNG</span>
                                                            <span class="rounded-full bg-indigo-100 px-1.5 xs:px-2 py-0.5 text-[9px] xs:text-[10px] font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">WEBP</span>
                                                            <span class="rounded-full bg-emerald-100 px-1.5 xs:px-2 py-0.5 text-[9px] xs:text-[10px] font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">✓ Auto-compress</span>
                                                        </div>
                                                    </div>
                                                </template>

                                                <input
                                                    ref="fileInputRef"
                                                    type="file"
                                                    accept="image/*"
                                                    class="hidden"
                                                    @change="onPhotoChange"
                                                />
                                            </div>
                                            <p v-if="form.errors.photo" class="mt-1 text-[10px] xs:text-xs font-medium text-rose-500">{{ form.errors.photo }}</p>
                                        </div>

                                        <!-- Designation -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Designation</label>
                                            <input
                                                v-model="form.designation"
                                                type="text"
                                                placeholder="e.g. Senior Teacher"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                        </div>

                                        <!-- Department -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Department</label>
                                            <input
                                                v-model="form.department"
                                                type="text"
                                                placeholder="e.g. Mathematics"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                        </div>

                                        <!-- Qualification -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Qualification</label>
                                            <input
                                                v-model="form.qualification"
                                                type="text"
                                                placeholder="e.g. M.Sc in Math"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                        </div>

                                        <!-- Experience -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Experience</label>
                                            <input
                                                v-model="form.experience"
                                                type="text"
                                                placeholder="e.g. 10 years"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                        </div>

                                        <!-- Phone -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Phone</label>
                                            <input
                                                v-model="form.phone"
                                                type="tel"
                                                placeholder="+880 1XXX-XXXXXX"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                        </div>

                                        <!-- Email -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Email</label>
                                            <input
                                                v-model="form.email"
                                                type="email"
                                                placeholder="teacher@school.com"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                        </div>

                                        <!-- Order -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 flex flex-wrap items-center gap-1.5 xs:gap-2 text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Display Order
                                                <span class="text-[9px] xs:text-[10px] font-bold text-indigo-600 bg-indigo-50 px-1.5 xs:px-2 py-0.5 rounded-full dark:text-indigo-300 dark:bg-indigo-900/40">Lower = First</span>
                                            </label>
                                            <input
                                                v-model.number="form.order"
                                                type="number"
                                                min="0"
                                                max="9999"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            />
                                            <p class="mt-1 text-[9px] xs:text-[10px] text-slate-500 dark:text-slate-400">
                                                Example: 1 er agé dekhabe, tarpor 2, 3...
                                            </p>
                                        </div>

                                        <!-- Status -->
                                        <div>
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Status</label>
                                            <select
                                                v-model="form.status"
                                                class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 py-2 xs:py-2.5 sm:py-3 px-3 xs:px-4 text-xs xs:text-sm text-slate-700 shadow-sm transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 cursor-pointer dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                            >
                                                <option value="active">🟢 Active</option>
                                                <option value="inactive">⚫ Inactive</option>
                                            </select>
                                        </div>

                                        <!-- Display on Website -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Display on Website</label>
                                            <label class="flex items-center gap-2 xs:gap-3 cursor-pointer select-none rounded-lg xs:rounded-xl border border-slate-200 xs:border-2 bg-white/70 px-3 xs:px-4 py-2 xs:py-2.5 sm:py-3 transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-800/70 dark:hover:border-indigo-700">
                                                <span
                                                    @click.prevent="form.display_on_website = !form.display_on_website"
                                                    :class="[
                                                        'relative inline-flex h-5 w-9 xs:h-6 xs:w-11 items-center rounded-full transition-colors duration-300 flex-shrink-0',
                                                        form.display_on_website ? 'bg-gradient-to-r from-emerald-500 to-teal-600' : 'bg-slate-300 dark:bg-slate-600'
                                                    ]"
                                                >
                                                    <span
                                                        :class="[
                                                            'inline-block h-3.5 w-3.5 xs:h-4 xs:w-4 transform rounded-full bg-white shadow-md transition-transform duration-300',
                                                            form.display_on_website ? 'translate-x-4 xs:translate-x-6' : 'translate-x-0.5 xs:translate-x-1'
                                                        ]"
                                                    ></span>
                                                </span>
                                                <span class="text-[10px] xs:text-xs sm:text-sm font-medium text-slate-600 break-words dark:text-slate-300">
                                                    {{ form.display_on_website ? '🌐 Will show on public website' : '🚫 Hidden from public website' }}
                                                </span>
                                            </label>
                                        </div>

                                        <!-- Bio -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-1.5 xs:mb-2 block text-[11px] xs:text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Bio</label>
                                            <div class="relative">
                                                <textarea
                                                    v-model="form.bio"
                                                    rows="3"
                                                    placeholder="Short bio about this teacher..."
                                                    class="w-full rounded-lg xs:rounded-xl border border-slate-200 bg-white/70 px-3 xs:px-4 py-2 xs:py-2.5 sm:py-3 text-xs xs:text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-indigo-500 dark:focus:bg-slate-800 dark:focus:ring-indigo-900/40"
                                                ></textarea>
                                                <div class="absolute bottom-2 xs:bottom-3 right-2 xs:right-3 text-[9px] xs:text-[10px] font-medium text-slate-400 bg-white/70 px-1 xs:px-1.5 py-0.5 rounded dark:text-slate-500 dark:bg-slate-800/70">
                                                    {{ form.bio?.length || 0 }} chars
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Actions — sticky bottom on mobile -->
                                    <div class="sticky bottom-0 -mx-3 xs:-mx-4 sm:mx-0 -mb-3 xs:-mb-4 sm:mb-0 px-3 xs:px-4 sm:px-0 py-3 xs:py-3 sm:py-0 bg-white/95 backdrop-blur-md sm:bg-transparent border-t border-slate-100 sm:border-0 sm:pt-2 flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 dark:bg-slate-900/95 dark:sm:bg-transparent dark:border-slate-800">
                                        <button
                                            type="button"
                                            @click="closeModal"
                                            class="rounded-lg xs:rounded-xl border border-slate-200 bg-white px-4 xs:px-6 py-2 xs:py-2.5 text-xs xs:text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 text-center dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="form.processing"
                                            class="inline-flex items-center justify-center gap-1.5 xs:gap-2 rounded-lg xs:rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 px-5 xs:px-7 py-2 xs:py-2.5 text-xs xs:text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:from-indigo-700 hover:to-purple-700 disabled:cursor-not-allowed disabled:opacity-60 active:scale-95"
                                        >
                                            <svg v-if="form.processing" class="h-3.5 w-3.5 xs:h-4 xs:w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg v-else class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            {{ form.processing ? 'Saving...' : (editingTeacher ? 'Update' : 'Add Teacher') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </Transition>
                    </div>
                </Transition>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* Custom scrollbar for mobile filter scroll */
.scrollbar-thin::-webkit-scrollbar {
    height: 4px;
}
.scrollbar-thin::-webkit-scrollbar-track {
    background: transparent;
}
.scrollbar-thin::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 9999px;
}
.scrollbar-thin::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Dark mode scrollbar */
:global(.dark) .scrollbar-thin::-webkit-scrollbar-thumb {
    background: #475569;
}
:global(.dark) .scrollbar-thin::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}
</style>
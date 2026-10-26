<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, router, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    notices: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const page = usePage()

// ============================================================
// STATE
// ============================================================
const showModal = ref(false)
const editingNotice = ref(null)
const search = ref(props.filters?.search ?? '')
const statusFilter = ref(props.filters?.status ?? 'all')

// ============================================================
// FORM
// ============================================================
const form = useForm({
    title:        '',
    description:  '',
    notice_date:  '',
    publish_date: '',
    attachment:   null,
    featured:     false,
    status:       'published',
})

// ============================================================
// INLINE TOAST
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
// OPEN MODAL — Create
// ============================================================
const openCreateModal = () => {
    editingNotice.value = null
    form.reset()
    form.clearErrors()
    form.featured = false
    form.status = 'published'
    attachmentPreview.value = null
    attachmentName.value = ''
    showModal.value = true
}

// ============================================================
// OPEN MODAL — Edit
// ============================================================
const openEditModal = (notice) => {
    editingNotice.value = notice
    form.clearErrors()
    form.title        = notice.title ?? ''
    form.description  = notice.description ?? ''
    form.notice_date  = notice.notice_date ?? ''
    form.publish_date = notice.publish_date ?? ''
    form.featured     = Boolean(notice.featured)
    form.status       = notice.status ?? 'published'
    form.attachment   = null
    attachmentPreview.value = null
    attachmentName.value = notice.attachment ? notice.attachment.split('/').pop() : ''
    showModal.value = true
}

// ============================================================
// CLOSE MODAL
// ============================================================
const closeModal = () => {
    showModal.value = false
    editingNotice.value = null
    form.reset()
    form.clearErrors()
    attachmentPreview.value = null
    attachmentName.value = ''
    if (fileInputRef.value) fileInputRef.value.value = ''
}

// ============================================================
// ATTACHMENT HANDLING
// ============================================================
const attachmentPreview = ref(null)
const attachmentName = ref('')
const isDragging = ref(false)
const fileInputRef = ref(null)

const handleFile = (file) => {
    if (!file) return
    form.attachment = file
    attachmentName.value = file.name
    if (file.type.startsWith('image/')) {
        attachmentPreview.value = URL.createObjectURL(file)
    } else {
        attachmentPreview.value = null
    }
}

const onFileChange = (e) => handleFile(e.target.files?.[0])

const onDrop = (e) => {
    e.preventDefault()
    isDragging.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file) handleFile(file)
}

const onDragOver = (e) => { e.preventDefault(); isDragging.value = true }
const onDragLeave = () => { isDragging.value = false }
const openFilePicker = () => fileInputRef.value?.click()

const removeAttachment = () => {
    form.attachment = null
    attachmentPreview.value = null
    attachmentName.value = ''
    if (fileInputRef.value) fileInputRef.value.value = ''
}

const isPdf = (name) => name?.toLowerCase()?.endsWith('.pdf')

// ============================================================
// SUBMIT
// ============================================================
const submit = () => {
    if (editingNotice.value) {
        form.post(route('website-management.notices.update', editingNotice.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (resp) => {
                showToast(resp.props.flash?.success || 'Notice updated successfully! ✅', 'success')
                closeModal()
            },
            onError: (errors) => {
                const first = Object.values(errors)[0] || 'Please fix the errors and try again.'
                showToast(first, 'error')
            },
        })
    } else {
        form.post(route('website-management.notices.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (resp) => {
                showToast(resp.props.flash?.success || 'Notice published successfully! ✅', 'success')
                closeModal()
            },
            onError: (errors) => {
                const first = Object.values(errors)[0] || 'Please fix the errors and try again.'
                showToast(first, 'error')
            },
        })
    }
}

// ============================================================
// DELETE
// ============================================================
const deleteNotice = (notice) => {
    if (!confirm(`Delete notice "${notice.title}"? This cannot be undone.`)) return

    router.delete(route('website-management.notices.destroy', notice.id), {
        preserveScroll: true,
        onSuccess: (resp) => {
            showToast(resp.props.flash?.success || 'Notice deleted successfully! ✅', 'success')
        },
        onError: () => {
            showToast('Failed to delete notice.', 'error')
        },
    })
}

// ============================================================
// TOGGLE FEATURED
// ============================================================
const toggleFeatured = (notice) => {
    router.post(route('website-management.notices.toggle-featured', notice.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (resp) => {
            showToast(resp.props.flash?.success || 'Featured status updated.', 'info')
        },
    })
}

// ============================================================
// SEARCH + FILTER
// ============================================================
let searchTimeout = null
watch(search, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        router.get(route('website-management.notices'), {
            search: search.value,
            status: statusFilter.value,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        })
    }, 400)
})

watch(statusFilter, () => {
    router.get(route('website-management.notices'), {
        search: search.value,
        status: statusFilter.value,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
})

// ============================================================
// HELPERS
// ============================================================
const formatDate = (dateStr) => {
    if (!dateStr) return '—'
    try {
        const d = new Date(dateStr)
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    } catch { return dateStr }
}

const statusMeta = (status) => {
    const map = {
        published: { label: 'Published', cls: 'bg-emerald-100 text-emerald-700 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700', dot: 'bg-emerald-500' },
        draft:     { label: 'Draft',     cls: 'bg-amber-100 text-amber-700 border-amber-300 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-700',       dot: 'bg-amber-500' },
    }
    return map[status] ?? map.published
}
</script>

<template>
    <Head title="Notice Management" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-amber-50/40 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-amber-200/30 blur-3xl dark:bg-amber-800/20"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-orange-200/30 blur-3xl dark:bg-orange-800/20"></div>
            </div>

            <div class="relative w-full">

                <!-- INLINE TOAST -->
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
                            'mb-4 sm:mb-6 flex items-start gap-3 rounded-2xl border-2 p-3 sm:p-4 shadow-lg backdrop-blur-sm',
                            toast.type === 'success' ? 'bg-emerald-50/90 border-emerald-300 text-emerald-800 dark:bg-emerald-950/80 dark:border-emerald-700 dark:text-emerald-200' : '',
                            toast.type === 'error'   ? 'bg-rose-50/90 border-rose-300 text-rose-800 dark:bg-rose-950/80 dark:border-rose-700 dark:text-rose-200' : '',
                            toast.type === 'info'    ? 'bg-blue-50/90 border-blue-300 text-blue-800 dark:bg-blue-950/80 dark:border-blue-700 dark:text-blue-200' : '',
                        ]"
                    >
                        <span class="text-xl flex-shrink-0 leading-none mt-0.5">
                            {{ toast.type === 'success' ? '✅' : toast.type === 'error' ? '❌' : 'ℹ️' }}
                        </span>
                        <p class="text-xs sm:text-sm font-semibold flex-1 break-words leading-relaxed">
                            {{ toast.message }}
                        </p>
                        <button
                            type="button"
                            @click="toast.show = false"
                            class="flex-shrink-0 rounded-lg p-1 transition text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </Transition>

                <!-- PAGE HEADER -->
                <div class="mb-5 sm:mb-6 lg:mb-8">
                    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-3 sm:gap-4 w-full lg:w-auto">
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-amber-500 to-orange-600 rounded-2xl shadow-lg shadow-amber-500/20 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent truncate dark:from-slate-100 dark:to-slate-400">
                                    Notice Management
                                </h1>
                                <p class="text-xs sm:text-sm text-slate-500 flex items-center gap-2 mt-1 dark:text-slate-400">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                    Publish notices, exam schedules, and announcements
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3 w-full lg:w-auto">
                            <Link
                                :href="route('website-management.settings')"
                                class="group inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white/80 backdrop-blur-sm px-5 py-2.5 text-sm font-medium text-slate-700 transition-all duration-300 hover:bg-white hover:border-slate-300 hover:shadow-lg dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:border-slate-600"
                            >
                                <svg class="w-4 h-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                Back
                            </Link>
                            <button
                                type="button"
                                @click="openCreateModal"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-300 hover:from-amber-600 hover:to-orange-700 hover:shadow-lg hover:shadow-amber-500/30 active:scale-95"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Create Notice
                            </button>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="mb-5 sm:mb-6 rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-3 sm:p-4 dark:bg-slate-900/80 dark:border-slate-800">
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                        <div class="relative flex-1">
                            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search notices..."
                                class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 pl-10 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40"
                            />
                        </div>

                        <div class="flex items-center gap-2 overflow-x-auto">
                            <button
                                v-for="opt in [
                                    { val: 'all', label: 'All' },
                                    { val: 'published', label: 'Published' },
                                    { val: 'draft', label: 'Draft' },
                                ]"
                                :key="opt.val"
                                type="button"
                                @click="statusFilter = opt.val"
                                :class="[
                                    'px-3 py-2 text-xs font-semibold rounded-xl transition whitespace-nowrap',
                                    statusFilter === opt.val
                                        ? 'bg-gradient-to-r from-amber-500 to-orange-600 text-white shadow-md shadow-amber-500/30'
                                        : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700 dark:hover:border-slate-600'
                                ]"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- EMPTY STATE -->
                <div v-if="notices.length === 0" class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-8 sm:p-16 text-center dark:bg-slate-900/80 dark:border-slate-800">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-amber-100 to-orange-100 mb-4 dark:from-amber-900/40 dark:to-orange-900/40">
                        <svg class="h-10 w-10 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">No notices yet</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Publish your first notice to keep everyone informed.</p>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-amber-500/30 transition hover:from-amber-600 hover:to-orange-700 active:scale-95"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Create Your First Notice
                    </button>
                </div>

                <!-- NOTICES GRID -->
                <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
                    <div
                        v-for="notice in notices"
                        :key="notice.id"
                        :class="[
                            'group relative overflow-hidden rounded-2xl bg-white/80 backdrop-blur-sm border-2 shadow-sm hover:shadow-xl transition-all duration-300 dark:bg-slate-900/80',
                            notice.featured
                                ? 'border-amber-300 ring-2 ring-amber-200/50 dark:border-amber-700 dark:ring-amber-900/40'
                                : 'border-slate-100 hover:border-amber-200 dark:border-slate-800 dark:hover:border-amber-800'
                        ]"
                    >
                        <!-- Featured ribbon -->
                        <div v-if="notice.featured" class="absolute top-0 right-0 z-10">
                            <div class="flex items-center gap-1 rounded-bl-2xl bg-gradient-to-r from-amber-500 to-orange-600 px-3 py-1.5 shadow-md">
                                <svg class="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                </svg>
                                <span class="text-[10px] font-bold text-white uppercase tracking-wider">Featured</span>
                            </div>
                        </div>

                        <div class="p-4 sm:p-5">
                            <!-- Status + Date row -->
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                <span :class="[
                                    'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border-2',
                                    statusMeta(notice.status).cls
                                ]">
                                    <span :class="['h-1.5 w-1.5 rounded-full', statusMeta(notice.status).dot]"></span>
                                    {{ statusMeta(notice.status).label }}
                                </span>

                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-full dark:text-slate-400 dark:bg-slate-800">
                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    {{ formatDate(notice.notice_date) }}
                                </span>
                            </div>

                            <!-- Title -->
                            <h3 class="text-base sm:text-lg font-bold text-slate-800 leading-snug line-clamp-2 min-h-[3rem] dark:text-slate-100">
                                {{ notice.title }}
                            </h3>

                            <!-- Description -->
                            <p v-if="notice.description" class="mt-2 text-xs text-slate-500 line-clamp-3 dark:text-slate-400">
                                {{ notice.description }}
                            </p>

                            <!-- Attachment -->
                            <div v-if="notice.attachment" class="mt-3">
                                <a
                                    :href="`/storage/${notice.attachment}`"
                                    target="_blank"
                                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition max-w-full dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    <span v-if="isPdf(notice.attachment)" class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100 text-rose-600 flex-shrink-0 dark:bg-rose-900/40 dark:text-rose-300">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                        </svg>
                                    </span>
                                    <span v-else class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-100 text-blue-600 flex-shrink-0 dark:bg-blue-900/40 dark:text-blue-300">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </span>
                                    <span class="truncate">
                                        {{ notice.attachment.split('/').pop() }}
                                    </span>
                                    <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                </a>
                            </div>

                            <!-- Publish date -->
                            <div v-if="notice.publish_date" class="mt-3 flex items-center gap-1.5 text-[10px] font-semibold text-slate-400 dark:text-slate-500">
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Published: {{ formatDate(notice.publish_date) }}
                            </div>

                            <!-- Actions -->
                            <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    @click="openEditModal(notice)"
                                    class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:from-amber-600 hover:to-orange-700 active:scale-95"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit
                                </button>

                                <button
                                    type="button"
                                    @click="toggleFeatured(notice)"
                                    :title="notice.featured ? 'Remove from featured' : 'Mark as featured'"
                                    :class="[
                                        'inline-flex h-9 w-9 items-center justify-center rounded-xl border-2 transition',
                                        notice.featured
                                            ? 'border-amber-300 bg-amber-50 text-amber-600 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/40 dark:text-amber-300 dark:hover:bg-amber-900/60'
                                            : 'border-slate-200 bg-white text-slate-400 hover:border-amber-300 hover:text-amber-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-500 dark:hover:border-amber-700 dark:hover:text-amber-400'
                                    ]"
                                >
                                    <svg class="h-4 w-4" :fill="notice.featured ? 'currentColor' : 'none'" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                    </svg>
                                </button>

                                <button
                                    type="button"
                                    @click="deleteNotice(notice)"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-xl border-2 border-rose-200 bg-white text-rose-600 transition hover:bg-rose-50 hover:border-rose-300 active:scale-95 dark:border-rose-800 dark:bg-slate-800 dark:text-rose-400 dark:hover:bg-rose-950/40 dark:hover:border-rose-700"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================= -->
                <!-- CREATE / EDIT MODAL -->
                <!-- ============================================= -->
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
                        class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-3 sm:p-6 bg-slate-900/60 backdrop-blur-sm overflow-y-auto"
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
                                class="relative w-full max-w-3xl my-8 rounded-3xl bg-white shadow-2xl overflow-hidden dark:bg-slate-900"
                                @click.stop
                            >
                                <!-- Header -->
                                <div class="relative overflow-hidden bg-gradient-to-r from-amber-500 via-orange-600 to-red-600 px-5 py-4 sm:px-6 sm:py-5">
                                    <div class="absolute inset-0 opacity-10">
                                        <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    </div>
                                    <div class="relative flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm flex-shrink-0">
                                                <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <h3 class="text-base sm:text-lg font-bold text-white truncate">
                                                    {{ editingNotice ? 'Edit Notice' : 'Create Notice' }}
                                                </h3>
                                                <p class="text-[11px] sm:text-xs text-amber-100/90">
                                                    {{ editingNotice ? 'Update notice details' : 'Publish a new notice' }}
                                                </p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            @click="closeModal"
                                            class="flex-shrink-0 rounded-xl p-2 text-white/80 hover:bg-white/20 transition"
                                        >
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Body -->
                                <form @submit.prevent="submit" class="p-4 sm:p-6 space-y-4 sm:space-y-5">
                                    <div class="grid grid-cols-1 gap-4 sm:gap-5 sm:grid-cols-2">

                                        <!-- Title -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Notice Title <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.title"
                                                type="text"
                                                placeholder="e.g. Annual Examination 2026"
                                                class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 px-4 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40"
                                            />
                                            <p v-if="form.errors.title" class="mt-1.5 text-xs font-medium text-rose-500">
                                                {{ form.errors.title }}
                                            </p>
                                        </div>

                                        <!-- Description -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Description
                                            </label>
                                            <div class="relative">
                                                <textarea
                                                    v-model="form.description"
                                                    rows="4"
                                                    placeholder="Write the notice details..."
                                                    class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40"
                                                ></textarea>
                                                <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 bg-white/70 px-1.5 py-0.5 rounded dark:text-slate-500 dark:bg-slate-800/70">
                                                    {{ form.description?.length || 0 }} chars
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Notice Date -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Notice Date <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.notice_date"
                                                type="date"
                                                class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 px-4 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40 dark:[color-scheme:dark]"
                                            />
                                            <p v-if="form.errors.notice_date" class="mt-1.5 text-xs font-medium text-rose-500">
                                                {{ form.errors.notice_date }}
                                            </p>
                                        </div>

                                        <!-- Publish Date -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Publish Date
                                            </label>
                                            <input
                                                v-model="form.publish_date"
                                                type="date"
                                                class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 px-4 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40 dark:[color-scheme:dark]"
                                            />
                                        </div>

                                        <!-- Status -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Status
                                            </label>
                                            <select
                                                v-model="form.status"
                                                class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 sm:py-3 px-4 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100 cursor-pointer dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:focus:border-amber-500 dark:focus:bg-slate-800 dark:focus:ring-amber-900/40"
                                            >
                                                <option value="published">🟢 Published</option>
                                                <option value="draft">🟡 Draft</option>
                                            </select>
                                        </div>

                                        <!-- Featured Toggle -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Featured
                                            </label>
                                            <label class="flex items-center gap-3 cursor-pointer select-none rounded-xl border-2 border-slate-200 bg-white/70 px-4 py-2.5 sm:py-3 transition hover:border-amber-300 dark:border-slate-700 dark:bg-slate-800/70 dark:hover:border-amber-700">
                                                <span
                                                    @click.prevent="form.featured = !form.featured"
                                                    :class="[
                                                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-300',
                                                        form.featured ? 'bg-gradient-to-r from-amber-500 to-orange-600' : 'bg-slate-300 dark:bg-slate-600'
                                                    ]"
                                                >
                                                    <span
                                                        :class="[
                                                            'inline-block h-4 w-4 transform rounded-full bg-white shadow-md transition-transform duration-300',
                                                            form.featured ? 'translate-x-6' : 'translate-x-1'
                                                        ]"
                                                    ></span>
                                                </span>
                                                <span class="text-xs sm:text-sm font-medium text-slate-600 dark:text-slate-300">
                                                    {{ form.featured ? 'Marked as Featured' : 'Not Featured' }}
                                                </span>
                                            </label>
                                        </div>

                                        <!-- Attachment -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Attachment / PDF
                                            </label>
                                            <div
                                                @drop="onDrop"
                                                @dragover="onDragOver"
                                                @dragleave="onDragLeave"
                                                @click="openFilePicker"
                                                :class="[
                                                    'group relative cursor-pointer overflow-hidden rounded-2xl border-2 border-dashed transition-all duration-300',
                                                    isDragging
                                                        ? 'border-amber-500 bg-gradient-to-br from-amber-50 to-orange-50 scale-[1.01] shadow-xl shadow-amber-200/50 dark:from-amber-950/40 dark:to-orange-950/40 dark:shadow-amber-900/30'
                                                        : 'border-slate-200 bg-slate-50/50 hover:border-amber-400 hover:bg-amber-50/40 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-amber-700 dark:hover:bg-amber-950/20',
                                                ]"
                                            >
                                                <template v-if="form.attachment || (editingNotice && editingNotice.attachment)">
                                                    <div class="flex items-center gap-3 p-3 sm:p-4">
                                                        <div class="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-xl flex-shrink-0 shadow-sm" :class="attachmentPreview ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300' : (isPdf(attachmentName || editingNotice?.attachment) ? 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-300' : 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300')">
                                                            <img v-if="attachmentPreview" :src="attachmentPreview" class="h-full w-full object-cover rounded-xl" />
                                                            <svg v-else-if="isPdf(attachmentName || editingNotice?.attachment)" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                            </svg>
                                                            <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                            </svg>
                                                        </div>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="text-xs sm:text-sm font-bold text-slate-700 truncate dark:text-slate-200">
                                                                {{ form.attachment ? 'New file selected' : 'Current attachment' }}
                                                            </p>
                                                            <p class="text-[10px] sm:text-xs text-slate-500 truncate dark:text-slate-400">
                                                                {{ attachmentName || editingNotice?.attachment?.split('/').pop() }}
                                                            </p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            @click.stop="removeAttachment"
                                                            class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-rose-500/90 backdrop-blur-sm text-white shadow-lg transition hover:bg-rose-600 active:scale-95 flex-shrink-0"
                                                        >
                                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </template>

                                                <template v-else>
                                                    <div class="flex flex-col items-center justify-center gap-2 px-4 py-8 text-center">
                                                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-100 to-orange-100 group-hover:from-amber-200 group-hover:to-orange-200 transition dark:from-amber-900/40 dark:to-orange-900/40 dark:group-hover:from-amber-900/60 dark:group-hover:to-orange-900/60">
                                                            <svg class="h-7 w-7 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                                            </svg>
                                                        </div>
                                                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Upload attachment</p>
                                                        <p class="text-[10px] text-slate-500 dark:text-slate-400">PDF, JPG, PNG — drag & drop or click</p>
                                                        <div class="flex flex-wrap items-center justify-center gap-1.5 mt-1">
                                                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">PDF</span>
                                                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">JPG</span>
                                                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">PNG</span>
                                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">✓ Max 20MB</span>
                                                        </div>
                                                    </div>
                                                </template>

                                                <input
                                                    ref="fileInputRef"
                                                    type="file"
                                                    accept=".pdf,image/*"
                                                    class="hidden"
                                                    @change="onFileChange"
                                                />
                                            </div>
                                            <p v-if="form.errors.attachment" class="mt-1.5 text-xs font-medium text-rose-500">
                                                {{ form.errors.attachment }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                                        <button
                                            type="button"
                                            @click="closeModal"
                                            class="rounded-xl border border-slate-200 bg-white px-6 py-2.5 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 text-center dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="form.processing"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-amber-500/30 transition hover:from-amber-600 hover:to-orange-700 disabled:cursor-not-allowed disabled:opacity-60 active:scale-95"
                                        >
                                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            {{ form.processing ? 'Saving...' : (editingNotice ? 'Update Notice' : 'Publish Notice') }}
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
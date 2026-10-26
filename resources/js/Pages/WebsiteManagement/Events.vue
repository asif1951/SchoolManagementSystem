<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, router, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    events: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const page = usePage()

// ============================================================
// STATE
// ============================================================
const showModal = ref(false)
const editingEvent = ref(null)
const search = ref(props.filters?.search ?? '')
const statusFilter = ref(props.filters?.status ?? 'all')

// ============================================================
// FORM
// ============================================================
const form = useForm({
    title:       '',
    event_date:  '',
    event_time:  '',
    location:    '',
    description: '',
    status:      'upcoming',
    event_image: null,
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
    editingEvent.value = null
    form.reset()
    form.clearErrors()
    form.status = 'upcoming'
    imagePreview.value = null
    showModal.value = true
}

// ============================================================
// OPEN MODAL — Edit
// ============================================================
const openEditModal = (event) => {
    editingEvent.value = event
    form.clearErrors()
    form.title       = event.title ?? ''
    form.event_date  = event.event_date ?? ''
    form.event_time  = event.event_time ?? ''
    form.location    = event.location ?? ''
    form.description = event.description ?? ''
    form.status      = event.status ?? 'upcoming'
    form.event_image = null
    imagePreview.value = null
    showModal.value = true
}

// ============================================================
// CLOSE MODAL
// ============================================================
const closeModal = () => {
    showModal.value = false
    editingEvent.value = null
    form.reset()
    form.clearErrors()
    imagePreview.value = null
    if (fileInputRef.value) fileInputRef.value.value = ''
}

// ============================================================
// IMAGE HANDLING
// ============================================================
const imagePreview = ref(null)
const isDragging = ref(false)
const fileInputRef = ref(null)

const handleFile = (file) => {
    if (!file) return
    form.event_image = file
    imagePreview.value = URL.createObjectURL(file)
}

const onImageChange = (e) => handleFile(e.target.files?.[0])

const onDrop = (e) => {
    e.preventDefault()
    isDragging.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file) handleFile(file)
}

const onDragOver = (e) => { e.preventDefault(); isDragging.value = true }
const onDragLeave = () => { isDragging.value = false }
const openFilePicker = () => fileInputRef.value?.click()

const removeImage = () => {
    form.event_image = null
    imagePreview.value = null
    if (fileInputRef.value) fileInputRef.value.value = ''
}

// ============================================================
// SUBMIT — Create or Update
// ============================================================
const submit = () => {
    if (editingEvent.value) {
        form.post(route('website-management.events.update', editingEvent.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (resp) => {
                showToast(resp.props.flash?.success || 'Event updated successfully! ✅', 'success')
                closeModal()
            },
            onError: (errors) => {
                const first = Object.values(errors)[0] || 'Please fix the errors and try again.'
                showToast(first, 'error')
            },
        })
    } else {
        form.post(route('website-management.events.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (resp) => {
                showToast(resp.props.flash?.success || 'Event created successfully! ✅', 'success')
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
const deleteEvent = (event) => {
    if (!confirm(`Are you sure you want to delete "${event.title}"?`)) return

    router.delete(route('website-management.events.destroy', event.id), {
        preserveScroll: true,
        onSuccess: (resp) => {
            showToast(resp.props.flash?.success || 'Event deleted successfully! ✅', 'success')
        },
        onError: () => {
            showToast('Failed to delete event.', 'error')
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
        router.get(route('website-management.events'), {
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
    router.get(route('website-management.events'), {
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
        return d.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        })
    } catch {
        return dateStr
    }
}

const formatTime = (timeStr) => {
    if (!timeStr) return '—'
    try {
        const [h, m] = timeStr.split(':')
        const hour = parseInt(h)
        const ampm = hour >= 12 ? 'PM' : 'AM'
        const h12 = hour % 12 || 12
        return `${h12}:${m} ${ampm}`
    } catch {
        return timeStr
    }
}

const statusMeta = (status) => {
    const map = {
        upcoming:  { label: 'Upcoming',  cls: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border-blue-300 dark:border-blue-700',    dot: 'bg-blue-500' },
        ongoing:   { label: 'Ongoing',   cls: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border-amber-300 dark:border-amber-700', dot: 'bg-amber-500' },
        completed: { label: 'Completed', cls: 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700', dot: 'bg-emerald-500' },
        cancelled: { label: 'Cancelled', cls: 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 border-rose-300 dark:border-rose-700',    dot: 'bg-rose-500' },
    }
    return map[status] ?? map.upcoming
}
</script>

<template>
    <Head title="Events Management" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-purple-50/40 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 transition-colors duration-300">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-purple-200/30 dark:bg-purple-800/10 blur-3xl"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-pink-200/30 dark:bg-pink-800/10 blur-3xl"></div>
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
                            toast.type === 'success' ? 'bg-emerald-50/90 dark:bg-emerald-900/40 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200' : '',
                            toast.type === 'error'   ? 'bg-rose-50/90 dark:bg-rose-900/40 border-rose-300 dark:border-rose-700 text-rose-800 dark:text-rose-200' : '',
                            toast.type === 'info'    ? 'bg-blue-50/90 dark:bg-blue-900/40 border-blue-300 dark:border-blue-700 text-blue-800 dark:text-blue-200' : '',
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
                            class="flex-shrink-0 rounded-lg p-1 transition text-slate-400 dark:text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 hover:text-slate-700 dark:hover:text-slate-200"
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
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-purple-600 to-pink-600 rounded-2xl shadow-lg shadow-purple-500/20 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 dark:from-slate-100 dark:to-slate-300 bg-clip-text text-transparent truncate">
                                    Events Management
                                </h1>
                                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 flex items-center gap-2 mt-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                                    Manage school events, sports days, ceremonies
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3 w-full lg:w-auto">
                            <Link
                                :href="route('website-management.settings')"
                                class="group inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm px-5 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 transition-all duration-300 hover:bg-white dark:hover:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-lg"
                            >
                                <svg class="w-4 h-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                Back
                            </Link>
                            <button
                                type="button"
                                @click="openCreateModal"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-300 hover:from-purple-700 hover:to-pink-700 hover:shadow-lg hover:shadow-purple-500/30 active:scale-95"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Create Event
                            </button>
                        </div>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="mb-5 sm:mb-6 rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm border border-slate-100 dark:border-slate-800 shadow-sm p-3 sm:p-4 transition-colors duration-300">
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                        <!-- Search -->
                        <div class="relative flex-1">
                            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search events by title or location..."
                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 outline-none transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                            />
                        </div>

                        <!-- Status filter -->
                        <div class="flex items-center gap-2 sm:gap-2 overflow-x-auto">
                            <button
                                v-for="opt in [
                                    { val: 'all', label: 'All' },
                                    { val: 'upcoming', label: 'Upcoming' },
                                    { val: 'ongoing', label: 'Ongoing' },
                                    { val: 'completed', label: 'Completed' },
                                    { val: 'cancelled', label: 'Cancelled' },
                                ]"
                                :key="opt.val"
                                type="button"
                                @click="statusFilter = opt.val"
                                :class="[
                                    'px-3 py-2 text-xs font-semibold rounded-xl transition whitespace-nowrap',
                                    statusFilter === opt.val
                                        ? 'bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-md shadow-purple-500/30'
                                        : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600'
                                ]"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- EMPTY STATE -->
                <div v-if="events.length === 0" class="rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm border border-slate-100 dark:border-slate-800 shadow-sm p-8 sm:p-16 text-center transition-colors duration-300">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-purple-100 to-pink-100 dark:from-purple-900/50 dark:to-pink-900/50 mb-4">
                        <svg class="h-10 w-10 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">No events yet</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create your first event to get started.</p>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-purple-500/30 transition hover:from-purple-700 hover:to-pink-700 active:scale-95"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Create Your First Event
                    </button>
                </div>

                <!-- EVENTS GRID -->
                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                    <div
                        v-for="event in events"
                        :key="event.id"
                        class="group relative overflow-hidden rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm border border-slate-100 dark:border-slate-800 shadow-sm hover:shadow-xl hover:border-purple-200 dark:hover:border-purple-700 transition-all duration-300"
                    >
                        <!-- Image -->
                        <div class="relative aspect-[16/10] w-full overflow-hidden bg-gradient-to-br from-purple-500 to-pink-600">
                            <img
                                v-if="event.event_image"
                                :src="`/storage/${event.event_image}`"
                                :alt="event.title"
                                class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                            />
                            <div v-else class="flex h-full items-center justify-center">
                                <svg class="h-16 w-16 text-white/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>

                            <!-- Overlay -->
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/70 via-transparent to-transparent"></div>

                            <!-- Status badge -->
                            <div class="absolute top-3 left-3">
                                <span :class="[
                                    'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border-2 backdrop-blur-sm',
                                    statusMeta(event.status).cls
                                ]">
                                    <span :class="['h-1.5 w-1.5 rounded-full', statusMeta(event.status).dot]"></span>
                                    {{ statusMeta(event.status).label }}
                                </span>
                            </div>

                            <!-- Date badge -->
                            <div class="absolute bottom-3 left-3 flex items-center gap-1.5 rounded-xl bg-white/95 dark:bg-slate-800/95 backdrop-blur-sm px-2.5 py-1.5 shadow-lg">
                                <svg class="h-3.5 w-3.5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <span class="text-[11px] font-bold text-slate-700 dark:text-slate-200">
                                    {{ formatDate(event.event_date) }}
                                </span>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="p-4">
                            <h3 class="text-base font-bold text-slate-800 dark:text-slate-100 line-clamp-2 min-h-[2.5rem]">
                                {{ event.title }}
                            </h3>

                            <div class="mt-2 space-y-1.5">
                                <div v-if="event.event_time" class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span class="truncate">{{ formatTime(event.event_time) }}</span>
                                </div>
                                <div v-if="event.location" class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <span class="truncate">{{ event.location }}</span>
                                </div>
                            </div>

                            <p v-if="event.description" class="mt-2 text-xs text-slate-500 dark:text-slate-400 line-clamp-2">
                                {{ event.description }}
                            </p>

                            <!-- Actions -->
                            <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    @click="openEditModal(event)"
                                    class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:from-indigo-600 hover:to-purple-700 active:scale-95"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit
                                </button>

                                <button
                                    type="button"
                                    @click="deleteEvent(event)"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-xl border-2 border-rose-200 dark:border-rose-800 bg-white dark:bg-slate-800 px-3 py-2 text-xs font-bold text-rose-600 dark:text-rose-400 transition hover:bg-rose-50 dark:hover:bg-rose-950/40 hover:border-rose-300 dark:hover:border-rose-700 active:scale-95"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                    </svg>
                                    Delete
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
                        class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-3 sm:p-6 bg-slate-900/60 dark:bg-black/70 backdrop-blur-sm overflow-y-auto"
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
                                class="relative w-full max-w-3xl my-8 rounded-3xl bg-white dark:bg-slate-800 shadow-2xl overflow-hidden transition-colors duration-300"
                                @click.stop
                            >
                                <!-- Modal header -->
                                <div class="relative overflow-hidden bg-gradient-to-r from-purple-600 via-purple-700 to-pink-700 px-5 py-4 sm:px-6 sm:py-5">
                                    <div class="absolute inset-0 opacity-10">
                                        <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    </div>
                                    <div class="relative flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm flex-shrink-0">
                                                <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <h3 class="text-base sm:text-lg font-bold text-white truncate">
                                                    {{ editingEvent ? 'Edit Event' : 'Create Event' }}
                                                </h3>
                                                <p class="text-[11px] sm:text-xs text-purple-100/90">
                                                    {{ editingEvent ? 'Update event details' : 'Add a new event to your website' }}
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

                                <!-- Modal body -->
                                <form @submit.prevent="submit" class="p-4 sm:p-6 space-y-4 sm:space-y-5 max-h-[70vh] overflow-y-auto">
                                    <div class="grid grid-cols-1 gap-4 sm:gap-5 sm:grid-cols-2">

                                        <!-- Title -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Event Title <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.title"
                                                type="text"
                                                placeholder="e.g. Annual Sports Day"
                                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 px-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                            />
                                            <p v-if="form.errors.title" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">
                                                {{ form.errors.title }}
                                            </p>
                                        </div>

                                        <!-- Date -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Event Date <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                v-model="form.event_date"
                                                type="date"
                                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 px-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                            />
                                            <p v-if="form.errors.event_date" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">
                                                {{ form.errors.event_date }}
                                            </p>
                                        </div>

                                        <!-- Time -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Event Time
                                            </label>
                                            <input
                                                v-model="form.event_time"
                                                type="time"
                                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 px-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                            />
                                        </div>

                                        <!-- Location -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Location
                                            </label>
                                            <input
                                                v-model="form.location"
                                                type="text"
                                                placeholder="e.g. School Main Ground"
                                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 px-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                            />
                                        </div>

                                        <!-- Status -->
                                        <div>
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Status
                                            </label>
                                            <select
                                                v-model="form.status"
                                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 px-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30 cursor-pointer"
                                            >
                                                <option value="upcoming">🟦 Upcoming</option>
                                                <option value="ongoing">🟨 Ongoing</option>
                                                <option value="completed">🟩 Completed</option>
                                                <option value="cancelled">🟥 Cancelled</option>
                                            </select>
                                        </div>

                                        <!-- Description -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Description
                                            </label>
                                            <div class="relative">
                                                <textarea
                                                    v-model="form.description"
                                                    rows="3"
                                                    placeholder="Write about this event..."
                                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-purple-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-purple-100 dark:focus:ring-purple-900/30"
                                                ></textarea>
                                                <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 dark:text-slate-500 bg-white/70 dark:bg-slate-900/70 px-1.5 py-0.5 rounded">
                                                    {{ form.description?.length || 0 }} chars
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Image -->
                                        <div class="sm:col-span-2">
                                            <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                                Event Image
                                            </label>
                                            <div
                                                @drop="onDrop"
                                                @dragover="onDragOver"
                                                @dragleave="onDragLeave"
                                                @click="openFilePicker"
                                                :class="[
                                                    'group relative cursor-pointer overflow-hidden rounded-2xl border-2 border-dashed transition-all duration-300',
                                                    isDragging
                                                        ? 'border-purple-500 bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-950/40 dark:to-pink-950/40 scale-[1.01] shadow-xl shadow-purple-200/50 dark:shadow-purple-900/30'
                                                        : 'border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50 hover:border-purple-400 hover:bg-purple-50/40 dark:hover:bg-purple-950/20',
                                                ]"
                                            >
                                                <template v-if="imagePreview || (editingEvent && editingEvent.event_image)">
                                                    <div class="relative aspect-[16/9] w-full overflow-hidden bg-slate-900">
                                                        <img
                                                            :src="imagePreview || `/storage/${editingEvent.event_image}`"
                                                            class="h-full w-full object-cover"
                                                        />
                                                        <div class="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent"></div>

                                                        <div class="absolute inset-0 flex flex-col justify-between p-4">
                                                            <div class="flex justify-end">
                                                                <button
                                                                    type="button"
                                                                    @click.stop="removeImage"
                                                                    class="inline-flex items-center gap-1.5 rounded-xl bg-rose-500/90 backdrop-blur-sm px-3 py-1.5 text-[10px] font-bold text-white shadow-lg transition hover:bg-rose-600 active:scale-95"
                                                                >
                                                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                                                    </svg>
                                                                    Remove
                                                                </button>
                                                            </div>
                                                            <p class="text-[10px] font-bold text-white/90">
                                                                {{ imagePreview ? 'New image selected' : 'Current image' }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </template>

                                                <template v-else>
                                                    <div class="flex flex-col items-center justify-center gap-2 px-4 py-8 text-center">
                                                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-100 to-pink-100 dark:from-purple-900/50 dark:to-pink-900/50 group-hover:from-purple-200 group-hover:to-pink-200 dark:group-hover:from-purple-900/70 dark:group-hover:to-pink-900/70 transition">
                                                            <svg class="h-7 w-7 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                                            </svg>
                                                        </div>
                                                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Upload event image</p>
                                                        <p class="text-[10px] text-slate-500 dark:text-slate-400">Drag & drop or click to browse</p>
                                                    </div>
                                                </template>

                                                <input
                                                    ref="fileInputRef"
                                                    type="file"
                                                    accept="image/*"
                                                    class="hidden"
                                                    @change="onImageChange"
                                                />
                                            </div>
                                            <p v-if="form.errors.event_image" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">
                                                {{ form.errors.event_image }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Modal actions -->
                                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 pt-2 border-t border-slate-100 dark:border-slate-700">
                                        <button
                                            type="button"
                                            @click="closeModal"
                                            class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 transition hover:border-slate-300 dark:hover:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 text-center"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="form.processing"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-purple-500/30 transition hover:from-purple-700 hover:to-pink-700 disabled:cursor-not-allowed disabled:opacity-60 active:scale-95"
                                        >
                                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            {{ form.processing ? 'Saving...' : (editingEvent ? 'Update Event' : 'Create Event') }}
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
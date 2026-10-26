<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, router, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    messages: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
})

const page = usePage()

// ============================================================
// STATE
// ============================================================
const search = ref(props.filters?.search ?? '')
const statusFilter = ref(props.filters?.status ?? 'all')
const showViewModal = ref(false)
const selectedMessage = ref(null)
const showReplyModal = ref(false)
const replyingTo = ref(null)

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
// STATS COMPUTED
// ============================================================
const stats = computed(() => ({
    total:     props.stats?.total     ?? props.messages.length,
    unread:    props.stats?.unread    ?? props.messages.filter(m => m.status === 'new').length,
    read:      props.stats?.read      ?? props.messages.filter(m => m.status === 'read').length,
    replied:   props.stats?.replied   ?? props.messages.filter(m => m.status === 'replied').length,
}))

// ============================================================
// VIEW MESSAGE — mark as read
// ============================================================
const openMessage = (message) => {
    selectedMessage.value = message
    showViewModal.value = true

    // Mark as read if new
    if (message.status === 'new') {
        router.post(route('website-management.messages.mark-read', message.id), {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // Update local
                message.status = 'read'
            },
        })
    }
}

const closeMessageModal = () => {
    showViewModal.value = false
    selectedMessage.value = null
}

// ============================================================
// REPLY MODAL
// ============================================================
const openReplyModal = (message) => {
    replyingTo.value = message
    replyForm.reply_message = ''
    replyForm.clearErrors()
    showReplyModal.value = true
}

const closeReplyModal = () => {
    showReplyModal.value = false
    replyingTo.value = null
    replyForm.reset()
}

// ============================================================
// REPLY FORM
// ============================================================
const replyForm = useForm({
    reply_message: '',
})

const submitReply = () => {
    if (!replyingTo.value) return

    replyForm.post(route('website-management.messages.reply', replyingTo.value.id), {
        preserveScroll: true,
        onSuccess: (resp) => {
            showToast(resp.props.flash?.success || 'Reply sent successfully! ✅', 'success')
            closeReplyModal()
            closeMessageModal()
        },
        onError: (errors) => {
            const first = Object.values(errors)[0] || 'Failed to send reply.'
            showToast(first, 'error')
        },
    })
}

// ============================================================
// DELETE MESSAGE
// ============================================================
const deleteMessage = (message) => {
    if (!confirm(`Delete message from "${message.name}"? This cannot be undone.`)) return

    router.delete(route('website-management.messages.destroy', message.id), {
        preserveScroll: true,
        onSuccess: (resp) => {
            showToast(resp.props.flash?.success || 'Message deleted successfully! ✅', 'success')
            if (selectedMessage.value?.id === message.id) closeMessageModal()
        },
        onError: () => {
            showToast('Failed to delete message.', 'error')
        },
    })
}

// ============================================================
// MARK UNREAD
// ============================================================
const markUnread = (message) => {
    router.post(route('website-management.messages.mark-unread', message.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (resp) => {
            showToast(resp.props.flash?.success || 'Marked as unread', 'info')
            message.status = 'new'
            closeMessageModal()
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
        router.get(route('website-management.messages'), {
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
    router.get(route('website-management.messages'), {
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
const statusMeta = (status) => {
    const map = {
        new:     { label: 'New',     cls: 'bg-rose-100 text-rose-700 border-rose-300 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-700',       dot: 'bg-rose-500' },
        read:    { label: 'Read',    cls: 'bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700',       dot: 'bg-blue-500' },
        replied: { label: 'Replied', cls: 'bg-emerald-100 text-emerald-700 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700', dot: 'bg-emerald-500' },
    }
    return map[status] ?? map.new
}

const formatDateTime = (dt) => {
    if (!dt) return '—'
    try {
        const d = new Date(dt)
        return d.toLocaleString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    } catch {
        return dt
    }
}

const formatShortDate = (dt) => {
    if (!dt) return '—'
    try {
        const d = new Date(dt)
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })
    } catch {
        return dt
    }
}
</script>

<template>
    <Head title="Contact Messages" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-blue-50/40 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
            <!-- Decorative blobs - overflow-hidden wrapper এর ভিতরে রাখা হয়েছে -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-blue-200/30 blur-3xl dark:bg-blue-800/20"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-indigo-200/30 blur-3xl dark:bg-indigo-800/20"></div>
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
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl shadow-lg shadow-blue-500/20 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent truncate dark:from-slate-100 dark:to-slate-400">
                                    Contact Messages
                                </h1>
                                <p class="text-xs sm:text-sm text-slate-500 flex items-center gap-2 mt-1 dark:text-slate-400">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                    Messages from your website visitors
                                </p>
                            </div>
                        </div>

                        <Link
                            :href="route('website-management.settings')"
                            class="group inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white/80 backdrop-blur-sm px-5 py-2.5 text-sm font-medium text-slate-700 transition-all duration-300 hover:bg-white hover:border-slate-300 hover:shadow-lg dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:border-slate-600"
                        >
                            <svg class="w-4 h-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Back
                        </Link>
                    </div>
                </div>

                <!-- STATS CARDS -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-5 sm:mb-6">
                    <div class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-3 sm:p-4 dark:bg-slate-900/80 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-md flex-shrink-0">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] sm:text-xs font-bold text-slate-500 uppercase tracking-wider dark:text-slate-400">Total</p>
                                <p class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-slate-100">{{ stats.total }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-3 sm:p-4 dark:bg-slate-900/80 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-red-600 text-white shadow-md flex-shrink-0">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] sm:text-xs font-bold text-slate-500 uppercase tracking-wider dark:text-slate-400">Unread</p>
                                <p class="text-xl sm:text-2xl font-bold text-rose-600 dark:text-rose-400">{{ stats.unread }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-3 sm:p-4 dark:bg-slate-900/80 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-cyan-500 text-white shadow-md flex-shrink-0">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] sm:text-xs font-bold text-slate-500 uppercase tracking-wider dark:text-slate-400">Read</p>
                                <p class="text-xl sm:text-2xl font-bold text-blue-600 dark:text-blue-400">{{ stats.read }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-3 sm:p-4 dark:bg-slate-900/80 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 sm:h-11 sm:w-11 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-md flex-shrink-0">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[10px] sm:text-xs font-bold text-slate-500 uppercase tracking-wider dark:text-slate-400">Replied</p>
                                <p class="text-xl sm:text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.replied }}</p>
                            </div>
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
                                placeholder="Search by name, email, subject..."
                                class="w-full rounded-xl border border-slate-200 bg-white/70 py-2.5 pl-10 pr-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-blue-500 dark:focus:bg-slate-800 dark:focus:ring-blue-900/40"
                            />
                        </div>

                        <div class="flex items-center gap-2 overflow-x-auto">
                            <button
                                v-for="opt in [
                                    { val: 'all', label: 'All' },
                                    { val: 'new', label: 'New' },
                                    { val: 'read', label: 'Read' },
                                    { val: 'replied', label: 'Replied' },
                                ]"
                                :key="opt.val"
                                type="button"
                                @click="statusFilter = opt.val"
                                :class="[
                                    'px-3 py-2 text-xs font-semibold rounded-xl transition whitespace-nowrap',
                                    statusFilter === opt.val
                                        ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-500/30'
                                        : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700 dark:hover:border-slate-600'
                                ]"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- EMPTY STATE -->
                <div v-if="messages.length === 0" class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm p-8 sm:p-16 text-center dark:bg-slate-900/80 dark:border-slate-800">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-blue-100 to-indigo-100 mb-4 dark:from-blue-900/40 dark:to-indigo-900/40">
                        <svg class="h-10 w-10 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">No messages yet</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Messages from website visitors will appear here.</p>
                </div>

                <!-- MESSAGES LIST -->
                <div v-else class="rounded-2xl bg-white/80 backdrop-blur-sm border border-slate-100 shadow-sm overflow-hidden dark:bg-slate-900/80 dark:border-slate-800">

                    <!-- Desktop table header -->
                    <div class="hidden lg:grid grid-cols-12 gap-4 px-5 py-3 border-b border-slate-100 bg-slate-50/50 text-[10px] font-bold text-slate-500 uppercase tracking-wider dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                        <div class="col-span-3">Sender</div>
                        <div class="col-span-3">Subject</div>
                        <div class="col-span-3">Message Preview</div>
                        <div class="col-span-1">Date</div>
                        <div class="col-span-1">Status</div>
                        <div class="col-span-1 text-right">Actions</div>
                    </div>

                    <!-- Message rows -->
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        :class="[
                            'group relative border-b border-slate-100 transition hover:bg-blue-50/40 dark:border-slate-800 dark:hover:bg-slate-800/40',
                            msg.status === 'new' ? 'bg-blue-50/30 dark:bg-blue-950/20' : ''
                        ]"
                    >
                        <!-- Desktop -->
                        <div class="hidden lg:grid grid-cols-12 gap-4 px-5 py-4 items-center">
                            <!-- Sender -->
                            <div class="col-span-3 flex items-center gap-3 min-w-0">
                                <div :class="[
                                    'flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white flex-shrink-0 shadow-sm',
                                    msg.status === 'new' ? 'bg-gradient-to-br from-rose-500 to-pink-600' : 'bg-gradient-to-br from-blue-500 to-indigo-600'
                                ]">
                                    {{ msg.name?.charAt(0)?.toUpperCase() || 'U' }}
                                </div>
                                <div class="min-w-0">
                                    <p :class="['text-sm truncate', msg.status === 'new' ? 'font-bold text-slate-800 dark:text-slate-100' : 'font-semibold text-slate-700 dark:text-slate-200']">
                                        {{ msg.name }}
                                    </p>
                                    <p class="text-xs text-slate-500 truncate dark:text-slate-400">{{ msg.email }}</p>
                                </div>
                            </div>

                            <!-- Subject -->
                            <div class="col-span-3 min-w-0">
                                <p :class="['text-sm truncate', msg.status === 'new' ? 'font-bold text-slate-800 dark:text-slate-100' : 'text-slate-700 dark:text-slate-200']">
                                    {{ msg.subject || 'No subject' }}
                                </p>
                            </div>

                            <!-- Preview -->
                            <div class="col-span-3 min-w-0">
                                <p class="text-xs text-slate-500 truncate dark:text-slate-400">{{ msg.message?.substring(0, 60) }}{{ msg.message?.length > 60 ? '...' : '' }}</p>
                            </div>

                            <!-- Date -->
                            <div class="col-span-1">
                                <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ formatShortDate(msg.created_at) }}</p>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500">{{ new Date(msg.created_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) }}</p>
                            </div>

                            <!-- Status -->
                            <div class="col-span-1">
                                <span :class="[
                                    'inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-bold border-2',
                                    statusMeta(msg.status).cls
                                ]">
                                    <span :class="['h-1.5 w-1.5 rounded-full', statusMeta(msg.status).dot, msg.status === 'new' ? 'animate-pulse' : '']"></span>
                                    {{ statusMeta(msg.status).label }}
                                </span>
                            </div>

                            <!-- Actions -->
                            <div class="col-span-1 flex items-center justify-end gap-1.5">
                                <button
                                    type="button"
                                    @click="openMessage(msg)"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 text-blue-700 hover:bg-blue-200 transition dark:bg-blue-900/40 dark:text-blue-300 dark:hover:bg-blue-900/60"
                                    title="View"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    @click="deleteMessage(msg)"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rose-100 text-rose-700 hover:bg-rose-200 transition dark:bg-rose-900/40 dark:text-rose-300 dark:hover:bg-rose-900/60"
                                    title="Delete"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Mobile card -->
                        <div class="lg:hidden p-4" @click="openMessage(msg)">
                            <div class="flex items-start gap-3">
                                <div :class="[
                                    'flex h-11 w-11 items-center justify-center rounded-full text-sm font-bold text-white flex-shrink-0 shadow-md',
                                    msg.status === 'new' ? 'bg-gradient-to-br from-rose-500 to-pink-600' : 'bg-gradient-to-br from-blue-500 to-indigo-600'
                                ]">
                                    {{ msg.name?.charAt(0)?.toUpperCase() || 'U' }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <p :class="['text-sm truncate', msg.status === 'new' ? 'font-bold text-slate-800 dark:text-slate-100' : 'font-semibold text-slate-700 dark:text-slate-200']">
                                            {{ msg.name }}
                                        </p>
                                        <span :class="[
                                            'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold border-2 flex-shrink-0',
                                            statusMeta(msg.status).cls
                                        ]">
                                            <span :class="['h-1 w-1 rounded-full', statusMeta(msg.status).dot]"></span>
                                            {{ statusMeta(msg.status).label }}
                                        </span>
                                    </div>
                                    <p :class="['text-xs font-semibold truncate', msg.status === 'new' ? 'text-slate-700 dark:text-slate-200' : 'text-slate-600 dark:text-slate-300']">
                                        {{ msg.subject || 'No subject' }}
                                    </p>
                                    <p class="text-[11px] text-slate-500 truncate mt-0.5 dark:text-slate-400">
                                        {{ msg.message?.substring(0, 50) }}...
                                    </p>
                                    <div class="flex items-center justify-between mt-2">
                                        <p class="text-[10px] text-slate-400 dark:text-slate-500">{{ formatDateTime(msg.created_at) }}</p>
                                        <button
                                            type="button"
                                            @click.stop="deleteMessage(msg)"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100 text-rose-700 hover:bg-rose-200 transition dark:bg-rose-900/40 dark:text-rose-300 dark:hover:bg-rose-900/60"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================= -->
                <!-- VIEW MESSAGE MODAL -->
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
                        v-if="showViewModal && selectedMessage"
                        class="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-3 sm:p-6 bg-slate-900/60 backdrop-blur-sm overflow-y-auto"
                        @click.self="closeMessageModal"
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
                                v-if="showViewModal"
                                class="relative w-full max-w-2xl my-8 rounded-3xl bg-white shadow-2xl overflow-hidden dark:bg-slate-900"
                                @click.stop
                            >
                                <!-- Header -->
                                <div class="relative overflow-hidden bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 px-5 py-4 sm:px-6 sm:py-5">
                                    <div class="absolute inset-0 opacity-10">
                                        <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    </div>
                                    <div class="relative flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm text-white text-lg font-bold flex-shrink-0 shadow-lg border-2 border-white/30">
                                                {{ selectedMessage.name?.charAt(0)?.toUpperCase() || 'U' }}
                                            </div>
                                            <div class="min-w-0">
                                                <h3 class="text-base sm:text-lg font-bold text-white truncate">
                                                    {{ selectedMessage.name }}
                                                </h3>
                                                <p class="text-[11px] sm:text-xs text-blue-100/90 truncate">
                                                    {{ selectedMessage.email }}
                                                </p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            @click="closeMessageModal"
                                            class="flex-shrink-0 rounded-xl p-2 text-white/80 hover:bg-white/20 transition"
                                        >
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Body -->
                                <div class="p-4 sm:p-6 space-y-4 max-h-[70vh] overflow-y-auto">

                                    <!-- Meta row -->
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                        <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 dark:text-slate-400">Phone</p>
                                            <p class="text-xs font-semibold text-slate-700 truncate dark:text-slate-200">{{ selectedMessage.phone || '—' }}</p>
                                        </div>
                                        <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 dark:text-slate-400">Date</p>
                                            <p class="text-xs font-semibold text-slate-700 truncate dark:text-slate-200">{{ formatDateTime(selectedMessage.created_at) }}</p>
                                        </div>
                                        <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 col-span-2 sm:col-span-1 dark:border-slate-700 dark:bg-slate-800/50">
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 dark:text-slate-400">Status</p>
                                            <span :class="[
                                                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border',
                                                statusMeta(selectedMessage.status).cls
                                            ]">
                                                {{ statusMeta(selectedMessage.status).label }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Subject -->
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5 dark:text-slate-400">Subject</p>
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ selectedMessage.subject || 'No subject' }}</p>
                                    </div>

                                    <!-- Message -->
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5 dark:text-slate-400">Message</p>
                                        <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                                            <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line dark:text-slate-200">{{ selectedMessage.message }}</p>
                                        </div>
                                    </div>

                                    <!-- Admin reply (if replied) -->
                                    <div v-if="selectedMessage.admin_reply">
                                        <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider mb-1.5 dark:text-emerald-400">Your Reply</p>
                                        <div class="rounded-xl border-2 border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40">
                                            <p class="text-sm text-emerald-900 leading-relaxed whitespace-pre-line dark:text-emerald-200">{{ selectedMessage.admin_reply }}</p>
                                            <p v-if="selectedMessage.replied_at" class="text-[10px] text-emerald-600 mt-2 dark:text-emerald-400">
                                                Replied: {{ formatDateTime(selectedMessage.replied_at) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Footer actions -->
                                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 sm:gap-3 px-4 sm:px-6 py-4 bg-slate-50/50 border-t border-slate-100 dark:bg-slate-800/50 dark:border-slate-800">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            v-if="selectedMessage.status !== 'new'"
                                            type="button"
                                            @click="markUnread(selectedMessage)"
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 19v-8.93a2 2 0 01.89-1.664l7-4.666a2 2 0 012.22 0l7 4.666A2 2 0 0121 10.07V19M3 19a2 2 0 002 2h14a2 2 0 002-2M3 19l6.75-4.5M21 19l-6.75-4.5M3 10l6.75 4.5M21 10l-6.75 4.5m0 0l-1.14.76a2 2 0 01-2.22 0l-1.14-.76" />
                                            </svg>
                                            Mark Unread
                                        </button>
                                        <button
                                            type="button"
                                            @click="deleteMessage(selectedMessage)"
                                            class="inline-flex items-center gap-1.5 rounded-xl border-2 border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition dark:border-rose-800 dark:bg-slate-800 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 3h6" />
                                            </svg>
                                            Delete
                                        </button>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            @click="closeMessageModal"
                                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                        >
                                            Close
                                        </button>
                                        <button
                                            type="button"
                                            @click="openReplyModal(selectedMessage)"
                                            class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 px-4 py-2 text-xs font-bold text-white shadow-md shadow-emerald-500/30 hover:from-emerald-600 hover:to-teal-700 transition"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                                            </svg>
                                            Reply
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </Transition>

                <!-- ============================================= -->
                <!-- REPLY MODAL -->
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
                        v-if="showReplyModal && replyingTo"
                        class="fixed inset-0 z-[60] flex items-start sm:items-center justify-center p-3 sm:p-6 bg-slate-900/60 backdrop-blur-sm overflow-y-auto"
                        @click.self="closeReplyModal"
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
                                v-if="showReplyModal"
                                class="relative w-full max-w-2xl my-8 rounded-3xl bg-white shadow-2xl overflow-hidden dark:bg-slate-900"
                                @click.stop
                            >
                                <!-- Header -->
                                <div class="relative overflow-hidden bg-gradient-to-r from-emerald-600 via-emerald-700 to-teal-700 px-5 py-4 sm:px-6 sm:py-5">
                                    <div class="absolute inset-0 opacity-10">
                                        <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
                                    </div>
                                    <div class="relative flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm flex-shrink-0">
                                                <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <h3 class="text-base sm:text-lg font-bold text-white truncate">Reply to {{ replyingTo.name }}</h3>
                                                <p class="text-[11px] sm:text-xs text-emerald-100/90 truncate">{{ replyingTo.email }}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            @click="closeReplyModal"
                                            class="flex-shrink-0 rounded-xl p-2 text-white/80 hover:bg-white/20 transition"
                                        >
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Body -->
                                <form @submit.prevent="submitReply" class="p-4 sm:p-6 space-y-4">
                                    <!-- Original message (collapsed) -->
                                    <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 dark:text-slate-400">Original Message</p>
                                        <p class="text-xs text-slate-600 line-clamp-3 dark:text-slate-300">{{ replyingTo.message }}</p>
                                    </div>

                                    <!-- Reply textarea -->
                                    <div>
                                        <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                            Your Reply <span class="text-rose-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <textarea
                                                v-model="replyForm.reply_message"
                                                rows="6"
                                                placeholder="Type your reply message..."
                                                class="w-full rounded-xl border border-slate-200 bg-white/70 px-4 py-3 text-sm text-slate-700 shadow-sm transition placeholder:text-slate-400 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:placeholder:text-slate-500 dark:focus:border-emerald-500 dark:focus:bg-slate-800 dark:focus:ring-emerald-900/40"
                                            ></textarea>
                                            <div class="absolute bottom-3 right-3 text-[10px] font-medium text-slate-400 bg-white/70 px-1.5 py-0.5 rounded dark:text-slate-500 dark:bg-slate-800/70">
                                                {{ replyForm.reply_message?.length || 0 }} chars
                                            </div>
                                        </div>
                                        <p v-if="replyForm.errors.reply_message" class="mt-1.5 text-xs font-medium text-rose-500">
                                            {{ replyForm.errors.reply_message }}
                                        </p>
                                    </div>

                                    <!-- Info note -->
                                    <div class="rounded-xl bg-blue-50/70 border border-blue-200 p-3 flex items-start gap-2 dark:bg-blue-950/40 dark:border-blue-800">
                                        <svg class="h-4 w-4 text-blue-600 flex-shrink-0 mt-0.5 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <p class="text-[11px] text-blue-800 leading-relaxed dark:text-blue-200">
                                            Reply email <strong>{{ replyingTo.email }}</strong> e pathano hobe. Ensure your mail settings are configured.
                                        </p>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                                        <button
                                            type="button"
                                            @click="closeReplyModal"
                                            class="rounded-xl border border-slate-200 bg-white px-6 py-2.5 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 text-center dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="replyForm.processing"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/30 transition hover:from-emerald-600 hover:to-teal-700 disabled:cursor-not-allowed disabled:opacity-60 active:scale-95"
                                        >
                                            <svg v-if="replyForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                            </svg>
                                            {{ replyForm.processing ? 'Sending...' : 'Send Reply' }}
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
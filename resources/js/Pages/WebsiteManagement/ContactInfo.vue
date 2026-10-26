<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps({
    contact: { type: Object, default: () => ({}) },
})

const page = usePage()

// ============================================================
// ORIGINAL — from DB
// ============================================================
const original = {
    address:           props.contact?.address ?? '',
    phone:             props.contact?.phone ?? '',
    alternative_phone: props.contact?.alternative_phone ?? '',
    email:             props.contact?.email ?? '',
    alternative_email: props.contact?.alternative_email ?? '',
    google_maps_url:   props.contact?.google_maps_url ?? '',
    office_hours:      props.contact?.office_hours ?? '',
    facebook_url:      props.contact?.facebook_url ?? '',
    youtube_url:       props.contact?.youtube_url ?? '',
    instagram_url:     props.contact?.instagram_url ?? '',
}

// ============================================================
// FORM
// ============================================================
const form = useForm({
    address:           original.address,
    phone:             original.phone,
    alternative_phone: original.alternative_phone,
    email:             original.email,
    alternative_email: original.alternative_email,
    google_maps_url:   original.google_maps_url,
    office_hours:      original.office_hours,
    facebook_url:      original.facebook_url,
    youtube_url:       original.youtube_url,
    instagram_url:     original.instagram_url,
})

// ============================================================
// FIELD LABELS
// ============================================================
const fieldLabels = {
    address:           'Address',
    phone:             'Phone',
    alternative_phone: 'Alternative Phone',
    email:             'Email',
    alternative_email: 'Alternative Email',
    google_maps_url:   'Google Maps URL',
    office_hours:      'Office Hours',
    facebook_url:      'Facebook URL',
    youtube_url:       'YouTube URL',
    instagram_url:     'Instagram URL',
}

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
// WATCH FOR CHANGES
// ============================================================
const watchedFields = [
    'address', 'phone', 'alternative_phone', 'email', 'alternative_email',
    'google_maps_url', 'office_hours',
    'facebook_url', 'youtube_url', 'instagram_url',
]

watchedFields.forEach((field) => {
    watch(() => form[field], (newVal, oldVal) => {
        if (newVal === oldVal) return
        const label = fieldLabels[field] ?? field
        const origVal = original[field] ?? ''

        if (String(newVal).trim() === String(origVal).trim()) {
            showToast(`${label} reverted to original value`, 'info')
            return
        }
        showToast(`${label} updated`, 'info')
    })
})

// ============================================================
// SUBMIT
// ============================================================
const submit = () => {
    form.post(route('website-management.contact-info.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: (pageResp) => {
            showToast(
                pageResp.props.flash?.success || 'Contact info saved successfully! ✅',
                'success'
            )
            Object.keys(original).forEach((key) => {
                if (key in form) original[key] = form[key]
            })
        },
        onError: (errors) => {
            const first = Object.values(errors)[0] || 'Please fix the errors and try again.'
            showToast(first, 'error')
        },
    })
}
</script>

<template>
    <Head title="Contact Information Management" />

    <AuthenticatedLayout>
        <!-- overflow-x-hidden যোগ করা হয়েছে horizontal scroll বন্ধ করতে -->
        <div class="relative min-h-screen w-full overflow-x-hidden bg-gradient-to-br from-slate-50 via-white to-emerald-50/40 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-3 py-4 sm:px-4 sm:py-6 md:px-6 md:py-7 lg:px-8 lg:py-8 transition-colors duration-300">
            <!-- Decorative blobs - now contained inside overflow-hidden wrapper -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -left-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-emerald-200/30 dark:bg-emerald-800/10 blur-3xl"></div>
                <div class="absolute top-1/3 -right-24 h-48 w-48 sm:h-72 sm:w-72 rounded-full bg-teal-200/30 dark:bg-teal-800/10 blur-3xl"></div>
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
                            <div class="p-2.5 sm:p-3 bg-gradient-to-br from-emerald-600 to-teal-600 rounded-2xl shadow-lg shadow-emerald-500/20 flex-shrink-0">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 dark:from-slate-100 dark:to-slate-300 bg-clip-text text-transparent truncate">
                                    Contact Information
                                </h1>
                                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 flex items-center gap-2 mt-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Manage contact details shown on your public website
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
                                @click="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-300 hover:from-emerald-700 hover:to-teal-700 hover:shadow-lg hover:shadow-emerald-500/25 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg v-if="!form.processing" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                {{ form.processing ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-4 sm:space-y-6">

                    <!-- ============================================= -->
                    <!-- 📍 ADDRESS -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-emerald-50/80 via-white to-teal-50/60 dark:from-emerald-950/30 dark:via-slate-900 dark:to-teal-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">📍 Address</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Where your school is located</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6">
                            <div class="relative group">
                                <div class="pointer-events-none absolute left-0 top-3.5 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-emerald-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </div>
                                <textarea
                                    v-model="form.address"
                                    rows="3"
                                    placeholder="Enter full school address"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-emerald-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-emerald-100 dark:focus:ring-emerald-900/30"
                                ></textarea>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 📞 PHONE & EMAIL -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-blue-50/80 via-white to-indigo-50/60 dark:from-blue-950/30 dark:via-slate-900 dark:to-indigo-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 shadow-lg shadow-blue-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">📞 Phone & Email</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">How visitors can reach you</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6 md:grid-cols-2">
                            <!-- Phone -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Phone</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-blue-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.phone"
                                        type="text"
                                        placeholder="+880 1XXX-XXXXXX"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    />
                                </div>
                                <p v-if="form.errors.phone" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">{{ form.errors.phone }}</p>
                            </div>

                            <!-- Alternative Phone -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Alternative Phone</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-blue-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.alternative_phone"
                                        type="text"
                                        placeholder="+880 1XXX-XXXXXX"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    />
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Email</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-blue-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.email"
                                        type="email"
                                        placeholder="info@school.com"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    />
                                </div>
                                <p v-if="form.errors.email" class="mt-1.5 text-xs font-medium text-rose-500 dark:text-rose-400">{{ form.errors.email }}</p>
                            </div>

                            <!-- Alternative Email -->
                            <div>
                                <label class="mb-2 block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Alternative Email</label>
                                <div class="group relative">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-blue-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <input
                                        v-model="form.alternative_email"
                                        type="email"
                                        placeholder="admin@school.com"
                                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                    />
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🕒 OFFICE HOURS -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-amber-50/80 via-white to-orange-50/60 dark:from-amber-950/30 dark:via-slate-900 dark:to-orange-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-lg shadow-amber-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🕒 Office Hours</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">When visitors can visit or call</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6">
                            <div class="group relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-amber-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.office_hours"
                                    type="text"
                                    placeholder="e.g. Sat - Thu, 8:00 AM - 4:00 PM"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-amber-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-amber-100 dark:focus:ring-amber-900/30"
                                />
                            </div>
                            <p class="mt-1.5 text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                Visitors will see this on the contact page.
                            </p>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🗺️ GOOGLE MAPS -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-cyan-50/80 via-white to-sky-50/60 dark:from-cyan-950/30 dark:via-slate-900 dark:to-sky-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 to-sky-600 shadow-lg shadow-cyan-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🗺️ Google Maps</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Show your school location on a map</p>
                            </div>
                        </header>

                        <div class="p-3 sm:p-6 space-y-4">
                            <div class="group relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 dark:text-slate-500 transition group-focus-within:text-cyan-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.google_maps_url"
                                    type="text"
                                    placeholder="https://maps.google.com/..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 py-2.5 sm:py-3 pl-10 pr-4 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-cyan-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-cyan-100 dark:focus:ring-cyan-900/30"
                                />
                            </div>
                            <p class="text-[11px] sm:text-xs text-slate-400 dark:text-slate-500">
                                Paste the Google Maps <strong>embed URL</strong> (from Share → Embed a map).
                            </p>

                            <!-- Map Preview -->
                            <div v-if="form.google_maps_url" class="rounded-2xl overflow-hidden border-2 border-slate-200 dark:border-slate-700 shadow-sm">
                                <iframe
                                    :src="form.google_maps_url"
                                    class="w-full h-64 sm:h-72"
                                    style="border:0;"
                                    loading="lazy"
                                    referrerpolicy="no-referrer-when-downgrade"
                                ></iframe>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 🌐 SOCIAL LINKS -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-pink-50/80 via-white to-purple-50/60 dark:from-pink-950/30 dark:via-slate-900 dark:to-purple-950/20 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-pink-500 to-purple-600 shadow-lg shadow-pink-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                        d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">🌐 Social Links</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">Connect social media profiles</p>
                            </div>
                        </header>

                        <div class="grid grid-cols-1 gap-4 sm:gap-6 p-3 sm:p-6 md:grid-cols-2 lg:grid-cols-3">
                            <!-- Facebook -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-blue-600 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.52 1.49-3.9 3.78-3.9 1.09 0 2.24.19 2.24.19v2.47h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.9h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z" />
                                        </svg>
                                    </span>
                                    Facebook
                                </label>
                                <input
                                    v-model="form.facebook_url"
                                    type="text"
                                    placeholder="https://facebook.com/yourschool"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-blue-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-blue-100 dark:focus:ring-blue-900/30"
                                />
                            </div>

                            <!-- YouTube -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-red-600 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M23.5 6.2a3 3 0 00-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 00.5 6.2 31.3 31.3 0 000 12a31.3 31.3 0 00.5 5.8 3 3 0 002.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 002.1-2.1A31.3 31.3 0 0024 12a31.3 31.3 0 00-.5-5.8zM9.6 15.6V8.4l6.2 3.6-6.2 3.6z" />
                                        </svg>
                                    </span>
                                    YouTube
                                </label>
                                <input
                                    v-model="form.youtube_url"
                                    type="text"
                                    placeholder="https://youtube.com/@yourschool"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-red-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-red-100 dark:focus:ring-red-900/30"
                                />
                            </div>

                            <!-- Instagram -->
                            <div>
                                <label class="mb-2 flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-md bg-gradient-to-br from-pink-500 to-purple-600 text-white flex-shrink-0">
                                        <svg class="h-3 w-3 sm:h-3.5 sm:w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23a3.7 3.7 0 01-.9 1.38 3.7 3.7 0 01-1.38.9c-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 01-1.38-.9 3.7 3.7 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 1.62c-3.15 0-3.52.01-4.76.07-1.15.05-1.77.24-2.18.4-.55.22-.94.47-1.35.88-.41.41-.66.8-.88 1.35-.16.41-.35 1.03-.4 2.18-.06 1.24-.07 1.61-.07 4.76s.01 3.52.07 4.76c.05 1.15.24 1.77.4 2.18.22.55.47.94.88 1.35.41.41.8.66 1.35.88.41.16 1.03.35 2.18.4 1.24.06 1.61.07 4.76.07s3.52-.01 4.76-.07c1.15-.05 1.77-.24 2.18-.4.55-.22.94-.47 1.35-.88.41-.41.66-.8.88-1.35.16-.41.35-1.03.4-2.18.06-1.24.07-1.61.07-4.76s-.01-3.52-.07-4.76c-.05-1.15-.24-1.77-.4-2.18a3.6 3.6 0 00-.88-1.35 3.6 3.6 0 00-1.35-.88c-.41-.16-1.03-.35-2.18-.4-1.24-.06-1.61-.07-4.76-.07zm0 2.76a5.46 5.46 0 110 10.92 5.46 5.46 0 010-10.92zm0 9a3.54 3.54 0 100-7.08 3.54 3.54 0 000 7.08zm6.95-9.22a1.27 1.27 0 11-2.55 0 1.27 1.27 0 012.55 0z" />
                                        </svg>
                                    </span>
                                    Instagram
                                </label>
                                <input
                                    v-model="form.instagram_url"
                                    type="text"
                                    placeholder="https://instagram.com/yourschool"
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-900/50 px-4 py-2.5 sm:py-3 text-sm text-slate-700 dark:text-slate-100 shadow-sm transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-pink-500 focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-pink-100 dark:focus:ring-pink-900/30"
                                />
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- 👁️ LIVE PREVIEW -->
                    <!-- ============================================= -->
                    <section class="overflow-hidden rounded-2xl sm:rounded-3xl bg-white/80 dark:bg-slate-900/80 shadow-xl shadow-slate-200/50 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-800 backdrop-blur-sm transition-colors duration-300">
                        <header class="relative flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 bg-gradient-to-r from-slate-50/80 via-white to-slate-50/60 dark:from-slate-800/50 dark:via-slate-900 dark:to-slate-800/40 px-3 py-3 sm:px-6 sm:py-5">
                            <div class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl bg-gradient-to-br from-slate-600 to-slate-800 dark:from-slate-500 dark:to-slate-700 shadow-lg shadow-slate-500/30 flex-shrink-0">
                                <svg class="h-4 w-4 sm:h-5 sm:w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 truncate">👁️ Live Preview</h3>
                                <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 truncate">How your contact page will look</p>
                            </div>
                            <span class="hidden sm:inline-flex items-center gap-1.5 rounded-full bg-emerald-50 dark:bg-emerald-900/40 px-3 py-1.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300 ring-1 ring-emerald-200 dark:ring-emerald-800">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                LIVE
                            </span>
                        </header>

                        <div class="p-3 sm:p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">

                                <!-- Contact info card -->
                                <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-950/40 dark:to-teal-950/40 border-2 border-emerald-100 dark:border-emerald-800 p-5 space-y-4 transition-colors duration-300">
                                    <h4 class="text-base font-bold text-emerald-900 dark:text-emerald-200 flex items-center gap-2">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                        </svg>
                                        Contact Us
                                    </h4>

                                    <div class="space-y-3">
                                        <!-- Address -->
                                        <div v-if="form.address" class="flex items-start gap-3">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-white flex-shrink-0">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Address</p>
                                                <p class="text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line">{{ form.address }}</p>
                                            </div>
                                        </div>

                                        <!-- Phone -->
                                        <div v-if="form.phone || form.alternative_phone" class="flex items-start gap-3">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-white flex-shrink-0">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Phone</p>
                                                <p v-if="form.phone" class="text-sm text-slate-700 dark:text-slate-200">{{ form.phone }}</p>
                                                <p v-if="form.alternative_phone" class="text-xs text-slate-500 dark:text-slate-400">{{ form.alternative_phone }}</p>
                                            </div>
                                        </div>

                                        <!-- Email -->
                                        <div v-if="form.email || form.alternative_email" class="flex items-start gap-3">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-white flex-shrink-0">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Email</p>
                                                <p v-if="form.email" class="text-sm text-slate-700 dark:text-slate-200">{{ form.email }}</p>
                                                <p v-if="form.alternative_email" class="text-xs text-slate-500 dark:text-slate-400">{{ form.alternative_email }}</p>
                                            </div>
                                        </div>

                                        <!-- Office Hours -->
                                        <div v-if="form.office_hours" class="flex items-start gap-3">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-white flex-shrink-0">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Office Hours</p>
                                                <p class="text-sm text-slate-700 dark:text-slate-200">{{ form.office_hours }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Social links preview -->
                                    <div v-if="form.facebook_url || form.youtube_url || form.instagram_url" class="pt-3 border-t border-emerald-200 dark:border-emerald-800">
                                        <p class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider mb-2">Follow Us</p>
                                        <div class="flex items-center gap-2">
                                            <a v-if="form.facebook_url" :href="form.facebook_url" target="_blank" class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white hover:scale-110 transition">
                                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.52 1.49-3.9 3.78-3.9 1.09 0 2.24.19 2.24.19v2.47h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.9h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z" /></svg>
                                            </a>
                                            <a v-if="form.youtube_url" :href="form.youtube_url" target="_blank" class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-600 text-white hover:scale-110 transition">
                                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M23.5 6.2a3 3 0 00-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 00.5 6.2 31.3 31.3 0 000 12a31.3 31.3 0 00.5 5.8 3 3 0 002.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 002.1-2.1A31.3 31.3 0 0024 12a31.3 31.3 0 00-.5-5.8zM9.6 15.6V8.4l6.2 3.6-6.2 3.6z" /></svg>
                                            </a>
                                            <a v-if="form.instagram_url" :href="form.instagram_url" target="_blank" class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-pink-500 to-purple-600 text-white hover:scale-110 transition">
                                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23a3.7 3.7 0 01-.9 1.38 3.7 3.7 0 01-1.38.9c-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 01-1.38-.9 3.7 3.7 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 1.62c-3.15 0-3.52.01-4.76.07-1.15.05-1.77.24-2.18.4-.55.22-.94.47-1.35.88-.41.41-.66.8-.88 1.35-.16.41-.35 1.03-.4 2.18-.06 1.24-.07 1.61-.07 4.76s.01 3.52.07 4.76c.05 1.15.24 1.77.4 2.18.22.55.47.94.88 1.35.41.41.8.66 1.35.88.41.16 1.03.35 2.18.4 1.24.06 1.61.07 4.76.07s3.52-.01 4.76-.07c1.15-.05 1.77-.24 2.18-.4.55-.22.94-.47 1.35-.88.41-.41.66-.8.88-1.35.16-.41.35-1.03.4-2.18.06-1.24.07-1.61.07-4.76s-.01-3.52-.07-4.76c-.05-1.15-.24-1.77-.4-2.18a3.6 3.6 0 00-.88-1.35 3.6 3.6 0 00-1.35-.88c-.41-.16-1.03-.35-2.18-.4-1.24-.06-1.61-.07-4.76-.07zm0 2.76a5.46 5.46 0 110 10.92 5.46 5.46 0 010-10.92zm0 9a3.54 3.54 0 100-7.08 3.54 3.54 0 000 7.08zm6.95-9.22a1.27 1.27 0 11-2.55 0 1.27 1.27 0 012.55 0z" /></svg>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Map preview -->
                                <div class="rounded-2xl bg-gradient-to-br from-cyan-50 to-sky-50 dark:from-cyan-950/40 dark:to-sky-950/40 border-2 border-cyan-100 dark:border-cyan-800 p-5 transition-colors duration-300">
                                    <h4 class="text-base font-bold text-cyan-900 dark:text-cyan-200 flex items-center gap-2 mb-3">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                        </svg>
                                        Find Us
                                    </h4>
                                    <div v-if="form.google_maps_url" class="rounded-xl overflow-hidden border border-cyan-200 dark:border-cyan-800 bg-white dark:bg-slate-800 h-64 sm:h-full min-h-[16rem]">
                                        <iframe
                                            :src="form.google_maps_url"
                                            class="w-full h-full"
                                            style="border:0; min-height: 16rem;"
                                            loading="lazy"
                                        ></iframe>
                                    </div>
                                    <div v-else class="flex flex-col items-center justify-center h-64 sm:h-full min-h-[16rem] rounded-xl border-2 border-dashed border-cyan-200 dark:border-cyan-800 bg-white/60 dark:bg-slate-800/40 text-center px-4">
                                        <svg class="h-12 w-12 text-cyan-300 dark:text-cyan-700 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                        </svg>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">Google Maps URL dile ekhane map show korbe</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============================================= -->
                    <!-- ACTION BUTTONS -->
                    <!-- ============================================= -->
                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-2 sm:gap-3 rounded-2xl border border-slate-100 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 p-3 sm:p-4 shadow-xl shadow-slate-200/50 dark:shadow-black/40 backdrop-blur-md transition-colors duration-300">
                        <Link
                            :href="route('website-management.settings')"
                            class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-2.5 text-sm font-semibold text-slate-600 dark:text-slate-300 shadow-sm transition hover:border-slate-300 dark:hover:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 hover:text-slate-800 dark:hover:text-slate-100 text-center"
                        >
                            Cancel
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="group relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-7 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/30 transition hover:from-emerald-700 hover:to-teal-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <svg v-else class="h-4 w-4 transition group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
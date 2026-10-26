<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

const props = defineProps({
    isAdmin:         { type: Boolean, default: false },
    user:            { type: Object,  default: () => ({}) },
    selectedYear:    { type: Number,  default: () => new Date().getFullYear() },
    currentYear:     { type: Number,  default: () => new Date().getFullYear() },
    availableYears:  { type: Array,   default: () => [] },
    stats:           { type: Object,  default: () => ({}) },
    monthlyFees:     { type: Array,   default: () => [] },
    studentsByClass: { type: Array,   default: () => [] },
    recentStudents:  { type: Array,   default: () => [] },
    recentPayments:  { type: Array,   default: () => [] },
    recentNotices:   { type: Array,   default: () => [] },
    upcomingEvents:  { type: Array,   default: () => [] },
    topTeachers:     { type: Array,   default: () => [] },
    recentActivities:{ type: Array,   default: () => [] },
    feeSummary:      { type: Array,   default: () => [] },
})

const page = usePage()
const school = computed(() => page.props.school ?? {})

// ============================================================
// YEAR SELECTOR
// ============================================================
const yearInput    = ref(props.selectedYear)
const yearDropdown = ref(false)

watch(() => props.selectedYear, (y) => {
    yearInput.value = y
})

const yearSuggestions = computed(() => {
    const list = [...(props.availableYears ?? [])]
    if (!list.includes(props.currentYear)) list.push(props.currentYear)
    return list.map(Number).sort((a, b) => b - a)
})

const filteredYearSuggestions = computed(() => {
    if (!yearInput.value) return yearSuggestions.value
    const v = String(yearInput.value).trim()
    return yearSuggestions.value.filter(y => String(y).includes(v))
})

const applyYear = (year = null) => {
    const targetYear = parseInt(year ?? yearInput.value)
    if (!targetYear || targetYear < 2000 || targetYear > 2100) {
        yearInput.value = props.selectedYear
        yearDropdown.value = false
        return
    }
    yearDropdown.value = false
    if (targetYear === props.selectedYear) return

    router.get(route('dashboard'), { year: targetYear }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

const selectYear = (year) => {
    yearInput.value = year
    applyYear(year)
}

const decrementYear = () => {
    yearInput.value = parseInt(yearInput.value || props.selectedYear) - 1
    applyYear()
}

const incrementYear = () => {
    yearInput.value = parseInt(yearInput.value || props.selectedYear) + 1
    applyYear()
}

// ============================================================
// HELPERS
// ============================================================
const storageUrl = (path) => {
    if (!path) return null
    if (path.startsWith('http')) return path
    return `/storage/${path.replace(/^\/+/, '')}`
}

const formatMoney = (n) =>
    new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0))

const formatMoneyShort = (n) =>
    new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Number(n || 0))

const formatDate = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

const formatDateTime = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const eventDay = (d) => !d ? '' : String(new Date(d).getDate()).padStart(2, '0')
const eventMonth = (d) => !d ? '' : new Date(d).toLocaleDateString('en-GB', { month: 'short' })

// Current date/time for top bar
const now = new Date()
const todayLabel = now.toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
const timeLabel = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })

// ============================================================
// STAT CARDS (6 cards — Net Payable added)
// ============================================================
const statCards = computed(() => [
    {
        key: 'classes',
        label: 'Total Classes',
        value: props.stats.totalClasses ?? 0,
        badge: (props.stats.activeClasses ?? 0) + ' active',
        icon: 'classes',
        gradient: 'from-blue-500 to-indigo-600',
        glow: 'shadow-blue-500/30',
        bg: 'from-blue-50 to-indigo-50/50 dark:from-blue-950/30 dark:to-indigo-950/20',
        ring: 'ring-blue-200/60 dark:ring-blue-800/40',
        text: 'text-blue-600 dark:text-blue-400',
        href: '/classes',
    },
    {
        key: 'students',
        label: 'Total Students',
        value: props.stats.totalStudents ?? 0,
        badge: (props.stats.studentsThisMonth ?? 0) + ' new this month',
        icon: 'students',
        gradient: 'from-emerald-500 to-teal-600',
        glow: 'shadow-emerald-500/30',
        bg: 'from-emerald-50 to-teal-50/50 dark:from-emerald-950/30 dark:to-teal-950/20',
        ring: 'ring-emerald-200/60 dark:ring-emerald-800/40',
        text: 'text-emerald-600 dark:text-emerald-400',
        href: '/students',
    },
    {
        key: 'teachers',
        label: 'Total Teachers',
        value: props.stats.totalTeachers ?? 0,
        badge: (props.stats.teachersThisMonth ?? 0) + ' new this month',
        icon: 'teachers',
        gradient: 'from-purple-500 to-fuchsia-600',
        glow: 'shadow-purple-500/30',
        bg: 'from-purple-50 to-fuchsia-50/50 dark:from-purple-950/30 dark:to-fuchsia-950/20',
        ring: 'ring-purple-200/60 dark:ring-purple-800/40',
        text: 'text-purple-600 dark:text-purple-400',
        href: '/teachers',
    },
    {
        key: 'total_fees',
        label: 'Total Fees',
        value: '৳ ' + formatMoneyShort(props.stats.currentYearFeeTotal ?? 0),
        badge: props.selectedYear + ' yearly',
        icon: 'money',
        gradient: 'from-indigo-500 to-violet-600',
        glow: 'shadow-indigo-500/30',
        bg: 'from-indigo-50 to-violet-50/50 dark:from-indigo-950/30 dark:to-violet-950/20',
        ring: 'ring-indigo-200/60 dark:ring-indigo-800/40',
        text: 'text-indigo-600 dark:text-indigo-400',
        href: '/fees',
    },
    {
        key: 'net_payable',
        label: 'Net Payable',
        value: '৳ ' + formatMoneyShort(props.stats.netPayable ?? 0),
        badge: (props.stats.discountedStudents ?? 0) + ' got discount',
        icon: 'net',
        gradient: 'from-rose-500 to-pink-600',
        glow: 'shadow-rose-500/30',
        bg: 'from-rose-50 to-pink-50/50 dark:from-rose-950/30 dark:to-pink-950/20',
        ring: 'ring-rose-200/60 dark:ring-rose-800/40',
        text: 'text-rose-600 dark:text-rose-400',
        href: '/fees',
    },
    {
        key: 'fees',
        label: 'Fee Collection',
        value: '৳ ' + formatMoneyShort(props.stats.totalFeesCollected ?? 0),
        badge: (props.stats.paymentsToday ?? 0) + ' today',
        icon: 'collection',
        gradient: 'from-amber-500 to-orange-600',
        glow: 'shadow-amber-500/30',
        bg: 'from-amber-50 to-orange-50/50 dark:from-amber-950/30 dark:to-orange-950/20',
        ring: 'ring-amber-200/60 dark:ring-amber-800/40',
        text: 'text-amber-600 dark:text-amber-400',
        href: '/fees',
    },
])

const statIcons = {
    classes:    'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    students:   'M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4',
    teachers:   'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    money:      'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    collection: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    net:        'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z',
}

// ============================================================
// LINE CHART — SVG polygon
// ============================================================
const chartData = computed(() => {
    if (!props.monthlyFees.length) return []
    return props.monthlyFees.map((m, i) => ({
        x: i,
        label: m.label || '',
        value: Number(m.total || 0),
    }))
})

const chartMax = computed(() => {
    if (!chartData.value.length) return 1
    const max = Math.max(...chartData.value.map(d => d.value))
    return max > 0 ? max * 1.2 : 1
})

const chartPoints = computed(() => {
    if (!chartData.value.length) return ''
    const width = 100
    const height = 100
    const stepX = chartData.value.length > 1 ? width / (chartData.value.length - 1) : width
    return chartData.value
        .map((d, i) => {
            const x = i * stepX
            const y = height - (d.value / chartMax.value) * height
            return `${x},${y}`
        })
        .join(' ')
})

const chartAreaPoints = computed(() => {
    if (!chartPoints.value) return ''
    return `0,100 ${chartPoints.value} 100,100`
})

const chartYAxis = computed(() => {
    const max = chartMax.value
    const steps = 4
    const arr = []
    for (let i = steps; i >= 0; i--) {
        arr.push(Math.round((max / steps) * i))
    }
    return arr
})

// ============================================================
// CLASS OVERVIEW
// ============================================================
const classOverview = computed(() => props.studentsByClass.map((c, i) => ({
    ...c,
    status: c.status ?? 'Active',
    color: ['bg-blue-500', 'bg-orange-500', 'bg-amber-500', 'bg-purple-500', 'bg-emerald-500', 'bg-pink-500'][i % 6],
})))

// ============================================================
// RECENT ACTIVITIES (icons)
// ============================================================
const activityIcons = {
    payment:  'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    notice:   'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z',
    class:    'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    teacher:  'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    student:  'M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4',
    event:    'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
}

const activityColors = {
    payment: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
    notice:  'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400',
    class:   'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
    teacher: 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400',
    student: 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400',
    event:   'bg-pink-100 text-pink-600 dark:bg-pink-900/30 dark:text-pink-400',
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-100/70 dark:bg-slate-950 transition-colors duration-300">

            <div class="mx-auto max-w-[1600px] px-3 xs:px-4 sm:px-6 lg:px-8 py-4 sm:py-6 lg:py-8 space-y-4 sm:space-y-6">

                <!-- ==================== WELCOME HEADER ==================== -->
                <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4 sm:gap-5 pb-4 sm:pb-5 border-b border-slate-200 dark:border-slate-800">

                    <!-- Welcome text -->
                    <div class="min-w-0 flex-1">
                        <h1 class="text-xl sm:text-2xl lg:text-[28px] font-semibold tracking-tight text-slate-900 dark:text-slate-50 leading-tight">
                            Welcome back, {{ user.name || 'Admin' }}
                        </h1>
                        <p class="mt-1 text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                            Here's what's happening at your school today.
                        </p>
                    </div>

                    <!-- Controls -->
                    <div class="flex flex-col xs:flex-row items-stretch xs:items-center gap-2.5 sm:gap-3">

                        <!-- ✅ YEAR SELECTOR -->
                        <div class="relative" @focusout="setTimeout(() => yearDropdown = false, 200)">
                            <div class="flex items-center gap-0.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-1.5 py-1 shadow-sm focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/20 transition">

                                <button
                                    type="button"
                                    @click="decrementYear"
                                    class="flex h-7 w-7 items-center justify-center rounded-md text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 active:scale-95 transition"
                                    title="Previous year"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </button>

                                <input
                                    v-model="yearInput"
                                    @focus="yearDropdown = true"
                                    @keydown.enter.prevent="applyYear()"
                                    @keydown.esc="yearDropdown = false"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    maxlength="4"
                                    class="w-14 sm:w-16 text-center text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 bg-transparent border-0 focus:ring-0 outline-none tracking-wider tabular-nums"
                                    title="Select year"
                                />

                                <button
                                    type="button"
                                    @click="yearDropdown = !yearDropdown"
                                    class="flex h-6 w-6 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                                    title="Choose year"
                                >
                                    <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="yearDropdown ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>

                                <button
                                    type="button"
                                    @click="incrementYear"
                                    class="flex h-7 w-7 items-center justify-center rounded-md text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 active:scale-95 transition"
                                    title="Next year"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Dropdown -->
                            <div
                                v-if="yearDropdown && filteredYearSuggestions.length > 0"
                                class="absolute right-0 top-full mt-2 w-44 z-30 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-xl overflow-hidden"
                            >
                                <div class="max-h-60 overflow-y-auto py-1">
                                    <button
                                        v-for="y in filteredYearSuggestions"
                                        :key="y"
                                        type="button"
                                        @mousedown.prevent="selectYear(y)"
                                        class="w-full text-left px-4 py-2.5 text-sm font-medium transition-colors flex items-center justify-between gap-2"
                                        :class="y === selectedYear
                                            ? 'bg-indigo-600 text-white'
                                            : 'text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800'"
                                    >
                                        <span class="tabular-nums">{{ y }}</span>
                                        <span
                                            v-if="y === currentYear"
                                            class="text-[9px] px-1.5 py-0.5 rounded font-semibold shrink-0"
                                            :class="y === selectedYear
                                                ? 'bg-white/25 text-white'
                                                : 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300'"
                                        >
                                            Current
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Date/time card -->
                        <div class="flex items-center gap-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-3 sm:px-4 py-2 shadow-sm">
                            <span class="flex h-8 w-8 items-center justify-center rounded-md bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 shrink-0">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] sm:text-xs font-semibold text-slate-800 dark:text-slate-100 truncate">{{ todayLabel }}</p>
                                <p class="text-[10px] sm:text-[11px] text-slate-500 dark:text-slate-400">{{ timeLabel }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== STAT CARDS (6) ==================== -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-6 gap-2.5 sm:gap-3 lg:gap-4">
                    <Link
                        v-for="card in statCards"
                        :key="card.key"
                        :href="card.href"
                        class="group relative overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3.5 sm:p-4 lg:p-5 shadow-sm transition duration-200 hover:border-slate-300 dark:hover:border-slate-700 hover:shadow-md"
                    >
                        <!-- Top accent line -->
                        <div :class="['absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r', card.gradient]"></div>

                        <div class="flex items-start justify-between gap-2">
                            <!-- Label -->
                            <p class="text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400 leading-snug">
                                {{ card.label }}
                            </p>

                            <!-- Icon -->
                            <div :class="['flex h-8 w-8 sm:h-9 sm:w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br text-white', card.gradient]">
                                <svg class="h-4 w-4 sm:h-[18px] sm:w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="statIcons[card.icon]" />
                                </svg>
                            </div>
                        </div>

                        <!-- Value -->
                        <p class="mt-3 sm:mt-4 text-lg sm:text-xl lg:text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-50 tabular-nums truncate">
                            {{ card.value }}
                        </p>

                        <!-- Badge -->
                        <div :class="['mt-2 pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center gap-1 text-[10px] sm:text-[11px] font-medium', card.text]">
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                            </svg>
                            <span class="truncate">{{ card.badge }}</span>
                        </div>
                    </Link>
                </div>

                <!-- ✅ Financial Summary Bar -->
                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
                    <div class="grid grid-cols-1 xs:grid-cols-3 divide-y xs:divide-y-0 xs:divide-x divide-slate-200 dark:divide-slate-800">
                        <!-- Total Fee -->
                        <div class="flex items-center gap-3 px-4 sm:px-5 py-3 sm:py-4 xs:justify-center">
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <div class="min-w-0">
                                <p class="text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400">Total Fee</p>
                                <p class="text-sm sm:text-base font-semibold text-slate-900 dark:text-slate-100 tabular-nums truncate">
                                    ৳ {{ formatMoney(props.stats.currentYearFeeTotal ?? 0) }}
                                </p>
                            </div>
                        </div>

                        <!-- Total Discount -->
                        <div class="flex items-center gap-3 px-4 sm:px-5 py-3 sm:py-4 xs:justify-center">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-500 shrink-0"></span>
                            <div class="min-w-0">
                                <p class="text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400">Total Discount</p>
                                <p class="text-sm sm:text-base font-semibold text-rose-600 dark:text-rose-400 tabular-nums truncate">
                                    − ৳ {{ formatMoney(props.stats.totalDiscount ?? 0) }}
                                </p>
                            </div>
                        </div>

                        <!-- Net Payable -->
                        <div class="flex items-center gap-3 px-4 sm:px-5 py-3 sm:py-4 xs:justify-center">
                            <span class="h-2.5 w-2.5 rounded-full bg-indigo-500 shrink-0"></span>
                            <div class="min-w-0">
                                <p class="text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400">Net Payable</p>
                                <p class="text-sm sm:text-base font-semibold text-indigo-600 dark:text-indigo-400 tabular-nums truncate">
                                    ৳ {{ formatMoney(props.stats.netPayable ?? 0) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== FEE CHART + CLASS OVERVIEW ==================== -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-5">

                    <!-- Fee Collection Overview -->
                    <div class="lg:col-span-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">
                                        Fee Collection Overview
                                    </h3>
                                    <p class="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400">
                                        {{ selectedYear }} yearly trend
                                    </p>
                                </div>
                            </div>

                            <select class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 sm:px-3 py-1.5 text-[11px] sm:text-xs font-medium text-slate-600 dark:text-slate-300 outline-none hover:border-indigo-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition">
                                <option>This Month</option>
                                <option>This Year</option>
                            </select>
                        </div>

                        <div class="p-4 sm:p-5 lg:p-6">
                            <div v-if="chartData.length" class="relative h-48 sm:h-56 lg:h-64 w-full">
                                <div class="absolute left-0 top-0 bottom-8 flex flex-col justify-between text-[9px] sm:text-[10px] text-slate-400 dark:text-slate-500 font-medium pr-2 tabular-nums">
                                    <span v-for="(v, i) in chartYAxis" :key="i">{{ formatMoneyShort(v) }}</span>
                                </div>

                                <div class="ml-10 sm:ml-12 mr-1 sm:mr-2 h-[calc(100%-2rem)] relative">
                                    <div class="absolute inset-0 flex flex-col justify-between">
                                        <div v-for="n in 5" :key="n" class="border-t border-dashed border-slate-200/80 dark:border-slate-800"></div>
                                    </div>

                                    <svg class="absolute inset-0 w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                                        <defs>
                                            <linearGradient id="feeAreaGrad" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="#4F46E5" stop-opacity="0.22" />
                                                <stop offset="100%" stop-color="#4F46E5" stop-opacity="0" />
                                            </linearGradient>
                                            <linearGradient id="feeLineGrad" x1="0" y1="0" x2="1" y2="0">
                                                <stop offset="0%" stop-color="#4F46E5" />
                                                <stop offset="100%" stop-color="#6366F1" />
                                            </linearGradient>
                                        </defs>
                                        <polygon :points="chartAreaPoints" fill="url(#feeAreaGrad)" />
                                        <polyline
                                            :points="chartPoints"
                                            fill="none"
                                            stroke="url(#feeLineGrad)"
                                            stroke-width="0.9"
                                            vector-effect="non-scaling-stroke"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>

                                    <svg class="absolute inset-0 w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                                        <circle
                                            v-for="(d, i) in chartData"
                                            :key="i"
                                            :cx="chartData.length > 1 ? (i * (100 / (chartData.length - 1))) : 50"
                                            :cy="100 - (d.value / chartMax) * 100"
                                            r="0.7"
                                            fill="#4F46E5"
                                            stroke="white"
                                            stroke-width="0.3"
                                            vector-effect="non-scaling-stroke"
                                        />
                                    </svg>

                                    <div class="absolute left-0 right-0 -bottom-6 flex justify-between text-[9px] sm:text-[10px] text-slate-400 dark:text-slate-500 font-medium">
                                        <span v-for="d in chartData" :key="d.label" class="truncate">{{ d.label }}</span>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="h-48 sm:h-56 lg:h-64 flex flex-col items-center justify-center text-center">
                                <svg class="h-10 w-10 sm:h-12 sm:w-12 text-slate-300 dark:text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2z" />
                                </svg>
                                <p class="text-xs sm:text-sm text-slate-400 dark:text-slate-500">No fee data yet</p>
                            </div>
                        </div>
                    </div>

                    <!-- Class Wise Overview -->
                    <div class="lg:col-span-1 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between gap-2 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 dark:bg-violet-500/10 text-violet-600 dark:text-violet-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                </span>
                                <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">Class Overview</h3>
                            </div>
                            <Link href="/classes" class="text-[11px] sm:text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline shrink-0">
                                View All
                            </Link>
                        </div>

                        <div v-if="classOverview.length" class="overflow-x-auto">
                            <table class="w-full text-[11px] sm:text-xs min-w-[300px]">
                                <thead>
                                    <tr class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                                        <th class="py-2.5 px-4 text-left font-semibold text-slate-500 dark:text-slate-400">Class</th>
                                        <th class="py-2.5 px-2 text-center font-semibold text-slate-500 dark:text-slate-400">Section</th>
                                        <th class="py-2.5 px-2 text-center font-semibold text-slate-500 dark:text-slate-400">Students</th>
                                        <th class="py-2.5 px-4 text-right font-semibold text-slate-500 dark:text-slate-400">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in classOverview" :key="c.id" class="border-b border-slate-100 dark:border-slate-800/60 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                        <td class="py-2.5 px-4">
                                            <div class="flex items-center gap-2">
                                                <span :class="['flex h-5 w-5 shrink-0 items-center justify-center rounded text-[9px] font-bold text-white', c.color]">
                                                    {{ c.id }}
                                                </span>
                                                <span class="font-semibold text-slate-800 dark:text-slate-100 truncate">
                                                    {{ c.class_name }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-2 text-center text-slate-500 dark:text-slate-400">
                                            {{ c.section || '—' }}
                                        </td>
                                        <td class="py-2.5 px-2 text-center font-semibold text-slate-700 dark:text-slate-300 tabular-nums">
                                            {{ c.count || '—' }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right">
                                            <span class="inline-flex items-center rounded-md bg-emerald-50 dark:bg-emerald-900/30 px-1.5 sm:px-2 py-0.5 text-[9px] sm:text-[10px] font-semibold text-emerald-700 dark:text-emerald-400 ring-1 ring-inset ring-emerald-200 dark:ring-emerald-800">
                                                {{ c.status }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="py-10 text-center text-xs text-slate-400 dark:text-slate-500">No classes for {{ selectedYear }}</div>
                    </div>
                </div>

                <!-- ==================== RECENT PAYMENTS + LATEST NOTICES ==================== -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5">

                    <!-- Recent Fee Payments -->
                    <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between gap-2 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                                    </svg>
                                </span>
                                <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">Recent Fee Payments</h3>
                            </div>
                            <Link href="/fees" class="text-[11px] sm:text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline shrink-0">
                                View All
                            </Link>
                        </div>

                        <div v-if="recentPayments.length" class="overflow-x-auto">
                            <table class="w-full text-[11px] sm:text-xs min-w-[400px]">
                                <thead>
                                    <tr class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                                        <th class="py-2.5 px-4 text-left font-semibold text-slate-500 dark:text-slate-400">Student</th>
                                        <th class="py-2.5 px-2 text-left font-semibold text-slate-500 dark:text-slate-400">Class</th>
                                        <th class="py-2.5 px-2 text-right font-semibold text-slate-500 dark:text-slate-400">Amount</th>
                                        <th class="py-2.5 px-4 text-right font-semibold text-slate-500 dark:text-slate-400">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in recentPayments" :key="p.id" class="border-b border-slate-100 dark:border-slate-800/60 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                        <td class="py-2.5 px-4">
                                            <div class="flex items-center gap-2.5">
                                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 text-[10px] font-bold">
                                                    <span>{{ (p.student_name || '?').charAt(0).toUpperCase() }}</span>
                                                </div>
                                                <span class="font-semibold text-slate-800 dark:text-slate-100 truncate">
                                                    {{ p.student_name }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 px-2 text-slate-500 dark:text-slate-400">
                                            {{ p.class || '—' }}
                                        </td>
                                        <td class="py-2.5 px-2 text-right font-semibold text-emerald-600 dark:text-emerald-400 tabular-nums whitespace-nowrap">
                                            ৳ {{ formatMoney(p.amount) }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                            {{ formatDate(p.paid_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="py-12 text-center text-xs text-slate-400 dark:text-slate-500">No payments for {{ selectedYear }}</div>
                    </div>

                    <!-- Latest Notices -->
                    <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between gap-2 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                    </svg>
                                </span>
                                <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">Latest Notices</h3>
                            </div>
                            <Link href="/notices" class="text-[11px] sm:text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline shrink-0">
                                View All
                            </Link>
                        </div>

                        <div v-if="recentNotices.length" class="p-2 sm:p-3 divide-y divide-slate-100 dark:divide-slate-800/70">
                            <a
                                v-for="(n, i) in recentNotices.slice(0, 4)"
                                :key="n.id"
                                :href="`/notices/${n.id}`"
                                class="flex items-start gap-3 group px-2.5 py-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <span :class="[
                                    'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                    ['bg-blue-500', 'bg-orange-500', 'bg-emerald-500', 'bg-purple-500'][i % 4]
                                ]"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-xs sm:text-[13px] font-semibold text-slate-800 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 line-clamp-1 transition-colors">
                                            {{ n.title }}
                                        </p>
                                        <span class="shrink-0 text-[10px] sm:text-[11px] text-slate-400 dark:text-slate-500 whitespace-nowrap">
                                            {{ formatDate(n.notice_date) }}
                                        </span>
                                    </div>
                                    <p v-if="n.description" class="mt-0.5 text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 line-clamp-1">
                                        {{ n.description }}
                                    </p>
                                </div>
                            </a>
                        </div>

                        <div v-else class="py-12 text-center text-xs text-slate-400 dark:text-slate-500">No notices yet</div>
                    </div>
                </div>

                <!-- ==================== EVENTS + FEE STRUCTURE + ACTIVITIES ==================== -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">

                    <!-- Upcoming Events -->
                    <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between gap-2 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </span>
                                <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">Upcoming Events</h3>
                            </div>
                            <Link href="/events" class="text-[11px] sm:text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline shrink-0">
                                View All
                            </Link>
                        </div>

                        <div v-if="upcomingEvents.length" class="p-3 sm:p-4 space-y-2">
                            <div
                                v-for="e in upcomingEvents.slice(0, 4)"
                                :key="e.id"
                                class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-100 dark:border-slate-800 hover:border-indigo-200 dark:hover:border-indigo-800 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors cursor-pointer"
                            >
                                <div class="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20">
                                    <span class="text-sm font-bold leading-none text-indigo-700 dark:text-indigo-300">{{ eventDay(e.event_date) }}</span>
                                    <span class="mt-0.5 text-[9px] font-semibold text-indigo-500 dark:text-indigo-400">
                                        {{ eventMonth(e.event_date) }}
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs sm:text-[13px] font-semibold text-slate-800 dark:text-slate-100 truncate">
                                        {{ e.title }}
                                    </p>
                                    <p v-if="e.location" class="mt-0.5 text-[10px] sm:text-[11px] text-slate-500 dark:text-slate-400 truncate flex items-center gap-1">
                                        <svg class="h-2.5 w-2.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        {{ e.location }}
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-md bg-emerald-50 dark:bg-emerald-900/30 px-1.5 sm:px-2 py-0.5 text-[9px] font-semibold text-emerald-700 dark:text-emerald-400 ring-1 ring-inset ring-emerald-200 dark:ring-emerald-800">
                                    Upcoming
                                </span>
                            </div>
                        </div>

                        <div v-else class="py-12 text-center text-xs text-slate-400 dark:text-slate-500">No upcoming events</div>
                    </div>

                    <!-- Fee Structure Summary -->
                    <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between gap-2 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">Fee Structure</h3>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ selectedYear }}</p>
                                </div>
                            </div>
                            <Link href="/fees" class="text-[11px] sm:text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline shrink-0">
                                View All
                            </Link>
                        </div>

                        <div v-if="feeSummary.length" class="overflow-x-auto">
                            <table class="w-full text-[11px] sm:text-xs min-w-[280px]">
                                <thead>
                                    <tr class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                                        <th class="py-2.5 px-4 text-left font-semibold text-slate-500 dark:text-slate-400">Fee Name</th>
                                        <th class="py-2.5 px-2 text-right font-semibold text-slate-500 dark:text-slate-400">Amount</th>
                                        <th class="py-2.5 px-4 text-right font-semibold text-slate-500 dark:text-slate-400">Cycle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(f, i) in feeSummary.slice(0, 5)" :key="i" class="border-b border-slate-100 dark:border-slate-800/60 last:border-0 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                                        <td class="py-2.5 px-4 font-semibold text-slate-800 dark:text-slate-100 truncate max-w-[120px]">
                                            {{ f.fee_name }}
                                        </td>
                                        <td class="py-2.5 px-2 text-right font-semibold text-slate-700 dark:text-slate-300 tabular-nums whitespace-nowrap">
                                            ৳ {{ formatMoney(f.amount) }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right">
                                            <span class="inline-flex items-center rounded-md bg-indigo-50 dark:bg-indigo-900/30 px-1.5 sm:px-2 py-0.5 text-[9px] sm:text-[10px] font-semibold text-indigo-700 dark:text-indigo-300 capitalize whitespace-nowrap">
                                                {{ f.cycle }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="py-12 text-center text-xs text-slate-400 dark:text-slate-500">No fee structures for {{ selectedYear }}</div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm md:col-span-2 lg:col-span-1">
                        <div class="flex items-center justify-between gap-2 px-4 sm:px-5 py-3.5 sm:py-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 shrink-0">
                                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                </span>
                                <h3 class="text-sm sm:text-[15px] font-semibold text-slate-900 dark:text-slate-100 truncate">Recent Activities</h3>
                            </div>
                            <Link href="/activities" class="text-[11px] sm:text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 hover:underline shrink-0">
                                View All
                            </Link>
                        </div>

                        <div v-if="recentActivities.length" class="p-2 sm:p-3 divide-y divide-slate-100 dark:divide-slate-800/70">
                            <div
                                v-for="(a, i) in recentActivities.slice(0, 5)"
                                :key="i"
                                class="flex items-start gap-3 px-2.5 py-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', activityColors[a.type] || activityColors.class]">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" :d="activityIcons[a.type] || activityIcons.class" />
                                    </svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs sm:text-[13px] font-semibold text-slate-800 dark:text-slate-100 truncate">
                                        {{ a.title }}
                                    </p>
                                    <p class="text-[10px] sm:text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                        {{ a.subtitle }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-[10px] sm:text-[11px] text-slate-400 dark:text-slate-500 whitespace-nowrap pt-0.5">
                                    {{ a.time }}
                                </span>
                            </div>
                        </div>

                        <div v-else class="py-12 text-center text-xs text-slate-400 dark:text-slate-500">No activities yet</div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
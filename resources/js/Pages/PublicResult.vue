<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ref, computed, onMounted, watch } from 'vue'
import PublicNavbar from '@/Components/PublicNavbar.vue'
import PublicFooter from '@/Components/PublicFooter.vue'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
    hero:        { type: Object, default: null },
    classes:     { type: Array,  default: () => [] },
    years:       { type: Array,  default: () => [] },
    currentYear: { type: Number, default: () => new Date().getFullYear() },
})

const page = usePage()
const school = computed(() => page.props.school ?? {})
const contact = computed(() => page.props.contact ?? {})
const heroShared = computed(() => page.props.hero ?? {})

const { initTheme } = useTheme()

// ============================================================
// FOOTER DATA
// ============================================================
const year = new Date().getFullYear()

const quickLinks = [
    { name: 'Home',     href: '/' },
    { name: 'About',    href: '/about' },
    { name: 'Result',   href: '/result' },
    { name: 'Teachers', href: '/teachers' },
    { name: 'Contact',  href: '/contact' },
]

// ============================================================
// STORAGE URL HELPER
// ============================================================
const storageUrl = (path) => {
    if (!path) return null
    if (path.startsWith('http')) return path
    return `/storage/${path.replace(/^\/+/, '')}`
}

// ============================================================
// HERO IMAGE
// ============================================================
const heroImage = computed(() => {
    if (props.hero?.hero_image) return storageUrl(props.hero.hero_image)
    if (heroShared.value?.image) return storageUrl(heroShared.value.image)
    return 'https://images.unsplash.com/photo-1544717297-fa95b6ee9643?w=1600&q=80'
})

const heroImageAlt = computed(() =>
    props.hero?.hero_image_alt || heroShared.value?.image_alt || 'Result'
)

// ============================================================
// FORM STATE
// ============================================================
const form = ref({
    student_id: '',
    class_name: '',
    year:       props.currentYear.toString(),
    exam:       '',
    section:    'all',
})

const classNames = computed(() => {
    const names = props.classes.map(c => c.class_name).filter(Boolean)
    return [...new Set(names)].sort((a, b) => {
        const na = parseInt(a.replace(/\D/g, ''), 10) || 0
        const nb = parseInt(b.replace(/\D/g, ''), 10) || 0
        return na - nb
    })
})

const availableSections = computed(() => {
    if (!form.value.class_name) return []
    const sections = props.classes
        .filter(c => c.class_name === form.value.class_name && c.section)
        .map(c => c.section)
    return [...new Set(sections)]
})

const resolveClassId = () => {
    const name = form.value.class_name
    const sec  = (form.value.section && form.value.section !== 'all') ? form.value.section : null

    if (sec) {
        const match = props.classes.find(c => c.class_name === name && c.section === sec)
        if (match) return match.id
    }

    const fallback = props.classes.find(c => c.class_name === name)
    return fallback?.id || null
}

watch(() => form.value.class_name, () => {
    form.value.section = 'all'
})

const yearOptions = computed(() => {
    const list = [...props.years]
    if (!list.includes(props.currentYear)) list.push(props.currentYear)
    return list.sort((a, b) => b - a)
})

// ============================================================
// RESULT STATE
// ============================================================
const loading    = ref(false)
const errorMsg   = ref('')
const resultData = ref(null)
const showResult = computed(() => !!resultData.value)

onMounted(() => {
    initTheme()
    form.value.section = 'all'
})

const submitResult = async () => {
    errorMsg.value = ''

    if (!form.value.student_id || !form.value.class_name || !form.value.year || !form.value.exam) {
        errorMsg.value = 'Please fill in all required fields.'
        return
    }

    const classId = resolveClassId()
    if (!classId) {
        errorMsg.value = 'Invalid class selected. Please try again.'
        return
    }

    loading.value = true
    resultData.value = null

    try {
        const sectionValue = (form.value.section && form.value.section !== 'all')
            ? form.value.section
            : null

        const yearValue = String(form.value.year).trim()

        const res = await fetch(route('result.fetch'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                student_id: String(form.value.student_id).trim(),
                class_id:   classId,
                year:       yearValue,
                exam:       String(form.value.exam),
                section:    sectionValue,
            }),
        })

        const data = await res.json()

        if (!res.ok || !data.success) {
            errorMsg.value = data.message || 'Failed to fetch result.'
            return
        }

        resultData.value = data
    } catch (e) {
        errorMsg.value = 'Network error. Please try again.'
        console.error(e)
    } finally {
        loading.value = false
    }
}

const gradeClass = (grade) => {
    if (!grade) return 'bg-slate-100 text-slate-600'
    const g = String(grade).toUpperCase()
    if (g === 'A+') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
    if (g === 'A')  return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
    if (g === 'A-') return 'bg-lime-100 text-lime-700 dark:bg-lime-900/30 dark:text-lime-400'
    if (g === 'B')  return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
    if (g === 'C')  return 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400'
    if (g === 'D')  return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
    return 'bg-red-200 text-red-800 dark:bg-red-900/40 dark:text-red-400'
}

const totalFullMarks = computed(() =>
    resultData.value?.subjects?.reduce((s, x) => s + Number(x.full || 0), 0) || 0
)
const totalObtained = computed(() =>
    resultData.value?.subjects?.reduce((s, x) => s + Number(x.obtained || 0), 0) || 0
)

// ============================================================
// PRINT HANDLER
// ============================================================
const printResult = () => {
    document.body.classList.add('printing-result')
    setTimeout(() => {
        window.print()
        setTimeout(() => {
            document.body.classList.remove('printing-result')
        }, 500)
    }, 150)
}

const resetSearch = () => {
    form.value = {
        student_id: '',
        class_name: '',
        year:       props.currentYear.toString(),
        exam:       '',
        section:    'all',
    }
    resultData.value = null
    errorMsg.value = ''
}

// ============================================================
// PRINT HELPERS
// ============================================================
const gradeGpa = (grade) => {
    const g = String(grade || '').toUpperCase()
    const map = { 'A+': '5.00', 'A': '4.00', 'A-': '3.50', 'B': '3.00', 'C': '2.00', 'D': '1.00', 'F': '0.00' }
    return map[g] || '—'
}

const gradeColor = (grade) => {
    const g = String(grade || '').toUpperCase()
    if (g === 'A+') return '#047857'
    if (g === 'A')  return '#15803d'
    if (g === 'A-') return '#4d7c0f'
    if (g === 'B')  return '#a16207'
    if (g === 'C')  return '#c2410c'
    if (g === 'D')  return '#be123c'
    if (g === 'F')  return '#b91c1c'
    return '#475569'
}

const getStudentId = (s) => s?.student_id || s?.id || '—'
const getStudentName = (s) => s?.name || s?.student_name || '—'
const getFatherName = (s) => s?.father_name || s?.fatherName || '—'
const getMotherName = (s) => s?.mother_name || s?.motherName || '—'
const getClassName = (s) => s?.class_name || s?.class || '—'
const getSection = (s) => s?.section || '—'
const getRoll = (s) => s?.roll || s?.roll_number || '—'
const getDob = (s) => s?.dob || s?.date_of_birth || '—'
</script>

<template>
    <Head :title="`Check Result — ${school?.name ?? 'School'}`" />

    <div class="relative min-h-screen w-full text-slate-800 dark:text-slate-100 transition-colors duration-300">

        <!-- FULL-PAGE BACKGROUND -->
        <div
            class="fixed inset-0 bg-cover bg-center z-0 screen-only"
            :style="{ backgroundImage: `url('${heroImage}')` }"
            :aria-label="heroImageAlt"
        ></div>

        <div class="fixed inset-0 z-0 bg-gradient-to-br from-[#B9DDF6]/85 via-[#CFE7FA]/80 to-[#E5F2FF]/85 dark:hidden screen-only"></div>

        <div class="fixed inset-0 z-0 hidden dark:block bg-gradient-to-br from-[#0B1220]/90 via-[#0F1E38]/85 to-[#0B1220]/95 screen-only"></div>
        <div class="fixed inset-0 z-0 hidden dark:block bg-[#0B1220]/30 screen-only"></div>

        <div class="relative z-10">
            <PublicNavbar class="screen-only" />

            <div class="pb-10 xs:pb-12 sm:pb-16 lg:pb-20">

                <!-- ==================== HERO ==================== -->
                <section class="screen-only relative px-3 xs:px-4 sm:px-6 lg:px-8 pt-4 xs:pt-5 sm:pt-6 lg:pt-10 pb-16 xs:pb-20 sm:pb-24 lg:pb-32">
                    <div class="mx-auto max-w-[1200px]">
                        <div class="relative overflow-hidden rounded-2xl xs:rounded-3xl
                                    bg-gradient-to-br from-[#0B4F9E] via-[#0A69C6] to-[#1883DB]
                                    dark:from-[#0F1E38] dark:via-[#14264A] dark:to-[#1E3A8A]
                                    shadow-[0_25px_60px_-15px_rgba(11,79,158,0.55)]
                                    dark:shadow-[0_25px_60px_-15px_rgba(0,0,0,0.85)]
                                    ring-1 ring-white/10 dark:ring-[#1E3A8A]/40
                                    transition-colors duration-500">

                            <div class="pointer-events-none absolute -top-24 -left-24 h-48 w-48 xs:h-56 xs:w-56 sm:h-72 sm:w-72 rounded-full bg-white/10 blur-3xl"></div>
                            <div class="pointer-events-none absolute -bottom-24 -right-24 h-48 w-48 xs:h-56 xs:w-56 sm:h-72 sm:w-72 rounded-full bg-[#60A5FA]/20 blur-3xl"></div>

                            <div class="pointer-events-none absolute inset-0 opacity-[0.08]"
                                 style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 20px 20px;">
                            </div>

                            <div class="relative grid grid-cols-1 lg:grid-cols-12 gap-5 xs:gap-6 lg:gap-10 items-center p-4 xs:p-5 sm:p-8 lg:p-10 xl:p-12">

                                <div class="lg:col-span-7 text-white">
                                    <div class="inline-flex items-center gap-1.5 xs:gap-2 rounded-full bg-white/10 backdrop-blur-sm px-2.5 xs:px-3.5 py-1 xs:py-1.5 mb-3 xs:mb-5 ring-1 ring-white/20">
                                        <Link :href="route('home')" class="text-[10px] xs:text-[12px] font-semibold text-white/90 hover:text-white transition">
                                            Home
                                        </Link>
                                        <svg class="h-2.5 w-2.5 xs:h-3 xs:w-3 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                        </svg>
                                        <span class="text-[10px] xs:text-[12px] font-bold text-white">Result</span>
                                    </div>

                                    <h1 class="text-2xl xs:text-3xl sm:text-4xl lg:text-5xl xl:text-[52px] font-black leading-[1.1] tracking-tight drop-shadow-[0_2px_12px_rgba(0,0,0,0.25)]">
                                        Check Your Result
                                    </h1>

                                    <p class="mt-2.5 xs:mt-4 max-w-lg text-xs xs:text-sm sm:text-base text-white/85 leading-relaxed">
                                        Enter your Roll Number, Class, Year and Exam to view your academic result instantly.
                                    </p>

                                    <div class="mt-4 xs:mt-6 flex flex-wrap items-center gap-1.5 xs:gap-2 sm:gap-2.5">
                                        <span class="inline-flex items-center gap-1 xs:gap-1.5 rounded-full bg-white/10 backdrop-blur-sm px-2 xs:px-3 py-1 xs:py-1.5 text-[9px] xs:text-[10px] sm:text-[11px] font-semibold text-white ring-1 ring-white/20">
                                            <svg class="h-2.5 w-2.5 xs:h-3 xs:w-3 sm:h-3.5 sm:w-3.5 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                            </svg>
                                            Instant Result
                                        </span>
                                        <span class="inline-flex items-center gap-1 xs:gap-1.5 rounded-full bg-white/10 backdrop-blur-sm px-2 xs:px-3 py-1 xs:py-1.5 text-[9px] xs:text-[10px] sm:text-[11px] font-semibold text-white ring-1 ring-white/20">
                                            <svg class="h-2.5 w-2.5 xs:h-3 xs:w-3 sm:h-3.5 sm:w-3.5 text-sky-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                                            </svg>
                                            Verified &amp; Secure
                                        </span>
                                        <span class="inline-flex items-center gap-1 xs:gap-1.5 rounded-full bg-white/10 backdrop-blur-sm px-2 xs:px-3 py-1 xs:py-1.5 text-[9px] xs:text-[10px] sm:text-[11px] font-semibold text-white ring-1 ring-white/20">
                                            <svg class="h-2.5 w-2.5 xs:h-3 xs:w-3 sm:h-3.5 sm:w-3.5 text-yellow-300" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 2l2.9 6.26L22 9.27l-5 4.87L18.18 22 12 18.56 5.82 22 7 14.14l-5-4.87 7.1-1.01L12 2z" />
                                            </svg>
                                            Official Result
                                        </span>
                                    </div>
                                </div>

                                <div class="lg:col-span-5 relative">
                                    <div class="relative aspect-[16/10] xs:aspect-[4/3] rounded-xl xs:rounded-2xl overflow-hidden
                                                ring-3 xs:ring-4 ring-white/20 dark:ring-[#60A5FA]/20
                                                shadow-[0_20px_50px_-12px_rgba(0,0,0,0.5)]
                                                rotate-1 hover:rotate-0 transition-transform duration-500">
                                        <img
                                            :src="heroImage"
                                            :alt="heroImageAlt"
                                            class="w-full h-full object-cover"
                                        />
                                        <div class="absolute inset-0 bg-gradient-to-t from-[#0B4F9E]/60 via-transparent to-transparent dark:from-[#0B1220]/70"></div>

                                        <div class="absolute top-2 xs:top-3 right-2 xs:right-3 inline-flex items-center gap-1 xs:gap-1.5 rounded-full bg-white/95 dark:bg-slate-900/95 px-2 xs:px-3 py-1 xs:py-1.5 shadow-lg backdrop-blur-sm">
                                            <span class="relative flex h-1.5 w-1.5 xs:h-2 xs:w-2">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-1.5 w-1.5 xs:h-2 xs:w-2 bg-emerald-500"></span>
                                            </span>
                                            <span class="text-[8px] xs:text-[10px] font-black uppercase tracking-wider text-[#0B4F9E] dark:text-white">Result Portal</span>
                                        </div>

                                        <div class="absolute bottom-2 xs:bottom-3 left-2 xs:left-3 right-2 xs:right-3 flex items-center gap-1.5 xs:gap-2 rounded-lg xs:rounded-xl bg-white/15 dark:bg-slate-900/40 backdrop-blur-md px-2 xs:px-3 py-1.5 xs:py-2 ring-1 ring-white/20">
                                            <div class="flex h-6 w-6 xs:h-8 xs:w-8 items-center justify-center rounded-md xs:rounded-lg bg-white/95 dark:bg-slate-800 flex-shrink-0 overflow-hidden">
                                                <img v-if="school?.logo" :src="`/storage/${school.logo}`" :alt="school.name" class="h-full w-full object-contain p-0.5 xs:p-1" />
                                                <svg v-else class="h-3 w-3 xs:h-4 xs:w-4 text-[#0B4F9E] dark:text-[#60A5FA]" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M12 2L2 7v2h1v11h18V9h1V7L12 2zm0 2.24L18.76 7H5.24L12 4.24zM5 9h14v9H5V9zm2 2v5h2v-3h2v3h2v-5H7z"/>
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-[9px] xs:text-[11px] font-black text-white leading-tight truncate">{{ school?.name || 'School' }}</p>
                                                <p class="text-[8px] xs:text-[9px] font-semibold text-white/80 tracking-wide truncate">{{ school?.motto || 'Excellence in Education' }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="absolute -bottom-2 -left-2 xs:-bottom-3 xs:-left-3 -z-10 h-full w-full rounded-xl xs:rounded-2xl bg-white/10 ring-1 ring-white/20"></div>
                                </div>
                            </div>

                            <div class="absolute bottom-0 left-0 right-0 pointer-events-none">
                                <svg viewBox="0 0 1440 80" preserveAspectRatio="none" class="w-full h-8 xs:h-10 sm:h-12 lg:h-14 block">
                                    <path d="M0,50 C240,80 480,20 720,40 C960,60 1200,80 1440,45 L1440,80 L0,80 Z" fill="#ffffff" class="dark:fill-[#0B1220]" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ==================== MAIN CONTENT ==================== -->
                <section class="relative px-3 xs:px-4 sm:px-6 lg:px-8 pb-6 xs:pb-8 sm:pb-12 -mt-10 xs:-mt-12 sm:-mt-16 lg:-mt-24">
                    <div class="mx-auto max-w-[1200px] space-y-4 xs:space-y-5 sm:space-y-6 lg:space-y-8">

                        <!-- SEARCH FORM -->
                        <div class="screen-only rounded-xl xs:rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xl dark:shadow-black/40 p-4 xs:p-5 sm:p-7 lg:p-8 transition-colors duration-500">

                            <div class="flex items-start gap-3 xs:gap-4 mb-4 xs:mb-6 sm:mb-7">
                                <div class="flex h-10 w-10 xs:h-12 xs:w-12 sm:h-14 sm:w-14 items-center justify-center rounded-xl xs:rounded-2xl bg-gradient-to-br from-[#0055B6] to-[#1883DB] shadow-lg shadow-[#0055B6]/25 flex-shrink-0">
                                    <svg class="h-5 w-5 xs:h-6 xs:w-6 sm:h-7 sm:w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                    </svg>
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-base xs:text-lg sm:text-xl lg:text-2xl font-black text-[#0B376B] dark:text-slate-100 leading-tight">
                                        Student Result Search
                                    </h2>
                                    <p class="mt-0.5 xs:mt-1 text-[11px] xs:text-xs sm:text-sm text-slate-500 dark:text-slate-400 leading-snug">
                                        Please provide the required information to check your result.
                                    </p>
                                </div>
                            </div>

                            <div v-if="errorMsg" class="mb-3 xs:mb-5 rounded-lg xs:rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 px-3 xs:px-4 py-2.5 xs:py-3 flex items-start gap-2 xs:gap-3">
                                <svg class="h-4 w-4 xs:h-5 xs:w-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-xs xs:text-sm font-medium text-red-700 dark:text-red-300">{{ errorMsg }}</p>
                            </div>

                            <form @submit.prevent="submitResult" class="space-y-3.5 xs:space-y-5">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 xs:gap-4 sm:gap-5">
                                    <div>
                                        <label class="block text-[11px] xs:text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 xs:mb-2">
                                            Student ID / Roll Number <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 xs:left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                </svg>
                                            </span>
                                            <input
                                                v-model="form.student_id"
                                                type="text"
                                                placeholder="e.g. 2023001 or STD-2026-00001"
                                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2 xs:py-2.5 pl-9 xs:pl-10 pr-3 xs:pr-4 text-xs xs:text-sm text-slate-700 dark:text-slate-100 outline-none transition placeholder:text-slate-400 focus:border-[#0055B6] focus:ring-4 focus:ring-[#0055B6]/10"
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] xs:text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 xs:mb-2">
                                            Class <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 xs:left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                                </svg>
                                            </span>
                                            <select
                                                v-model="form.class_name"
                                                class="w-full appearance-none rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2 xs:py-2.5 pl-9 xs:pl-10 pr-9 xs:pr-10 text-xs xs:text-sm text-slate-700 dark:text-slate-100 outline-none transition focus:border-[#0055B6] focus:ring-4 focus:ring-[#0055B6]/10"
                                            >
                                                <option value="">Select Class</option>
                                                <option v-for="name in classNames" :key="name" :value="name">
                                                    {{ name }}
                                                </option>
                                            </select>
                                            <span class="absolute right-3 xs:right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] xs:text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 xs:mb-2">
                                            Year <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 xs:left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </span>
                                            <input
                                                v-model="form.year"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="4"
                                                placeholder="2026"
                                                class="w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2 xs:py-2.5 pl-9 xs:pl-10 pr-3 xs:pr-4 text-xs xs:text-sm text-slate-700 dark:text-slate-100 outline-none transition placeholder:text-slate-400 focus:border-[#0055B6] focus:ring-4 focus:ring-[#0055B6]/10"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 xs:gap-4 sm:gap-5">
                                    <div>
                                        <label class="block text-[11px] xs:text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 xs:mb-2">
                                            Choose Exam <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 xs:left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </span>
                                            <select
                                                v-model="form.exam"
                                                class="w-full appearance-none rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2 xs:py-2.5 pl-9 xs:pl-10 pr-9 xs:pr-10 text-xs xs:text-sm text-slate-700 dark:text-slate-100 outline-none transition focus:border-[#0055B6] focus:ring-4 focus:ring-[#0055B6]/10"
                                            >
                                                <option value="">Select Exam</option>
                                                <option value="final">Final</option>
                                                <option value="half_yearly">Half Yearly</option>
                                                <option value="test">Test Examination</option>
                                            </select>
                                            <span class="absolute right-3 xs:right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] xs:text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 xs:mb-2">
                                            Choose Section
                                            <span class="text-slate-400 text-[9px] xs:text-[10px] font-normal ml-1">(Optional)</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3 xs:left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                                </svg>
                                            </span>
                                            <select
                                                v-model="form.section"
                                                :disabled="!form.class_name"
                                                class="w-full appearance-none rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2 xs:py-2.5 pl-9 xs:pl-10 pr-9 xs:pr-10 text-xs xs:text-sm text-slate-700 dark:text-slate-100 outline-none transition focus:border-[#0055B6] focus:ring-4 focus:ring-[#0055B6]/10 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400 dark:disabled:bg-slate-800/50 dark:disabled:text-slate-500"
                                            >
                                                <option value="all">
                                                    {{ form.class_name ? 'Any Section (Default)' : 'Select class first' }}
                                                </option>
                                                <option v-for="sec in availableSections" :key="sec" :value="sec">
                                                    {{ sec }}
                                                </option>
                                            </select>
                                            <span class="absolute right-3 xs:right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                                <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    :disabled="loading"
                                    class="w-full inline-flex items-center justify-center gap-1.5 xs:gap-2 rounded-lg bg-gradient-to-r from-[#0055B6] to-[#1883DB] px-4 xs:px-6 py-3 xs:py-3.5 text-xs xs:text-sm sm:text-base font-bold text-white shadow-lg shadow-[#0055B6]/25 transition hover:shadow-xl hover:shadow-[#0055B6]/40 hover:scale-[1.01] active:scale-95 disabled:opacity-70 disabled:cursor-wait"
                                >
                                    <svg v-if="!loading" class="h-4 w-4 xs:h-5 xs:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    <svg v-else class="h-4 w-4 xs:h-5 xs:w-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    {{ loading ? 'Searching...' : 'View Result' }}
                                </button>
                            </form>
                        </div>

                        <!-- RESULT DETAILS -->
                        <div
                            v-if="showResult"
                            class="screen-only rounded-xl xs:rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xl dark:shadow-black/40 p-4 xs:p-5 sm:p-7 lg:p-8 transition-colors duration-500"
                        >
                            <div class="flex flex-col xs:flex-row items-start xs:items-start justify-between gap-3 xs:gap-4 mb-4 xs:mb-6 sm:mb-7">
                                <div class="flex items-start gap-3 xs:gap-4">
                                    <div class="flex h-10 w-10 xs:h-12 xs:w-12 sm:h-14 sm:w-14 items-center justify-center rounded-xl xs:rounded-2xl bg-gradient-to-br from-[#0055B6] to-[#1883DB] shadow-lg shadow-[#0055B6]/25 flex-shrink-0">
                                        <svg class="h-5 w-5 xs:h-6 xs:w-6 sm:h-7 sm:w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <h2 class="text-base xs:text-lg sm:text-xl lg:text-2xl font-black text-[#0B376B] dark:text-slate-100 leading-tight">
                                            Result Details
                                        </h2>
                                        <p class="mt-0.5 xs:mt-1 text-[11px] xs:text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                                            Your complete academic performance
                                        </p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="resetSearch"
                                    class="self-start xs:self-auto shrink-0 inline-flex items-center gap-1 xs:gap-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 xs:px-3 py-1.5 xs:py-2 text-[10px] xs:text-xs font-bold text-slate-600 dark:text-slate-300 transition hover:border-[#0055B6]/40 hover:text-[#0055B6] active:scale-95"
                                >
                                    <svg class="h-3 w-3 xs:h-3.5 xs:w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    New Search
                                </button>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 xs:gap-4 sm:gap-5 mb-4 xs:mb-6 sm:mb-7">
                                <div class="lg:col-span-2 rounded-lg xs:rounded-xl bg-[#EBF4FF] dark:bg-slate-800 border border-[#0055B6]/10 dark:border-slate-700 p-3 xs:p-4 sm:p-5">
                                    <div class="flex items-start gap-3 xs:gap-4">
                                        <div class="flex h-11 w-11 xs:h-14 xs:w-14 sm:h-16 sm:w-16 items-center justify-center rounded-full bg-white dark:bg-slate-700 flex-shrink-0 shadow-md">
                                            <svg class="h-6 w-6 xs:h-8 xs:w-8 sm:h-9 sm:w-9 text-[#0055B6] dark:text-[#60A5FA]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3 class="text-sm xs:text-base sm:text-lg font-black text-[#0B376B] dark:text-slate-100 truncate">
                                                {{ resultData.student.name }}
                                            </h3>
                                            <div class="mt-1.5 xs:mt-2 grid grid-cols-2 gap-x-3 gap-y-1 xs:gap-2 text-[11px] xs:text-xs sm:text-sm">
                                                <div class="flex items-center gap-1">
                                                    <span class="text-slate-500 dark:text-slate-400">Roll:</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200 truncate">{{ resultData.student.roll }}</span>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <span class="text-slate-500 dark:text-slate-400">Class:</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200 truncate">{{ resultData.student.class_name }}</span>
                                                </div>
                                                <div v-if="resultData.student.section && resultData.student.section !== 'N/A'" class="flex items-center gap-1">
                                                    <span class="text-slate-500 dark:text-slate-400">Section:</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200 truncate">{{ resultData.student.section }}</span>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <span class="text-slate-500 dark:text-slate-400">Exam:</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200 truncate">{{ resultData.student.exam }}</span>
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <span class="text-slate-500 dark:text-slate-400">Year:</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200 truncate">{{ resultData.student.year }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-lg xs:rounded-xl bg-[#EBF4FF] dark:bg-slate-800 border border-[#0055B6]/10 dark:border-slate-700 p-3 xs:p-4 sm:p-5">
                                    <div class="flex items-start gap-2.5 xs:gap-3">
                                        <div class="flex h-9 w-9 xs:h-10 xs:w-10 sm:h-12 sm:w-12 items-center justify-center rounded-full bg-white dark:bg-slate-700 flex-shrink-0 shadow-md">
                                            <svg class="h-5 w-5 xs:h-6 xs:w-6 text-[#0055B6] dark:text-[#60A5FA]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                                            </svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] xs:text-xs font-bold text-[#0055B6] dark:text-[#60A5FA] uppercase tracking-wider">Overall Result</p>
                                            <p class="mt-0.5 xs:mt-1 text-xl xs:text-2xl sm:text-3xl font-black text-[#0B376B] dark:text-slate-100 leading-none">
                                                {{ resultData.summary.grade }}
                                            </p>
                                            <p class="mt-0.5 xs:mt-1 text-[10px] xs:text-xs text-slate-500 dark:text-slate-400">({{ resultData.summary.remarks }})</p>

                                            <div class="mt-2 xs:mt-3 space-y-0.5 xs:space-y-1 text-[10px] xs:text-xs">
                                                <div class="flex justify-between gap-2">
                                                    <span class="text-slate-500 dark:text-slate-400">Total</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200">{{ resultData.summary.totalMarks }}</span>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <span class="text-slate-500 dark:text-slate-400">Obtained</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200">{{ resultData.summary.obtainedMarks }}</span>
                                                </div>
                                                <div class="flex justify-between gap-2">
                                                    <span class="text-slate-500 dark:text-slate-400">Percentage</span>
                                                    <span class="font-semibold text-[#0B376B] dark:text-slate-200">{{ resultData.summary.percentage }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Subjects Table -->
                            <div class="overflow-x-auto rounded-lg xs:rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                                <table class="w-full text-[11px] xs:text-xs sm:text-sm min-w-[440px] xs:min-w-[500px]">
                                    <thead>
                                        <tr class="bg-gradient-to-r from-[#0055B6] to-[#1883DB] text-white">
                                            <th class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-left font-bold">Subject</th>
                                            <th class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center font-bold">Full</th>
                                            <th class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center font-bold">Obtained</th>
                                            <th class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center font-bold">Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <tr
                                            v-for="(sub, i) in resultData.subjects"
                                            :key="i"
                                            class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition"
                                        >
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-slate-700 dark:text-slate-300 font-medium">
                                                {{ sub.name }}
                                            </td>
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center text-slate-600 dark:text-slate-400">
                                                {{ sub.full }}
                                            </td>
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center font-bold text-[#0B376B] dark:text-slate-200">
                                                {{ sub.obtained }}
                                            </td>
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center">
                                                <span :class="['inline-flex items-center justify-center rounded-md px-2 xs:px-2.5 py-0.5 xs:py-1 text-[10px] xs:text-xs font-bold min-w-[32px] xs:min-w-[36px]', gradeClass(sub.grade)]">
                                                    {{ sub.grade }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-[#EBF4FF] dark:bg-slate-800">
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 font-black text-[#0B376B] dark:text-slate-100">Total</td>
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center font-black text-[#0B376B] dark:text-slate-100">
                                                {{ totalFullMarks }}
                                            </td>
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center font-black text-[#0B376B] dark:text-slate-100">
                                                {{ totalObtained }}
                                            </td>
                                            <td class="px-2 xs:px-3 sm:px-4 py-2 xs:py-3 text-center">
                                                <span :class="['inline-flex items-center justify-center rounded-md px-2 xs:px-2.5 py-0.5 xs:py-1 text-[10px] xs:text-xs font-black min-w-[32px] xs:min-w-[36px]', gradeClass(resultData.summary.grade)]">
                                                    {{ resultData.summary.grade }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div
                                :class="[
                                    'mt-4 xs:mt-6 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 xs:gap-4 rounded-lg xs:rounded-xl px-3 xs:px-4 sm:px-5 py-3 xs:py-4 border',
                                    resultData.summary.passed
                                        ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800/40'
                                        : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800/40'
                                ]"
                            >
                                <div class="flex items-center gap-2.5 xs:gap-3 text-left">
                                    <span
                                        :class="[
                                            'flex h-7 w-7 xs:h-8 xs:w-8 items-center justify-center rounded-full text-white flex-shrink-0',
                                            resultData.summary.passed ? 'bg-emerald-500' : 'bg-red-500'
                                        ]"
                                    >
                                        <svg v-if="resultData.summary.passed" class="h-4 w-4 xs:h-5 xs:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <svg v-else class="h-4 w-4 xs:h-5 xs:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </span>
                                    <p
                                        :class="[
                                            'text-xs xs:text-sm sm:text-base font-bold leading-snug',
                                            resultData.summary.passed ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'
                                        ]"
                                    >
                                        {{ resultData.summary.passed
                                            ? `Congratulations! You have passed with Grade ${resultData.summary.grade}.`
                                            : 'Sorry, you have failed. Please contact your class teacher.' }}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    @click="printResult"
                                    class="inline-flex items-center justify-center gap-1.5 xs:gap-2 rounded-lg bg-[#0055B6] hover:bg-[#1883DB] px-4 xs:px-5 py-2 xs:py-2.5 text-xs xs:text-sm font-bold text-white shadow-md transition hover:shadow-lg active:scale-95 flex-shrink-0 w-full sm:w-auto"
                                >
                                    <svg class="h-3.5 w-3.5 xs:h-4 xs:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                    </svg>
                                    Print Result
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- ==================== FOOTER — PublicFooter Component ==================== -->
            <PublicFooter class="screen-only" />
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- HIDDEN PRINT-ONLY SHEET                                       -->
    <!-- ============================================================ -->
    <div v-if="showResult" id="print-sheet" class="print-only">
        <div class="wm-wrap">
            <div class="wm-text">
                <div class="wm-school">{{ (school?.name || '').toUpperCase() }}</div>
                <div class="wm-official">OFFICIAL</div>
            </div>
        </div>

        <div class="sheet-inner">

            <div class="header">
                <div class="header-logo">
                    <img v-if="school?.logo" :src="`/storage/${school.logo}`" :alt="school?.name" />
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                </div>

                <div class="header-center">
                    <h1 class="header-school">{{ school?.name || 'School Name' }}</h1>
                    <p class="header-address">{{ contact?.address || school?.address || '' }}</p>
                    <p class="header-phone">Phone: {{ contact?.phone || school?.phone || '' }}</p>
                    <p class="header-title">ACADEMIC TRANSCRIPT</p>
                    <p class="header-subtitle">{{ (resultData.student.exam || '').toUpperCase() }}</p>
                </div>

                <div class="header-right">
                    <p class="header-session-label">SESSION</p>
                    <p class="header-session-year">{{ resultData.student.year }}</p>
                    <div class="header-session-line"></div>
                    <p class="header-session-note">Official Copy</p>
                </div>
            </div>

            <div class="meta-strip">
                <div><b>Examination:</b> {{ (resultData.student.exam || '').toUpperCase() }}</div>
                <div><b>Date:</b> {{ new Date().toLocaleDateString('en-GB') }}</div>
            </div>

            <div class="info-row">
                <div class="info-block">
                    <div class="block-head">STUDENT INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="info-label">Name</td>
                            <td class="info-value">{{ resultData.student.name }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Father's Name</td>
                            <td class="info-value">{{ getFatherName(resultData.student) }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Mother's Name</td>
                            <td class="info-value">{{ getMotherName(resultData.student) }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Class</td>
                            <td class="info-value">{{ resultData.student.class_name }} - {{ resultData.student.section || '—' }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Roll No.</td>
                            <td class="info-value">{{ resultData.student.roll }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Student ID</td>
                            <td class="info-value">{{ getStudentId(resultData.student) }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Date of Birth</td>
                            <td class="info-value">{{ getDob(resultData.student) }}</td>
                        </tr>
                    </table>
                </div>

                <div class="grade-block">
                    <div class="block-head">GRADING SCALE</div>
                    <table class="grade-table">
                        <thead>
                            <tr>
                                <th>MARKS</th>
                                <th>LG</th>
                                <th>GPA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>80-100</td><td class="lg-aplus">A+</td><td>5.00</td></tr>
                            <tr><td>70-79</td><td class="lg-a">A</td><td>4.00</td></tr>
                            <tr><td>60-69</td><td class="lg-aminus">A-</td><td>3.50</td></tr>
                            <tr><td>50-59</td><td class="lg-b">B</td><td>3.00</td></tr>
                            <tr><td>40-49</td><td class="lg-c">C</td><td>2.00</td></tr>
                            <tr><td>33-39</td><td class="lg-d">D</td><td>1.00</td></tr>
                            <tr><td>0-32</td><td class="lg-f">F</td><td>0.00</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="subject-block">
                <div class="block-head">SUBJECT-WISE PERFORMANCE</div>
                <table class="subject-table">
                    <thead>
                        <tr>
                            <th class="col-subj">SUBJECT</th>
                            <th class="col-total">TOTAL</th>
                            <th class="col-obt">OBTAINED</th>
                            <th class="col-high">HIGHEST</th>
                            <th class="col-lg">LG</th>
                            <th class="col-gpa">GPA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(sub, i) in resultData.subjects" :key="i">
                            <td class="col-subj">{{ sub.name }}</td>
                            <td class="col-total">{{ sub.full ?? 100 }}</td>
                            <td class="col-obt">{{ sub.obtained ?? '—' }}</td>
                            <td class="col-high">{{ sub.highest ?? '—' }}</td>
                            <td class="col-lg" :style="{ color: gradeColor(sub.grade), fontWeight: 700 }">{{ sub.grade }}</td>
                            <td class="col-gpa">{{ gradeGpa(sub.grade) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <table class="overall-table">
                <tr>
                    <td class="ot-cell ot-mint">
                        <p class="ot-label">TOTAL MARKS</p>
                        <p class="ot-value">{{ totalObtained }}</p>
                        <p class="ot-sub">of {{ totalFullMarks }}</p>
                    </td>
                    <td class="ot-cell ot-amber">
                        <p class="ot-label">POSITION</p>
                        <p class="ot-value">{{ resultData.student.position || '—' }}</p>
                        <p class="ot-sub">out of {{ resultData.student.total_students || '—' }}</p>
                    </td>
                    <td class="ot-cell ot-mint">
                        <p class="ot-label">OVERALL GPA</p>
                        <p class="ot-value">{{ gradeGpa(resultData.summary.grade) }}</p>
                    </td>
                    <td class="ot-cell ot-mint">
                        <p class="ot-label">LETTER GRADE</p>
                        <p class="ot-value" :style="{ color: resultData.summary.passed ? '#047857' : '#b91c1c' }">
                            {{ resultData.summary.grade }}
                        </p>
                    </td>
                    <td class="ot-cell" :class="resultData.summary.passed ? 'ot-pass' : 'ot-fail'">
                        <p class="ot-label" :style="{ color: resultData.summary.passed ? '#047857' : '#b91c1c' }">RESULT</p>
                        <p class="ot-result" :style="{ color: resultData.summary.passed ? '#047857' : '#b91c1c' }">
                            {{ resultData.summary.passed ? 'PASSED' : 'FAILED' }}
                        </p>
                        <p class="ot-sub" :style="{ color: resultData.summary.passed ? '#0f766e' : '#b91c1c' }">
                            ({{ totalObtained }} Marks)
                        </p>
                    </td>
                </tr>
            </table>

            <div class="signatures">
                <div class="sig-line">
                    <div class="sig-bar"></div>
                    <p>CLASS TEACHER</p>
                </div>
                <div class="sig-line">
                    <div class="sig-bar"></div>
                    <p>EXAM CONTROLLER</p>
                </div>
                <div class="sig-line">
                    <div class="sig-bar"></div>
                    <p>PRINCIPAL</p>
                </div>
            </div>

            <div class="sheet-footer">
                <p>Printed on: {{ new Date().toLocaleString() }}</p>
                <p class="italic">This is a computer-generated document. No signature required.</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.print-only { display: none; }

@media print {
    @page {
        size: A4 portrait;
        margin: 0.4in;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }

    html, body {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        height: 100% !important;
        background: #ffffff !important;
        overflow: visible !important;
    }

    body * { visibility: hidden !important; }

    .screen-only,
    header,
    footer,
    nav,
    aside,
    button {
        display: none !important;
        visibility: hidden !important;
    }

    #print-sheet {
        display: flex !important;
        flex-direction: column !important;
        visibility: visible !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        min-height: 100vh !important;
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
        z-index: 999999 !important;
        font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif !important;
    }

    #print-sheet * { visibility: visible !important; }

    #print-sheet,
    #print-sheet * {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
}

/* ============================================================ */
/* PRINT SHEET DESIGN                                            */
/* ============================================================ */
#print-sheet {
    font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    color: #0f172a;
    background: #ffffff;
    position: relative;
    padding: 8px 14px;
    min-height: 100vh;
    font-size: 11px;
    line-height: 1.35;
    display: none;
    flex-direction: column;
}

.wm-wrap {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
}
.wm-text {
    transform: rotate(-30deg);
    text-align: center;
    opacity: 0.06;
}
.wm-school {
    font-size: 90px;
    font-weight: 900;
    color: #047857;
    letter-spacing: 0.08em;
    line-height: 1;
    white-space: nowrap;
}
.wm-official {
    font-size: 28px;
    font-weight: 800;
    color: #047857;
    letter-spacing: 0.5em;
    margin-top: 12px;
}

.sheet-inner {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 100%;
}

.header {
    display: flex;
    align-items: stretch;
    background: linear-gradient(135deg, #047857 0%, #0f766e 100%);
    border-radius: 12px 12px 0 0;
    padding: 12px 16px;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(4, 120, 87, 0.2);
    gap: 14px;
    flex-shrink: 0;
}

.header-logo {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    border: 3px solid rgba(255, 255, 255, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    padding: 4px;
}
.header-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 50%;
}
.header-logo svg {
    width: 40px;
    height: 40px;
    color: #ffffff;
}

.header-center {
    flex: 1;
    text-align: center;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}
.header-school {
    font-size: 22px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0;
    line-height: 1.1;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}
.header-address {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.9);
    margin: 3px 0 0;
}
.header-phone {
    font-size: 9px;
    color: rgba(255, 255, 255, 0.85);
    margin: 2px 0 0;
}
.header-title {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.28em;
    margin: 6px 0 0;
    text-transform: uppercase;
    border-top: 1px solid rgba(255, 255, 255, 0.3);
    padding-top: 5px;
}
.header-subtitle {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.15em;
    margin: 2px 0 0;
    color: rgba(255, 255, 255, 0.9);
}

.header-right {
    min-width: 84px;
    background: rgba(255, 255, 255, 0.14);
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-radius: 10px;
    padding: 6px 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    flex-shrink: 0;
}
.header-session-label {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    margin: 0;
    color: rgba(255, 255, 255, 0.9);
}
.header-session-year {
    font-size: 20px;
    font-weight: 900;
    margin: 3px 0 0;
    line-height: 1;
    color: #ffffff;
}
.header-session-line {
    width: 24px;
    height: 2px;
    background: #ffffff;
    margin: 4px auto;
}
.header-session-note {
    font-size: 7px;
    font-weight: 700;
    letter-spacing: 0.12em;
    margin: 0;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.85);
}

.meta-strip {
    display: flex;
    justify-content: space-between;
    padding: 5px 14px;
    background: #ecfdf5;
    border-left: 4px solid #047857;
    border-right: 4px solid #047857;
    border-bottom: 3px solid #047857;
    font-size: 10px;
    color: #064e3b;
    margin-bottom: 10px;
    flex-shrink: 0;
}
.meta-strip b {
    color: #047857;
    letter-spacing: 0.05em;
}

.info-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    flex-shrink: 0;
}
.info-block {
    flex: 1.55;
    border: 2px solid #047857;
    border-radius: 8px;
    overflow: hidden;
    background: #ffffff;
}
.grade-block {
    flex: 1;
    border: 2px solid #047857;
    border-radius: 8px;
    overflow: hidden;
    background: #ffffff;
}
.block-head {
    background: linear-gradient(135deg, #047857 0%, #0f766e 100%);
    color: #ffffff;
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    padding: 5px 12px;
}
.info-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10.5px;
}
.info-table tr td {
    padding: 4.5px 10px;
    border-bottom: 1px solid #a7f3d0;
}
.info-table tr:last-child td {
    border-bottom: none;
}
.info-label {
    width: 100px;
    background: #f0fdfa;
    font-weight: 700;
    color: #047857;
    border-right: 1px solid #a7f3d0;
}
.info-value {
    color: #064e3b;
    font-weight: 600;
}

.grade-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5px;
}
.grade-table thead th {
    background: #f0fdfa;
    color: #047857;
    font-weight: 800;
    font-size: 8.5px;
    letter-spacing: 0.05em;
    padding: 3.5px 4px;
    text-align: center;
    border-bottom: 1px solid #a7f3d0;
    border-right: 1px solid #a7f3d0;
}
.grade-table thead th:last-child {
    border-right: none;
}
.grade-table tbody td {
    padding: 3.2px 4px;
    text-align: center;
    border-bottom: 1px solid #e2e8f0;
    border-right: 1px solid #e2e8f0;
    color: #475569;
}
.grade-table tbody td:last-child {
    border-right: none;
}
.grade-table tbody tr:last-child td {
    border-bottom: none;
}
.lg-aplus { color: #047857; font-weight: 800; background: #d1fae5; }
.lg-a { color: #15803d; font-weight: 800; }
.lg-aminus { color: #4d7c0f; font-weight: 800; }
.lg-b { color: #a16207; font-weight: 800; }
.lg-c { color: #c2410c; font-weight: 800; }
.lg-d { color: #be123c; font-weight: 800; }
.lg-f { color: #b91c1c; font-weight: 800; background: #fee2e2; }

.subject-block {
    border: 2px solid #047857;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 10px;
    background: #ffffff;
    flex-shrink: 0;
}
.subject-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10.5px;
}
.subject-table thead th {
    background: #ecfdf5;
    color: #047857;
    font-weight: 800;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 6px 10px;
    border-bottom: 1.5px solid #047857;
    border-right: 1px solid #a7f3d0;
}
.subject-table thead th:last-child {
    border-right: none;
}
.subject-table tbody td {
    padding: 5.5px 10px;
    border-bottom: 1px solid #d1fae5;
    border-right: 1px solid #d1fae5;
}
.subject-table tbody td:last-child {
    border-right: none;
}
.subject-table tbody tr:nth-child(even) td {
    background: #f0fdfa;
}
.subject-table tbody tr:last-child td {
    border-bottom: none;
}
.col-subj { text-align: left; font-weight: 700; color: #064e3b; }
.col-total, .col-obt, .col-high, .col-lg, .col-gpa { text-align: center; }
.col-obt { font-weight: 800; color: #047857; }
.col-total, .col-high { color: #475569; }
.col-gpa { font-weight: 800; color: #0f766e; }

.overall-table {
    width: 100%;
    border-collapse: collapse;
    border: 2px solid #047857;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 12px;
    background: #ffffff;
    flex-shrink: 0;
}
.ot-cell {
    padding: 9px 6px;
    text-align: center;
    border-right: 1px solid #a7f3d0;
    vertical-align: middle;
}
.ot-cell:last-child {
    border-right: none;
}
.ot-label {
    font-size: 8.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin: 0 0 3px;
    color: #047857;
}
.ot-value {
    font-size: 20px;
    font-weight: 900;
    margin: 0;
    line-height: 1;
    color: #047857;
}
.ot-sub {
    font-size: 8.5px;
    color: #0f766e;
    margin: 3px 0 0;
    font-weight: 600;
}
.ot-result {
    font-size: 16px;
    font-weight: 900;
    margin: 0;
    line-height: 1;
    letter-spacing: 0.05em;
}
.ot-mint { background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); }
.ot-amber { background: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%); }
.ot-amber .ot-label { color: #92400e; }
.ot-amber .ot-value { color: #92400e; }
.ot-amber .ot-sub { color: #b45309; }
.ot-pass { background: linear-gradient(180deg, #ecfdf5 0%, #bbf7d0 100%); }
.ot-fail { background: linear-gradient(180deg, #fef2f2 0%, #fee2e2 100%); }

.signatures {
    display: flex;
    justify-content: space-between;
    gap: 30px;
    margin-top: auto;
    padding-top: 50px;
    padding-bottom: 10px;
    flex-shrink: 0;
}
.sig-line {
    flex: 1;
    text-align: center;
}
.sig-bar {
    border-top: 1.5px solid #047857;
    margin-bottom: 5px;
}
.sig-line p {
    font-size: 9px;
    font-weight: 800;
    color: #047857;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin: 0;
}

.sheet-footer {
    display: flex;
    justify-content: space-between;
    padding: 6px 12px;
    background: #f0fdfa;
    border: 1px dashed #047857;
    border-radius: 6px;
    font-size: 8.5px;
    color: #475569;
    flex-shrink: 0;
}
.sheet-footer p {
    margin: 0;
    font-weight: 600;
}
.sheet-footer .italic {
    font-style: italic;
    font-weight: 400;
}
</style>
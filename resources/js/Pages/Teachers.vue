<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'
import PublicNavbar from '@/Components/PublicNavbar.vue'
import PublicFooter from '@/Components/PublicFooter.vue'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
    teachers:    { type: Array,  default: () => [] },
    departments: { type: Array,  default: () => [] },
    filters:     { type: Object, default: () => ({}) },
    hero:        { type: Object, default: () => ({}) },
})

const page = usePage()
const school = computed(() => page.props.school ?? {})
const contact = computed(() => page.props.contact ?? {})

const { initTheme } = useTheme()
onMounted(() => initTheme())

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
// WHATSAPP URL HELPER
// 01XXXXXXXXX  -> 8801XXXXXXXXX
// +8801XXXXXXXXX / 8801XXXXXXXXX -> unchanged
// ============================================================
const whatsappUrl = (phone) => {
    if (!phone) return null
    let digits = String(phone).replace(/[^0-9]/g, '')
    if (!digits) return null
    if (digits.startsWith('00')) digits = digits.slice(2)
    if (digits.startsWith('0')) digits = '88' + digits
    return `https://wa.me/${digits}`
}

// Fallback placeholder
const placeholderImg = 'data:image/svg+xml;utf8,' + encodeURIComponent(`
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="500" viewBox="0 0 400 500">
  <rect width="400" height="500" fill="#EBF4FF"/>
  <circle cx="200" cy="200" r="70" fill="#93C5FD" opacity="0.6"/>
  <path d="M200 130a70 70 0 100 140 70 70 0 000-140zm0 90a20 20 0 110-40 20 20 0 010 40zm0 20c30 0 55 20 60 45H140c5-25 30-45 60-45z" fill="#0055B6" opacity="0.8"/>
  <text x="200" y="400" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" fill="#0055B6" opacity="0.7">No Image</text>
</svg>
`)

// ============================================================
// HERO IMAGE
// ============================================================
const heroImage = computed(() => {
    if (props.hero?.hero_image) {
        return storageUrl(props.hero.hero_image)
    }
    return 'https://images.unsplash.com/photo-1562774053-701939374585?w=1600&q=80'
})

const heroImageAlt = computed(() => props.hero?.hero_image_alt || 'Teachers and Staff')

// ============================================================
// SEARCH + FILTER
// ============================================================
const search = ref(props.filters?.search ?? '')
const department = ref(props.filters?.department ?? 'all')

let searchTimeout = null
watch(search, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        router.get(route('teachers'), {
            search: search.value,
            department: department.value,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        })
    }, 400)
})

watch(department, () => {
    router.get(route('teachers'), {
        search: search.value,
        department: department.value,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
})

const clearFilters = () => {
    search.value = ''
    department.value = 'all'
}

const hasFilters = computed(() => search.value || department.value !== 'all')

// ============================================================
// SIDEBAR STATS
// ============================================================
const totalTeachers = computed(() => props.teachers.length || 0)
const totalDepartments = computed(() => props.departments.length || 0)
const avgExperience = computed(() => {
    const nums = props.teachers
        .map(t => parseFloat(String(t.experience || '').replace(/[^0-9.]/g, '')))
        .filter(n => !isNaN(n) && n > 0)
    if (!nums.length) return '10+'
    const avg = nums.reduce((a, b) => a + b, 0) / nums.length
    return Math.round(avg) + '+'
})
</script>

<template>
    <Head :title="`Teachers & Staff — ${school?.name ?? 'School'}`" />

    <div class="min-h-screen bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 transition-colors duration-500">

        <PublicNavbar />

        <!-- ============================================================ -->
        <!-- HERO SECTION -->
        <!-- ============================================================ -->
        <section class="relative overflow-hidden">
            <div class="absolute inset-0">
                <img
                    :src="heroImage"
                    :alt="heroImageAlt"
                    class="h-full w-full scale-105 object-cover"
                    @error="(e) => console.error('Hero image failed:', e.target.src)"
                />

                <!-- Layered overlays -->
                <div class="absolute inset-0 bg-gradient-to-r from-[#061F3F]/95 via-[#0B376B]/80 to-[#0055B6]/30 dark:from-[#020817]/95 dark:via-[#061F3F]/85 dark:to-[#0B376B]/40"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-[#061F3F]/70 via-transparent to-transparent dark:from-black/60"></div>
            </div>

            <!-- Decorative glow shapes -->
            <div class="hero-float pointer-events-none absolute -top-24 -left-24 h-80 w-80 rounded-full bg-[#1883DB]/30 blur-3xl"></div>
            <div class="hero-float-slow pointer-events-none absolute -bottom-24 right-10 h-72 w-72 rounded-full bg-[#F5B82E]/20 blur-3xl"></div>
            <div class="pointer-events-none absolute top-12 right-[12%] hidden h-24 w-24 rounded-full border-2 border-white/15 lg:block"></div>
            <div class="pointer-events-none absolute bottom-24 right-[28%] hidden h-10 w-10 rounded-full border-2 border-[#F5B82E]/40 lg:block"></div>

            <!-- Dotted pattern -->
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.07]"
                style="background-image: radial-gradient(#ffffff 1px, transparent 1px); background-size: 22px 22px;"
            ></div>

            <div class="relative mx-auto max-w-[1200px] px-4 sm:px-6 lg:px-8 py-20 sm:py-24 lg:py-32">
                <div class="max-w-3xl text-left">

                    <!-- Breadcrumb -->
                    <div class="mb-5 inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-xs sm:text-sm ring-1 ring-white/20 backdrop-blur-md">
                        <Link :href="route('home')" class="text-[#93C5FD] hover:text-white transition font-medium">
                            Home
                        </Link>
                        <svg class="h-3.5 w-3.5 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        <span class="font-semibold text-white">Teachers &amp; Staff</span>
                    </div>

                    <div class="mb-4 flex items-center gap-3">
                        <span class="h-[3px] w-10 rounded-full bg-gradient-to-r from-[#F5B82E] to-[#FFD97A]"></span>
                        <p class="text-xs sm:text-sm font-bold text-[#F5B82E] uppercase tracking-[0.25em]">
                            Meet Our Team
                        </p>
                    </div>

                    <h1 class="text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-black text-white leading-[1.05] tracking-tight drop-shadow-lg">
                        Our
                        <span class="bg-gradient-to-r from-[#93C5FD] via-white to-[#FFD97A] bg-clip-text text-transparent">Teachers</span>
                        &amp; Staff
                    </h1>

                    <p class="mt-5 sm:mt-6 max-w-xl border-l-4 border-[#F5B82E] pl-4 text-sm sm:text-base lg:text-lg text-white/90 leading-relaxed">
                        Dedicated educators, inspiring minds,<br class="hidden sm:block" />
                        building a brighter future.
                    </p>
                </div>
            </div>

            <div class="absolute bottom-0 left-0 right-0">
                <svg viewBox="0 0 1440 80" fill="none" class="w-full h-12 sm:h-16">
                    <path d="M0 40 Q360 80 720 40 T1440 40 V80 H0 Z" class="fill-white dark:fill-slate-950 transition-colors duration-500"/>
                </svg>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- MAIN CONTENT -->
        <!-- ============================================================ -->
        <section class="relative px-4 sm:px-6 lg:px-8 py-12 sm:py-16 bg-white dark:bg-slate-950 transition-colors duration-500">

            <!-- Soft background accents -->
            <div class="pointer-events-none absolute top-0 left-0 h-72 w-72 rounded-full bg-[#0055B6]/5 blur-3xl dark:bg-[#1883DB]/10"></div>
            <div class="pointer-events-none absolute bottom-0 right-0 h-72 w-72 rounded-full bg-[#F5B82E]/10 blur-3xl dark:bg-[#F5B82E]/5"></div>

            <div class="relative mx-auto max-w-[1200px]">
                <div class="grid grid-cols-1 lg:grid-cols-[290px_1fr] gap-6 lg:gap-8">

                    <!-- LEFT SIDEBAR -->
                    <aside class="lg:sticky lg:top-28 lg:h-fit space-y-4">

                        <!-- Intro card -->
                        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0B376B] via-[#0055B6] to-[#1883DB] dark:from-[#0B376B] dark:via-[#0A4A99] dark:to-[#0F5FB8] p-6 shadow-xl shadow-[#0055B6]/25 dark:shadow-black/40 ring-1 ring-transparent dark:ring-white/10 transition-colors duration-500">
                            <div class="pointer-events-none absolute -top-10 -right-10 h-36 w-36 rounded-full bg-white/10 blur-2xl"></div>
                            <div class="pointer-events-none absolute -bottom-12 -left-8 h-32 w-32 rounded-full bg-[#F5B82E]/20 blur-2xl"></div>

                            <div class="relative">
                                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/30 backdrop-blur-md shadow-lg mb-5">
                                    <svg class="h-7 w-7 text-[#F5B82E]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                    </svg>
                                </div>

                                <h3 class="text-xl font-black text-white leading-tight mb-3">
                                    Meet Our<br />
                                    <span class="text-[#F5B82E]">Dedicated Team</span>
                                </h3>

                                <div class="mb-3 h-[3px] w-12 rounded-full bg-gradient-to-r from-[#F5B82E] to-transparent"></div>

                                <p class="text-xs leading-relaxed text-white/80">
                                    Our teachers are not just educators, they are mentors, guides and inspiration for every student. With their experience, passion and commitment, they help shape the future of our children.
                                </p>
                            </div>
                        </div>

                        <!-- Stats card -->
                        <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-lg shadow-slate-200/60 dark:shadow-black/30 p-5 space-y-3 transition-colors duration-500">
                            <div class="group flex items-center gap-3 rounded-2xl p-2.5 transition hover:bg-[#EBF4FF] dark:hover:bg-slate-800">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white shadow-md shadow-[#0055B6]/30 dark:shadow-black/30 transition group-hover:scale-110">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-2xl font-black text-[#0B376B] dark:text-slate-100 leading-none">{{ totalTeachers }}+</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold mt-1">Total Teachers</p>
                                </div>
                            </div>

                            <div class="h-px bg-gradient-to-r from-transparent via-slate-200 dark:via-slate-700 to-transparent"></div>

                            <div class="group flex items-center gap-3 rounded-2xl p-2.5 transition hover:bg-[#EBF4FF] dark:hover:bg-slate-800">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white shadow-md shadow-[#0055B6]/30 dark:shadow-black/30 transition group-hover:scale-110">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-2xl font-black text-[#0B376B] dark:text-slate-100 leading-none">{{ totalDepartments }}</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold mt-1">Departments</p>
                                </div>
                            </div>

                            <div class="h-px bg-gradient-to-r from-transparent via-slate-200 dark:via-slate-700 to-transparent"></div>

                            <div class="group flex items-center gap-3 rounded-2xl p-2.5 transition hover:bg-[#FFF7E0] dark:hover:bg-slate-800">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#F5B82E] to-[#E89B0C] text-white shadow-md shadow-[#F5B82E]/30 dark:shadow-black/30 transition group-hover:scale-110">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-2xl font-black text-[#0B376B] dark:text-slate-100 leading-none">{{ avgExperience }}</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold mt-1">Years Average Experience</p>
                                </div>
                            </div>
                        </div>

                        <!-- Why choose us button -->
                        <a
                            href="#why-choose"
                            class="group relative flex items-center justify-between gap-3 overflow-hidden rounded-2xl bg-gradient-to-r from-[#F5B82E] to-[#E89B0C] p-5 shadow-lg shadow-[#F5B82E]/30 dark:shadow-[#F5B82E]/10 transition hover:shadow-xl hover:shadow-[#F5B82E]/50 hover:scale-[1.02] active:scale-95"
                        >
                            <span class="pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-white/25 blur-xl"></span>
                            <span class="relative text-sm font-black text-[#0B376B]">Why Choose Us</span>
                            <span class="relative flex h-9 w-9 items-center justify-center rounded-full bg-[#0B376B] shadow-md">
                                <svg class="h-4 w-4 text-white transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </span>
                        </a>
                    </aside>

                    <!-- RIGHT — Teachers Grid -->
                    <div>

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                            <div>
                                <div class="mb-2 flex items-center gap-2">
                                    <span class="h-[3px] w-8 rounded-full bg-gradient-to-r from-[#0055B6] to-[#F5B82E]"></span>
                                    <span class="text-[11px] font-bold uppercase tracking-[0.2em] text-[#0055B6] dark:text-[#60A5FA]">Faculty</span>
                                </div>
                                <h2 class="text-2xl sm:text-3xl font-black text-[#0B376B] dark:text-slate-100 leading-tight">
                                    Our Teachers
                                </h2>
                                <p class="mt-1 text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                                    Qualified, experienced and passionate educators.
                                </p>
                            </div>

                            <div class="relative w-full sm:w-72">
                                <input
                                    v-model="search"
                                    type="text"
                                    placeholder="Search teacher..."
                                    class="w-full rounded-full border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 py-3 pl-5 pr-12 text-sm text-slate-700 dark:text-slate-100 shadow-md shadow-slate-200/60 dark:shadow-black/20 outline-none transition placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-[#0055B6] dark:focus:border-[#60A5FA] focus:bg-white dark:focus:bg-slate-900 focus:ring-4 focus:ring-[#0055B6]/10 dark:focus:ring-[#60A5FA]/10"
                                />
                                <button
                                    type="button"
                                    class="absolute right-1.5 top-1/2 -translate-y-1/2 flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-[#0055B6] to-[#1883DB] text-white shadow-md shadow-[#0055B6]/30 dark:shadow-black/30 transition hover:scale-105 active:scale-95"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div v-if="hasFilters" class="mb-6 flex items-center justify-between gap-3 rounded-xl bg-[#EBF4FF] dark:bg-slate-800 border border-[#0055B6]/10 dark:border-slate-700 px-4 py-2.5 transition-colors duration-300">
                            <p class="text-xs text-[#0055B6] dark:text-[#60A5FA] font-semibold">
                                Showing {{ teachers.length }} result{{ teachers.length !== 1 ? 's' : '' }} with filters
                            </p>
                            <button
                                type="button"
                                @click="clearFilters"
                                class="text-xs font-bold text-[#0055B6] dark:text-[#60A5FA] hover:underline"
                            >
                                Clear
                            </button>
                        </div>

                        <div v-if="teachers.length === 0" class="rounded-3xl bg-white dark:bg-slate-900 border border-dashed border-slate-200 dark:border-slate-700 shadow-sm dark:shadow-black/20 p-12 text-center transition-colors duration-500">
                            <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-[#EBF4FF] dark:bg-slate-800 mb-5 transition-colors duration-300">
                                <svg class="h-10 w-10 text-[#0055B6] dark:text-[#60A5FA]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">
                                {{ hasFilters ? 'No teachers found' : 'No teachers available' }}
                            </h3>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                {{ hasFilters ? 'Try adjusting your search or filter criteria.' : 'Teachers will appear here once added.' }}
                            </p>
                        </div>

                        <!-- TEACHER GRID (all cards equal height) -->
                        <div v-else class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 items-stretch gap-4 sm:gap-5">
                            <div
                                v-for="(teacher, index) in teachers"
                                :key="teacher.id"
                                class="teacher-card group relative flex h-full flex-col overflow-hidden rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/70 dark:border-slate-800 shadow-md shadow-slate-200/70 dark:shadow-black/30 hover:shadow-2xl hover:shadow-[#0055B6]/20 dark:hover:shadow-black/60 hover:-translate-y-2 hover:border-[#0055B6]/30 dark:hover:border-[#60A5FA]/40 transition-all duration-300"
                                :style="{ animationDelay: `${Math.min(index, 11) * 60}ms` }"
                            >
                                <!-- Top accent bar -->
                                <div class="absolute inset-x-0 top-0 z-20 h-1 origin-left scale-x-0 bg-gradient-to-r from-[#0055B6] via-[#1883DB] to-[#F5B82E] transition-transform duration-500 group-hover:scale-x-100"></div>

                                <!-- Photo (fixed square, same for every card) -->
                                <div class="relative aspect-square w-full shrink-0 overflow-hidden bg-gradient-to-br from-[#EBF4FF] to-[#DCEBFF] dark:from-slate-700 dark:to-slate-800 transition-colors duration-300">
                                    <img
                                        v-if="teacher.photo"
                                        :src="storageUrl(teacher.photo)"
                                        :alt="teacher.name"
                                        class="h-full w-full object-cover object-top transition-transform duration-700 group-hover:scale-110"
                                        loading="lazy"
                                        @error="(e) => { e.target.src = placeholderImg }"
                                    />
                                    <div v-else class="flex h-full w-full items-center justify-center">
                                        <span class="text-5xl font-black text-[#0055B6] dark:text-[#60A5FA] opacity-40">
                                            {{ teacher.name?.charAt(0)?.toUpperCase() }}
                                        </span>
                                    </div>

                                    <!-- Bottom gradient fade -->
                                    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/40 to-transparent opacity-70 transition-opacity duration-300 group-hover:opacity-100"></div>

                                    <!-- Department badge -->
                                    <span
                                        v-if="teacher.department"
                                        class="absolute left-2.5 top-2.5 max-w-[85%] truncate rounded-full bg-white/95 dark:bg-slate-900/90 px-2.5 py-1 text-[9px] sm:text-[10px] font-bold uppercase tracking-wide text-[#0055B6] dark:text-[#60A5FA] shadow-md ring-1 ring-black/5 dark:ring-white/10 backdrop-blur-sm"
                                    >
                                        {{ teacher.department }}
                                    </span>
                                </div>

                                <!-- Info (flex-1 so button row is always pinned to the bottom) -->
                                <div class="relative flex flex-1 flex-col p-3 text-center">
                                    <h3 class="truncate text-sm font-black leading-5 text-[#0B376B] dark:text-slate-100 group-hover:text-[#0055B6] dark:group-hover:text-[#60A5FA] transition-colors">
                                        {{ teacher.name }}
                                    </h3>
                                    <p class="mt-0.5 truncate text-xs font-bold leading-4 text-[#E89B0C] dark:text-[#F5B82E]">
                                        {{ teacher.designation || 'Teacher' }}
                                    </p>

                                    <!-- Fixed-height slots: keep alignment even when data is missing -->
                                    <p class="mt-0.5 h-4 truncate text-[11px] leading-4 text-slate-500 dark:text-slate-400">
                                        {{ teacher.qualification || '\u00A0' }}
                                    </p>
                                    <p class="h-4 truncate text-[10px] leading-4 text-slate-400 dark:text-slate-500">
                                        {{ teacher.department ? `${teacher.department} Department` : '\u00A0' }}
                                    </p>

                                    <!-- Contact buttons (always at the bottom, fixed height) -->
                                    <div class="mt-auto flex h-8 items-center justify-center gap-2 pt-0 mt-3">
                                        <!-- Call -->
                                        <a
                                            v-if="teacher.phone"
                                            :href="`tel:${teacher.phone}`"
                                            class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#EBF4FF] dark:bg-slate-800 text-[#0055B6] dark:text-[#60A5FA] hover:bg-gradient-to-br hover:from-[#0055B6] hover:to-[#1883DB] hover:text-white dark:hover:text-white hover:-translate-y-0.5 hover:shadow-md hover:shadow-[#0055B6]/30 transition"
                                            :title="teacher.phone"
                                            aria-label="Call"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                            </svg>
                                        </a>

                                        <!-- WhatsApp -->
                                        <a
                                            v-if="teacher.phone"
                                            :href="whatsappUrl(teacher.phone)"
                                            target="_blank"
                                            rel="noopener"
                                            class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#E7F9EE] dark:bg-slate-800 text-[#25D366] hover:bg-[#25D366] hover:text-white dark:hover:text-white hover:-translate-y-0.5 hover:shadow-md hover:shadow-[#25D366]/40 transition"
                                            title="Chat on WhatsApp"
                                            aria-label="WhatsApp"
                                        >
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                            </svg>
                                        </a>

                                        <!-- Email -->
                                        <a
                                            v-if="teacher.email"
                                            :href="`mailto:${teacher.email}`"
                                            class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#EBF4FF] dark:bg-slate-800 text-[#0055B6] dark:text-[#60A5FA] hover:bg-gradient-to-br hover:from-[#0055B6] hover:to-[#1883DB] hover:text-white dark:hover:text-white hover:-translate-y-0.5 hover:shadow-md hover:shadow-[#0055B6]/30 transition"
                                            :title="teacher.email"
                                            aria-label="Email"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                        </a>

                                        <span
                                            v-if="!teacher.phone && !teacher.email"
                                            class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#EBF4FF] dark:bg-slate-800 text-[#0055B6] dark:text-[#60A5FA]"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- FOOTER — PublicFooter Component -->
        <!-- ============================================================ -->
        <PublicFooter />
    </div>
</template>

<style scoped>
/* Floating glow shapes in hero */
@keyframes heroFloat {
    0%, 100% { transform: translateY(0) translateX(0); }
    50%      { transform: translateY(-18px) translateX(10px); }
}
.hero-float      { animation: heroFloat 8s ease-in-out infinite; }
.hero-float-slow { animation: heroFloat 12s ease-in-out infinite reverse; }

/* Teacher cards fade-up on load */
@keyframes cardFadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}
.teacher-card {
    animation: cardFadeUp 0.6s ease-out both;
}

@media (prefers-reduced-motion: reduce) {
    .hero-float,
    .hero-float-slow,
    .teacher-card {
        animation: none;
    }
}
</style>
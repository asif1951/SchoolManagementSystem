<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import PublicNavbar from '@/Components/PublicNavbar.vue'
import PublicFooter from '@/Components/PublicFooter.vue'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
    canLogin:    { type: Boolean, default: false },
    canRegister: { type: Boolean, default: false },
    about:       { type: Object,  default: null },
    hero:        { type: Object,  default: null },
    teachers:    { type: Array,   default: () => [] },
    stats:       { type: Object,  default: () => ({}) },
})

const page = usePage()
const school = computed(() => page.props.school ?? {})
const contact = computed(() => page.props.contact ?? {})
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
// BRAND COLORS
// ============================================================
const BRAND_PRIMARY = '#0055B6'
const BRAND_DARK    = '#0B376B'
const BRAND_ACCENT  = '#F5B82E'

const brandVars = computed(() => ({
    '--brand':       school.value?.primary_color   || BRAND_PRIMARY,
    '--brand-dark':  school.value?.secondary_color || BRAND_DARK,
    '--accent':      school.value?.accent_color    || BRAND_ACCENT,
    '--brand-mid':   '#1883DB',
    '--brand-light': '#60A5FA',
    '--brand-tint':  '#EBF4FF',
}))

// ============================================================
// HERO IMAGE — backend hero image
// ============================================================
const heroImage = computed(() =>
    props.hero?.image ? `/storage/${props.hero.image}` : null
)

// ============================================================
// ABOUT IMAGE
// ============================================================
const aboutImage = computed(() =>
    props.about?.image
        ? `/storage/${props.about.image}`
        : 'https://images.unsplash.com/photo-1562774053-701939374585?w=1200&q=80'
)

// ============================================================
// WHATSAPP URL HELPER
// ============================================================
const whatsappUrl = (phone) => {
    if (!phone) return null
    let digits = String(phone).replace(/[^0-9]/g, '')
    if (!digits) return null
    if (digits.startsWith('00')) digits = digits.slice(2)
    if (digits.startsWith('0')) digits = '88' + digits
    return `https://wa.me/${digits}`
}

// Fallback placeholder (teacher photo)
const placeholderImg = 'data:image/svg+xml;utf8,' + encodeURIComponent(`
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="500" viewBox="0 0 400 500">
  <rect width="400" height="500" fill="#EBF4FF"/>
  <circle cx="200" cy="200" r="70" fill="#93C5FD" opacity="0.6"/>
  <path d="M200 130a70 70 0 100 140 70 70 0 000-140zm0 90a20 20 0 110-40 20 20 0 010 40zm0 20c30 0 55 20 60 45H140c5-25 30-45 60-45z" fill="#0055B6" opacity="0.8"/>
  <text x="200" y="400" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" fill="#0055B6" opacity="0.7">No Image</text>
</svg>
`)

// ============================================================
// STATS (with count-up animation, same as Home page)
// ============================================================
const statsList = computed(() => [
    { label: 'Total Students',    value: props.stats?.students          ?? 0, suffix: '+' },
    { label: 'Total Teachers',    value: props.stats?.teachers          ?? 0, suffix: '+' },
    { label: 'Classes',           value: props.stats?.classes           ?? 0, suffix: ''  },
    { label: 'Years of Excellence', value: props.stats?.yearsOfExcellence ?? 0, suffix: '+' },
])

const counts = ref([0, 0, 0, 0])
const statsEl = ref(null)
let statsObserver = null
let statsStarted = false

const runCountUp = () => {
    if (statsStarted) return
    statsStarted = true
    const targets = statsList.value.map(s => Number(s.value) || 0)
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (reduce) { counts.value = targets; return }
    const duration = 1600
    const start = performance.now()
    const tick = (now) => {
        const p = Math.min((now - start) / duration, 1)
        const eased = 1 - Math.pow(1 - p, 3)
        counts.value = targets.map(t => Math.round(t * eased))
        if (p < 1) requestAnimationFrame(tick)
    }
    requestAnimationFrame(tick)
}

// ============================================================
// SCROLL REVEAL
// ============================================================
let revealObserver = null
const REVEAL_CLASSES = ['reveal', 'reveal-left', 'reveal-right', 'reveal-zoom', 'is-visible']

const vReveal = {
    mounted(el, binding) {
        const v = binding.value
        const delay = typeof v === 'number' ? v : (v?.delay ?? 0)
        const type  = typeof v === 'object' && v ? v.type : null

        el.classList.add('reveal')
        if (type) el.classList.add(`reveal-${type}`)
        if (delay) el.style.transitionDelay = `${delay}ms`
        el.dataset.revealDelay = String(delay)

        if (!('IntersectionObserver' in window)) {
            REVEAL_CLASSES.forEach(c => el.classList.remove(c))
            el.style.transitionDelay = ''
            return
        }
        if (!revealObserver) {
            revealObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return
                    const target = entry.target
                    target.classList.add('is-visible')
                    revealObserver.unobserve(target)

                    const wait = 900 + Number(target.dataset.revealDelay || 0)
                    setTimeout(() => {
                        REVEAL_CLASSES.forEach(c => target.classList.remove(c))
                        target.style.transitionDelay = ''
                    }, wait)
                })
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' })
        }
        revealObserver.observe(el)
    },
}

onMounted(() => {
    initTheme()

    if (statsEl.value && 'IntersectionObserver' in window) {
        statsObserver = new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) {
                runCountUp()
                statsObserver.disconnect()
            }
        }, { threshold: 0.3 })
        statsObserver.observe(statsEl.value)
    } else {
        counts.value = statsList.value.map(s => Number(s.value) || 0)
    }
})

onBeforeUnmount(() => {
    statsObserver?.disconnect()
    revealObserver?.disconnect()
    revealObserver = null
})
</script>

<template>
    <Head :title="`About — ${school?.name ?? 'School'}`" />

    <div
        :style="brandVars"
        class="about-page min-h-screen bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 transition-colors duration-300"
    >
        <PublicNavbar />

        <!-- ============================================================ -->
        <!-- HERO HEADER — with backend image -->
        <!-- ============================================================ -->
        <section class="relative overflow-hidden about-gradient-brand">
            <!-- Background Image from backend -->
            <div class="absolute inset-0">
                <img
                    v-if="heroImage"
                    :src="heroImage"
                    :alt="hero?.image_alt || 'Hero image'"
                    class="h-full w-full object-cover"
                />
                <!-- Fallback decorative blobs if no image -->
                <template v-else>
                    <div class="absolute inset-0 opacity-20">
                        <div class="absolute -top-20 -left-20 h-72 w-72 rounded-full bg-white blur-3xl"></div>
                        <div class="absolute -bottom-24 right-0 h-80 w-80 rounded-full bg-[#F5B82E] blur-3xl"></div>
                    </div>
                </template>

                <!-- Overlay for readability -->
                <div class="absolute inset-0 about-hero-overlay"></div>
            </div>

            <div class="relative mx-auto max-w-[1200px] px-4 sm:px-6 lg:px-8 py-16 sm:py-20 lg:py-24 text-center">
                <p class="about-anim-up text-xs sm:text-sm font-bold text-[#93C5FD] uppercase tracking-[0.2em] mb-3" style="animation-delay: 0.05s">
                    About Our School
                </p>
                <h1 class="about-anim-up text-3xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight" style="animation-delay: 0.15s">
                    {{ about?.title || school?.name || 'Our Story' }}
                </h1>
                <p v-if="about?.subtitle" class="about-anim-up mx-auto mt-4 max-w-2xl text-sm sm:text-base text-white/85 leading-relaxed" style="animation-delay: 0.25s">
                    {{ about.subtitle }}
                </p>
                <p v-else class="about-anim-up mx-auto mt-4 max-w-2xl text-sm sm:text-base text-white/85 leading-relaxed" style="animation-delay: 0.25s">
                    Discover our journey, our mission, and the values that shape every child who walks through our doors.
                </p>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- STATS STRIP -->
        <!-- ============================================================ -->
        <section ref="statsEl" class="relative -mt-10 sm:-mt-14 z-10 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-[1200px]">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5">
                    <div
                        v-for="(stat, i) in statsList"
                        :key="stat.label"
                        v-reveal="{ delay: i * 80 }"
                        class="about-card group relative overflow-hidden rounded-2xl bg-white dark:bg-slate-800 p-4 sm:p-6 shadow-xl shadow-slate-300/40 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-700 transition duration-300 hover:-translate-y-1.5"
                    >
                        <div class="absolute inset-x-0 top-0 h-1 about-accent-bar"></div>
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="flex h-12 w-12 sm:h-14 sm:w-14 shrink-0 items-center justify-center rounded-2xl bg-[#0055B6]/10 dark:bg-[#60A5FA]/15 group-hover:bg-[#0055B6] dark:group-hover:bg-[#0055B6] transition duration-300 group-hover:rotate-6">
                                <svg class="h-6 w-6 text-[#0055B6] dark:text-[#60A5FA] group-hover:text-white dark:group-hover:text-white transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path v-if="i === 0" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                    <path v-else-if="i === 1" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                    <path v-else-if="i === 2" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[#0B376B] dark:text-white text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">
                                    {{ counts[i] }}{{ stat.suffix }}
                                </p>
                                <p class="mt-1.5 text-[11px] sm:text-sm text-slate-600 dark:text-slate-200 font-medium">
                                    {{ stat.label }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- ABOUT MAIN SECTION -->
        <!-- ============================================================ -->
        <section class="relative px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
            <div class="mx-auto max-w-[1200px]">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-center">

                    <!-- Image side -->
                    <div v-reveal="{ type: 'left' }" class="relative order-2 lg:order-1 h-full">
                        <div class="relative h-full p-4 sm:p-6">
                            <div class="pointer-events-none absolute inset-0 rounded-[2rem] border-2 border-[#F5B82E]"></div>

                            <div class="relative h-full min-h-[18rem] sm:min-h-[22rem] lg:min-h-[26rem] overflow-hidden rounded-[2rem] shadow-2xl shadow-[#0055B6]/20">
                                <img
                                    :src="aboutImage"
                                    :alt="about?.title || 'About school'"
                                    class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 hover:scale-105"
                                />
                            </div>
                        </div>

                        <div class="float-slow absolute bottom-0 right-0 sm:right-2 rounded-2xl bg-[#0B376B] px-5 py-4 text-white shadow-xl ring-1 ring-white/10">
                            <p class="text-3xl font-extrabold leading-none text-[#F5B82E]">{{ stats?.yearsOfExcellence ?? 0 }}+</p>
                            <p class="mt-1 text-xs font-medium text-white/80">Years of Excellence</p>
                        </div>
                    </div>

                    <!-- Text side -->
                    <div class="order-1 lg:order-2">
                        <p v-reveal class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-bold bg-[#0055B6]/10 dark:bg-[#60A5FA]/15 text-[#0055B6] dark:text-[#60A5FA]">
                            <span class="h-2 w-2 rounded-full bg-[#F5B82E]"></span>
                            About Our School
                        </p>

                        <h2 v-reveal="100" class="text-[#0B376B] dark:text-white mt-4 text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-tight">
                            {{ about?.title || 'Building a Better Future' }}
                            <span v-if="about?.subtitle" class="block text-[#0055B6] dark:text-[#60A5FA]">
                                {{ about.subtitle }}
                            </span>
                        </h2>

                        <p v-reveal="200" class="mt-5 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed whitespace-pre-line">
                            {{ about?.description || school?.short_description || 'Our school is committed to providing a safe, supportive and inspiring learning environment where every student can grow, learn and achieve their dreams.' }}
                        </p>

                        <div v-reveal="300" class="mt-8 flex flex-wrap items-center gap-3">
                            <Link
                                href="/contact"
                                class="about-brand-btn about-btn-shine inline-flex items-center gap-2 rounded-full px-7 py-3.5 text-sm font-bold text-white shadow-lg transition hover:brightness-110 active:scale-95"
                            >
                                Contact Us
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </Link>
                            <Link
                                href="/teachers"
                                class="inline-flex items-center gap-2 rounded-full border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-7 py-3.5 text-sm font-bold text-[#0055B6] dark:text-[#60A5FA] transition hover:border-[#0055B6]/40 dark:hover:border-[#60A5FA]/50 hover:bg-[#0055B6]/5 dark:hover:bg-[#60A5FA]/10 active:scale-95"
                            >
                                Meet Our Teachers
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- MISSION / VISION / VALUES -->
        <!-- ============================================================ -->
        <section
            v-if="about?.mission || about?.vision || about?.core_values"
            class="relative px-4 sm:px-6 lg:px-8 py-16 sm:py-20 bg-[#EBF4FF] dark:bg-slate-900 transition-colors duration-300"
        >
            <div class="mx-auto max-w-[1200px]">
                <div v-reveal class="text-center max-w-2xl mx-auto mb-12">
                    <p class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-bold bg-[#0055B6]/10 dark:bg-[#60A5FA]/15 text-[#0055B6] dark:text-[#60A5FA]">
                        <span class="h-2 w-2 rounded-full bg-[#F5B82E]"></span>
                        What Drives Us
                    </p>
                    <h2 class="text-[#0B376B] dark:text-white mt-4 text-3xl sm:text-4xl font-extrabold leading-tight">
                        Our Mission, Vision &amp; Values
                    </h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 sm:gap-6">

                    <!-- Mission -->
                    <div v-if="about?.mission" v-reveal="0" class="about-mvv-card group rounded-3xl bg-[#0B376B] p-6 sm:p-7 text-white ring-1 ring-white/10 dark:ring-white/15">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#F5B82E] text-[#0B376B] transition group-hover:rotate-12">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        </span>
                        <h3 class="mt-4 text-lg font-bold">Our Mission</h3>
                        <p class="mt-2 text-sm text-white/90 leading-relaxed whitespace-pre-line">{{ about.mission }}</p>
                    </div>

                    <!-- Vision -->
                    <div v-if="about?.vision" v-reveal="120" class="about-mvv-card group rounded-3xl bg-white dark:bg-slate-800 p-6 sm:p-7 ring-1 ring-slate-200 dark:ring-slate-700">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#0055B6]/10 dark:bg-[#60A5FA]/15 group-hover:bg-[#0055B6] dark:group-hover:bg-[#0055B6] transition group-hover:rotate-12">
                            <svg class="h-6 w-6 text-[#0055B6] dark:text-[#60A5FA] group-hover:text-white dark:group-hover:text-white transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                        </span>
                        <h3 class="text-[#0B376B] dark:text-white mt-4 text-lg font-bold">Our Vision</h3>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">{{ about.vision }}</p>
                    </div>

                    <!-- Core values -->
                    <div v-if="about?.core_values" v-reveal="240" class="about-mvv-card group rounded-3xl bg-white dark:bg-slate-800 p-6 sm:p-7 ring-1 ring-slate-200 dark:ring-slate-700">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#F5B82E] text-[#0B376B] transition group-hover:rotate-12">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                        </span>
                        <h3 class="text-[#0B376B] dark:text-white mt-4 text-lg font-bold">Our Values</h3>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">{{ about.core_values }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- SCHOOL HISTORY -->
        <!-- ============================================================ -->
        <section v-if="about?.history" class="relative px-4 sm:px-6 lg:px-8 py-20 sm:py-24">
            <div class="mx-auto max-w-[1200px]">
                <div v-reveal class="max-w-3xl mx-auto text-center">
                    <p class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-bold bg-[#0055B6]/10 dark:bg-[#60A5FA]/15 text-[#0055B6] dark:text-[#60A5FA]">
                        <span class="h-2 w-2 rounded-full bg-[#F5B82E]"></span>
                        Our Journey
                    </p>
                    <h2 class="text-[#0B376B] dark:text-white mt-4 text-3xl sm:text-4xl font-extrabold leading-tight">
                        School History
                    </h2>
                    <p class="mt-6 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed whitespace-pre-line text-left sm:text-center">
                        {{ about.history }}
                    </p>
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- TEACHERS PREVIEW -->
        <!-- ============================================================ -->
        <section v-if="teachers.length > 0" class="relative px-4 sm:px-6 lg:px-8 py-16 sm:py-20 bg-[#EBF4FF] dark:bg-slate-900 transition-colors duration-300">
            <div class="mx-auto max-w-[1200px]">

                <div v-reveal class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5 mb-10 sm:mb-12">
                    <div>
                        <p class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-bold bg-white dark:bg-slate-800 text-[#0055B6] dark:text-[#60A5FA] shadow-sm">
                            <span class="h-2 w-2 rounded-full bg-[#F5B82E]"></span>
                            Meet Our Team
                        </p>
                        <h2 class="text-[#0B376B] dark:text-white mt-4 text-3xl sm:text-4xl font-extrabold leading-tight">
                            Our Teachers
                        </h2>
                        <p class="mt-2 text-sm sm:text-base text-slate-700 dark:text-slate-200">
                            Experienced and dedicated team members.
                        </p>
                    </div>

                    <Link
                        href="/teachers"
                        class="about-brand-btn shrink-0 inline-flex items-center gap-2 rounded-full px-6 py-3 text-sm font-bold text-white shadow-lg transition hover:brightness-110 active:scale-95"
                    >
                        View All Teachers
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </Link>
                </div>

                <!-- TEACHER GRID (same card design as Teachers & Staff page, all cards equal height) -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 items-stretch gap-4 sm:gap-6">
                    <div
                        v-for="(teacher, i) in teachers"
                        :key="teacher.id"
                        v-reveal="{ delay: (i % 4) * 90, type: 'zoom' }"
                        class="about-teacher-card group relative flex h-full flex-col overflow-hidden rounded-3xl bg-white dark:bg-slate-800 border border-slate-200/70 dark:border-slate-700 shadow-md shadow-slate-200/70 dark:shadow-black/30 transition duration-500 hover:-translate-y-2 hover:shadow-2xl hover:shadow-[#0055B6]/20 dark:hover:shadow-black/60"
                    >
                        <!-- Top accent bar -->
                        <div class="absolute inset-x-0 top-0 z-20 h-1 origin-left scale-x-0 bg-gradient-to-r from-[#0055B6] via-[#1883DB] to-[#F5B82E] transition-transform duration-500 group-hover:scale-x-100"></div>

                        <!-- Photo (fixed square, same for every card) -->
                        <div class="relative aspect-square w-full shrink-0 overflow-hidden bg-gradient-to-br from-[#EBF4FF] to-[#DCEBFF] dark:from-slate-700 dark:to-slate-800 transition-colors duration-300">
                            <img
                                v-if="teacher.photo"
                                :src="`/storage/${teacher.photo}`"
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
                            <h3 class="truncate text-sm font-black leading-5 text-[#0B376B] dark:text-white group-hover:text-[#0055B6] dark:group-hover:text-[#60A5FA] transition-colors">
                                {{ teacher.name }}
                            </h3>
                            <p class="mt-0.5 truncate text-xs font-bold leading-4 text-[#B45309] dark:text-[#F5B82E]">
                                {{ teacher.designation || 'Teacher' }}
                            </p>

                            <!-- Fixed-height slots: keep alignment even when data is missing -->
                            <p class="mt-0.5 h-4 truncate text-[11px] leading-4 text-slate-600 dark:text-slate-300">
                                {{ teacher.qualification || '\u00A0' }}
                            </p>
                            <p class="h-4 truncate text-[10px] leading-4 text-slate-500 dark:text-slate-400">
                                {{ teacher.department ? `${teacher.department} Department` : '\u00A0' }}
                            </p>

                            <!-- Contact buttons (always at the bottom, fixed height) -->
                            <div class="mt-auto flex h-8 items-center justify-center gap-2 pt-0 mt-3">
                                <!-- Call -->
                                <a
                                    v-if="teacher.phone"
                                    :href="`tel:${teacher.phone}`"
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#EBF4FF] dark:bg-slate-700 text-[#0055B6] dark:text-[#60A5FA] hover:bg-gradient-to-br hover:from-[#0055B6] hover:to-[#1883DB] hover:text-white dark:hover:text-white hover:-translate-y-0.5 hover:shadow-md hover:shadow-[#0055B6]/30 transition"
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
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#E7F9EE] dark:bg-slate-700 text-[#25D366] hover:bg-[#25D366] hover:text-white dark:hover:text-white hover:-translate-y-0.5 hover:shadow-md hover:shadow-[#25D366]/40 transition"
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
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#EBF4FF] dark:bg-slate-700 text-[#0055B6] dark:text-[#60A5FA] hover:bg-gradient-to-br hover:from-[#0055B6] hover:to-[#1883DB] hover:text-white dark:hover:text-white hover:-translate-y-0.5 hover:shadow-md hover:shadow-[#0055B6]/30 transition"
                                    :title="teacher.email"
                                    aria-label="Email"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </a>

                                <span
                                    v-if="!teacher.phone && !teacher.email"
                                    class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#EBF4FF] dark:bg-slate-700 text-[#0055B6] dark:text-[#60A5FA]"
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
        </section>

        <!-- ============================================================ -->
        <!-- CTA STRIP -->
        <!-- ============================================================ -->
        <section class="px-4 sm:px-6 lg:px-8 pb-20 sm:pb-24 pt-16 sm:pt-20">
            <div v-reveal="{ type: 'zoom' }" class="relative mx-auto max-w-[1200px] overflow-hidden rounded-[2rem] about-gradient-brand px-6 py-12 sm:px-12 sm:py-16 text-center">
                <div class="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-white/10 blur-3xl"></div>
                <div class="relative">
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white leading-tight">
                        Want your child to learn with us?
                    </h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm sm:text-base text-white/80">
                        Visit our campus, meet our teachers and see how we help every child grow.
                    </p>
                    <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                        <Link href="/contact" class="about-btn-shine inline-flex items-center justify-center rounded-full bg-[#F5B82E] px-8 py-3.5 text-sm sm:text-base font-bold text-[#1f2937] shadow-lg transition hover:brightness-105 active:scale-95">
                            Contact Us
                        </Link>
                        <Link href="/" class="inline-flex items-center justify-center rounded-full border-2 border-white/60 px-8 py-3.5 text-sm sm:text-base font-bold text-white transition hover:bg-white/10 hover:border-white active:scale-95">
                            Back to Home
                        </Link>
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
/* ============================================================
   Every rule is scoped to `.about-page` so nothing leaks.
   ============================================================ */

.about-page .about-gradient-brand {
    background: linear-gradient(135deg,
        var(--brand-dark) 0%,
        var(--brand) 55%,
        var(--brand-mid) 100%);
}

/* Hero overlay — for readability when image is present */
.about-page .about-hero-overlay {
    background: linear-gradient(
        to bottom,
        color-mix(in srgb, var(--brand-dark) 75%, transparent) 0%,
        color-mix(in srgb, var(--brand-dark) 55%, transparent) 50%,
        color-mix(in srgb, var(--brand-dark) 80%, transparent) 100%
    );
}

.about-page .about-accent-bar {
    background: var(--accent);
}

.about-page .about-card {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.about-page .about-heading {
    color: var(--brand-dark);
}
.about-page .about-heading-alt {
    color: var(--brand);
}
:global(.dark) .about-page .about-heading,
:global([data-theme='dark']) .about-page .about-heading {
    color: #F1F5F9;
}
:global(.dark) .about-page .about-heading-alt,
:global([data-theme='dark']) .about-page .about-heading-alt {
    color: #93C5FD;
}

.about-page .about-pill {
    background: color-mix(in srgb, var(--brand) 10%, transparent);
    color: var(--brand);
}
:global(.dark) .about-page .about-pill,
:global([data-theme='dark']) .about-page .about-pill {
    background: color-mix(in srgb, #60A5FA 15%, transparent);
    color: #93C5FD;
}

.about-page .about-icon-circle {
    background: color-mix(in srgb, var(--brand) 10%, transparent);
}
.about-page .about-icon {
    color: var(--brand);
}
.about-page .about-card:hover .about-icon-circle,
.about-page .about-mvv-card:hover .about-icon-circle {
    background: var(--brand);
}
.about-page .about-card:hover .about-icon,
.about-page .about-mvv-card:hover .about-icon {
    color: #ffffff;
}
:global(.dark) .about-page .about-icon-circle,
:global([data-theme='dark']) .about-page .about-icon-circle {
    background: color-mix(in srgb, #60A5FA 15%, transparent);
}
:global(.dark) .about-page .about-icon,
:global([data-theme='dark']) .about-page .about-icon {
    color: #60A5FA;
}

.about-page .about-brand-btn {
    background: var(--brand);
}
.about-page .about-brand-btn:hover {
    box-shadow: 0 12px 24px -10px color-mix(in srgb, var(--brand) 40%, transparent);
}

.about-page .about-tint-bg {
    background: var(--brand-tint);
}
:global(.dark) .about-page .about-tint-bg,
:global([data-theme='dark']) .about-page .about-tint-bg {
    background: #0F172A;
}
.about-page .about-tint-2 {
    background: var(--brand-tint);
}
:global(.dark) .about-page .about-tint-2,
:global([data-theme='dark']) .about-page .about-tint-2 {
    background: #1E293B;
}

.about-page .about-text-brand {
    color: var(--brand);
}
:global(.dark) .about-page .about-text-brand,
:global([data-theme='dark']) .about-page .about-text-brand {
    color: #60A5FA;
}

.about-page .about-mvv-card {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.about-page .about-mvv-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.25);
}

.about-page .about-teacher-card {
    transition: transform 0.5s ease, box-shadow 0.5s ease;
}

.about-page .reveal {
    opacity: 0;
    transform: translateY(28px);
    transition: opacity 0.8s cubic-bezier(0.22, 1, 0.36, 1), transform 0.8s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.about-page .reveal-left  { transform: translateX(-40px); }
.about-page .reveal-right { transform: translateX(40px); }
.about-page .reveal-zoom  { transform: scale(0.92); }
.about-page .reveal.is-visible { opacity: 1; transform: none; }

@media (max-width: 1023px) {
    .about-page .reveal-left,
    .about-page .reveal-right { transform: translateY(28px); }
}

.about-page .about-anim-up {
    opacity: 0;
    animation: aboutFadeUp 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes aboutFadeUp {
    from { opacity: 0; transform: translateY(28px); }
    to   { opacity: 1; transform: translateY(0); }
}

.about-page .float-slow { animation: aboutFloatY 5s ease-in-out infinite; }
@keyframes aboutFloatY {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-10px); }
}

.about-page .about-btn-shine { position: relative; overflow: hidden; }
.about-page .about-btn-shine::after {
    content: '';
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.45), transparent);
    transform: skewX(-20deg);
    transition: left 0.7s ease;
}
.about-page .about-btn-shine:hover::after { left: 130%; }

@media (prefers-reduced-motion: reduce) {
    .about-page .reveal,
    .about-page .about-anim-up { opacity: 1 !important; transform: none !important; transition: none !important; animation: none !important; }
    .about-page .float-slow { animation: none !important; }
}
</style>
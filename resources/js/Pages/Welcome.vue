<script setup>
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import PublicNavbar from '@/Components/PublicNavbar.vue'
import PublicFooter from '@/Components/PublicFooter.vue'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
    canLogin:       { type: Boolean, default: false },
    canRegister:    { type: Boolean, default: false },
    laravelVersion: { type: String,  required: true },
    phpVersion:     { type: String,  required: true },
    hero:           { type: Object, default: null },
    about:          { type: Object, default: null },
    notices:        { type: Object, default: () => ({ data: [], meta: {} }) },
    events:         { type: Object, default: () => ({ data: [], meta: {} }) },
    teachers:       { type: Array,  default: () => [] },
    teachersCount:  { type: Number, default: 0 },
    stats:          { type: Object, default: () => ({}) },
    galleries:      { type: Object, default: () => ({ data: [], meta: {} }) },
})

const page = usePage()
const user   = computed(() => page.props.auth?.user)
const school = computed(() => page.props.school ?? {})
const contact = computed(() => page.props.contact ?? {})

const { initTheme } = useTheme()

const faviconUrl = computed(() =>
    school.value?.logo ? `/storage/${school.value.logo}` : null
)

const year = new Date().getFullYear()

const quickLinks = [
    { name: 'Home',     href: '/' },
    { name: 'About',    href: '/about' },
    { name: 'Result',   href: '/result' },
    { name: 'Teachers', href: '/teachers' },
    { name: 'Contact',  href: '/contact' },
]

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

const heroImage = computed(() =>
    props.hero?.image ? `/storage/${props.hero.image}` : null
)

const aboutImage = computed(() =>
    props.about?.image ? `/storage/${props.about.image}` : null
)

const button1Href = computed(() => {
    if (!props.hero?.button1?.show) return null
    const btn = props.hero.button1
    return btn.type === 'external' ? (btn.url || '#') : (btn.page ? `/${btn.page}` : '/')
})

const button2Href = computed(() => {
    if (!props.hero?.button2?.show) return null
    const btn = props.hero.button2
    return btn.type === 'external' ? (btn.url || '#') : (btn.page ? `/${btn.page}` : '/')
})

const formatDate = (d) => {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
const eventDay = (d) => !d ? '' : new Date(d).getDate()
const eventMonth = (d) => !d ? '' : new Date(d).toLocaleDateString('en-GB', { month: 'short' })

const whatsappLink = (phone) => {
    if (!phone) return null
    let digits = String(phone).replace(/[^\d]/g, '')
    if (!digits) return null
    if (digits.startsWith('00')) digits = digits.slice(2)
    if (digits.startsWith('0')) digits = '88' + digits
    return `https://wa.me/${digits}`
}

const placeholderImg = 'data:image/svg+xml;utf8,' + encodeURIComponent(`
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="500" viewBox="0 0 400 500">
  <rect width="400" height="500" fill="#EBF4FF"/>
  <circle cx="200" cy="200" r="70" fill="#93C5FD" opacity="0.6"/>
  <path d="M200 130a70 70 0 100 140 70 70 0 000-140zm0 90a20 20 0 110-40 20 20 0 010 40zm0 20c30 0 55 20 60 45H140c5-25 30-45 60-45z" fill="#0055B6" opacity="0.8"/>
  <text x="200" y="400" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" fill="#0055B6" opacity="0.7">No Image</text>
</svg>
`)

const eventImages = (event) => {
    if (!event) return []
    if (Array.isArray(event.event_images) && event.event_images.length) {
        return event.event_images
            .filter(Boolean)
            .map((p) => (typeof p === 'string' && p.startsWith('http') ? p : `/storage/${p}`))
    }
    if (event.event_image) {
        return [`/storage/${event.event_image}`]
    }
    return []
}

const eventCover = (event) => eventImages(event)[0] || null
const eventImageCount = (event) => eventImages(event).length

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

const iconPaths = {
    students: 'M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z',
    teachers: 'M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4',
    classes:  'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    years:    'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.05 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
}

const stats = [
    { icon: 'students', target: Number(props.stats?.students          ?? 0), suffix: '+', label: 'Total Students' },
    { icon: 'teachers', target: Number(props.stats?.teachers          ?? 0), suffix: '+', label: 'Total Teachers' },
    { icon: 'classes',  target: Number(props.stats?.classes           ?? 0), suffix: '',  label: 'Classes' },
    { icon: 'years',    target: Number(props.stats?.yearsOfExcellence ?? 0), suffix: '+', label: 'Years of Excellence' },
]
const counts = ref(stats.map(() => 0))
const statsEl = ref(null)
let statsObserver = null
let statsStarted = false

const runCountUp = () => {
    if (statsStarted) return
    statsStarted = true
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (reduce) { counts.value = stats.map(s => s.target); return }
    const duration = 1600
    const start = performance.now()
    const tick = (now) => {
        const p = Math.min((now - start) / duration, 1)
        const eased = 1 - Math.pow(1 - p, 3)
        counts.value = stats.map(s => Math.round(s.target * eased))
        if (p < 1) requestAnimationFrame(tick)
    }
    requestAnimationFrame(tick)
}

const reasons = [
    { title: 'Experienced & Dedicated Teachers', text: 'Our teachers are qualified, passionate and committed.', icon: 'M12 14l9-5-9-5-9 5 9 5z' },
    { title: 'Modern Learning Facilities',       text: 'Well-equipped classrooms, labs and digital learning.', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
    { title: 'Safe & Friendly Environment',      text: 'A secure and caring campus for every child.', icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
    { title: 'Focus on Overall Development',     text: 'Academic, moral, social and creative growth.', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
]

// ===== GALLERY LAYOUT (must be declared before galleryImages) =====
const galleryLayout = [
    'col-span-2 row-span-2', // #1  - big (top-left)
    '',                      // #2  - small
    '',                      // #3  - small
    '',                      // #4  - small (row 2)
    '',                      // #5  - small (row 2)
    'col-span-2 row-span-2', // #6  - big (right side, row 3-4)
    'col-span-2 row-span-2', // #7  - big (left side, row 5-6)
    '',                      // #8  - small
    '',                      // #9  - small
    '',                      // #10 - small
    '',                      // #11 - small (extra to make last row 3)
]

// ===== NOTICES (paginated) =====
const noticeList = computed(() => {
    const items = Array.isArray(props.notices?.data) ? props.notices.data : []
    return items.filter(n => n?.title)
})
const noticesMeta = computed(() => props.notices?.meta ?? {})

const goToNoticesPage = (p) => {
    const last = noticesMeta.value?.last_page || 1
    const current = noticesMeta.value?.current_page || 1
    if (!p || p < 1 || p > last || p === current) return

    const params = new URLSearchParams(window.location.search)
    params.set('notices_page', String(p))

    router.get(
        window.location.pathname + '?' + params.toString(),
        {},
        { preserveScroll: true, preserveState: true, only: ['notices'] }
    )
}

// ===== EVENTS (paginated) =====
const eventList = computed(() => {
    const items = Array.isArray(props.events?.data) ? props.events.data : []
    return items.filter(e => e?.title)
})
const eventsMeta = computed(() => props.events?.meta ?? {})

const goToEventsPage = (p) => {
    const last = eventsMeta.value?.last_page || 1
    const current = eventsMeta.value?.current_page || 1
    if (!p || p < 1 || p > last || p === current) return

    const params = new URLSearchParams(window.location.search)
    params.set('events_page', String(p))

    router.get(
        window.location.pathname + '?' + params.toString(),
        {},
        { preserveScroll: true, preserveState: true, only: ['events'] }
    )
}

// ===== GALLERY (paginated) =====
const galleryImages = computed(() => {
    const items = Array.isArray(props.galleries?.data) ? props.galleries.data : []
    return items
        .filter(g => g?.image)
        .map((g, i) => ({
            id: g.id,
            src: g.image.startsWith('http') ? g.image : `/storage/${g.image.replace(/^\/+/, '')}`,
            title: g.title || 'Gallery image',
            layout: galleryLayout[i % galleryLayout.length] || '',
        }))
})

const galleryMeta = computed(() => props.galleries?.meta ?? {})

const goToGalleryPage = (page) => {
    const last = galleryMeta.value?.last_page || 1
    const current = galleryMeta.value?.current_page || 1
    if (!page || page < 1 || page > last || page === current) return

    const params = new URLSearchParams(window.location.search)
    params.set('gallery_page', String(page))

    router.get(
        window.location.pathname + '?' + params.toString(),
        {},
        { preserveScroll: true, preserveState: true, only: ['galleries'] }
    )
}

// ✅ Page number list (reusable compact window)
const makePaginationWindow = (meta) => {
    const current = meta?.current_page || 1
    const last = meta?.last_page || 1
    const windowSize = 2

    const pages = []
    const start = Math.max(1, current - windowSize)
    const end = Math.min(last, current + windowSize)

    if (start > 1) {
        pages.push(1)
        if (start > 2) pages.push('...')
    }
    for (let p = start; p <= end; p++) pages.push(p)
    if (end < last) {
        if (end < last - 1) pages.push('...')
        pages.push(last)
    }
    return pages
}

const noticesPaginationWindow = computed(() => makePaginationWindow(noticesMeta.value))
const eventsPaginationWindow  = computed(() => makePaginationWindow(eventsMeta.value))
const galleryPaginationWindow = computed(() => makePaginationWindow(galleryMeta.value))

const lightbox = ref(null)
const openLightbox = (i) => { lightbox.value = i }
const closeLightbox = () => { lightbox.value = null }
const stepLightbox = (dir) => {
    if (lightbox.value === null) return
    const len = galleryImages.value.length
    if (!len) return
    lightbox.value = (lightbox.value + dir + len) % len
}

const eventLightbox = ref(null)

const openEventLightbox = (event, startIndex = 0) => {
    const imgs = eventImages(event)
    if (!imgs.length) return
    eventLightbox.value = {
        images: imgs,
        index: Math.max(0, Math.min(startIndex, imgs.length - 1)),
        title: event?.title || 'Event',
    }
}
const closeEventLightbox = () => { eventLightbox.value = null }
const stepEventLightbox = (dir) => {
    if (!eventLightbox.value) return
    const len = eventLightbox.value.images.length
    if (!len) return
    eventLightbox.value.index = (eventLightbox.value.index + dir + len) % len
}
const setEventLightbox = (i) => {
    if (!eventLightbox.value) return
    const len = eventLightbox.value.images.length
    if (!len) return
    eventLightbox.value.index = (i + len) % len
}

// ===== EVENT MODAL =====
const eventModal = ref(null)
const openEventModal = (event) => {
    if (!event) return
    eventModal.value = event
}
const closeEventModal = () => { eventModal.value = null }
const onEventModalBackdropClick = (e) => {
    if (e.target === e.currentTarget) closeEventModal()
}

// ===== NOTICE MODAL =====
const noticeModal = ref(null)
const openNoticeModal = (notice) => {
    if (!notice) return
    noticeModal.value = notice
}
const closeNoticeModal = () => { noticeModal.value = null }
const onNoticeBackdropClick = (e) => {
    if (e.target === e.currentTarget) closeNoticeModal()
}

const onEventBackdropClick = (e) => {
    if (e.target === e.currentTarget) {
        closeEventLightbox()
    }
}
const onGalleryBackdropClick = (e) => {
    if (e.target === e.currentTarget) {
        closeLightbox()
    }
}

const onKey = (e) => {
    if (noticeModal.value) {
        if (e.key === 'Escape') closeNoticeModal()
        return
    }
    if (eventModal.value) {
        if (e.key === 'Escape') closeEventModal()
        return
    }
    if (eventLightbox.value) {
        if (e.key === 'Escape') closeEventLightbox()
        if (e.key === 'ArrowRight') stepEventLightbox(1)
        if (e.key === 'ArrowLeft') stepEventLightbox(-1)
        return
    }
    if (lightbox.value === null) return
    if (e.key === 'Escape') closeLightbox()
    if (e.key === 'ArrowRight') stepLightbox(1)
    if (e.key === 'ArrowLeft') stepLightbox(-1)
}

const scrollTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })

onMounted(() => {
    initTheme()
    window.addEventListener('keydown', onKey)

    const setFavicon = () => {
        if (!faviconUrl.value) return
        let link = document.querySelector("link[rel~='icon']")
        if (!link) {
            link = document.createElement('link')
            link.rel = 'icon'
            document.head.appendChild(link)
        }
        link.type = 'image/png'
        link.href = faviconUrl.value
    }
    setFavicon()

    if (statsEl.value && 'IntersectionObserver' in window) {
        statsObserver = new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) {
                runCountUp()
                statsObserver.disconnect()
            }
        }, { threshold: 0.3 })
        statsObserver.observe(statsEl.value)
    } else {
        counts.value = stats.map(s => s.target)
    }
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey)
    statsObserver?.disconnect()
    revealObserver?.disconnect()
    revealObserver = null
})
</script>

<template>
    <Head :title="school?.name ?? 'Welcome'">
        <link v-if="faviconUrl" rel="icon" type="image/png" :href="faviconUrl" />
        <link v-if="faviconUrl" rel="shortcut icon" type="image/png" :href="faviconUrl" />
        <link v-if="faviconUrl" rel="apple-touch-icon" :href="faviconUrl" />
    </Head>

    <div
        :style="brandVars"
        class="school-page min-h-screen bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 transition-colors duration-300 overflow-x-hidden"
    >

        <div class="fixed top-0 left-0 right-0 z-50">
            <PublicNavbar />
        </div>

        <div class="h-16 xs:h-[72px] sm:h-20 lg:h-[88px]" aria-hidden="true"></div>

        <!-- HERO SECTION -->
        <section class="relative overflow-hidden">
            <div class="absolute inset-0">
                <img
                    v-if="heroImage"
                    :src="heroImage"
                    :alt="hero?.image_alt || 'Hero image'"
                    class="h-full w-full object-cover"
                />
                <div
                    v-else
                    class="h-full w-full gradient-brand"
                ></div>

                <div class="absolute inset-0 hero-overlay"></div>
            </div>

            <div class="relative mx-auto max-w-[1320px] px-4 sm:px-6 lg:px-8 py-12 sm:py-16 md:py-20 lg:py-24 xl:py-32">
                <div class="max-w-3xl text-left">
                    <p class="anim-up text-[10px] sm:text-xs lg:text-sm font-bold text-[#93C5FD] uppercase tracking-[0.2em] mb-2 sm:mb-3" style="animation-delay: 0.05s">
                        Welcome To
                    </p>

                    <h1 class="anim-up text-2xl min-[360px]:text-3xl min-[400px]:text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-extrabold text-white leading-[1.15] sm:leading-[1.1] tracking-tight" style="animation-delay: 0.15s">
                        {{ hero?.title || school?.name || 'Childhome Academy' }}
                    </h1>

                    <p
                        v-if="hero?.subtitle"
                        class="anim-up mt-3 sm:mt-5 lg:mt-6 text-sm sm:text-base lg:text-lg xl:text-xl text-white/90 leading-relaxed max-w-xl"
                        style="animation-delay: 0.3s"
                    >
                        {{ hero.subtitle }}
                    </p>

                    <div class="anim-up mt-6 sm:mt-8 lg:mt-10 flex flex-col sm:flex-row gap-2.5 sm:gap-3 lg:gap-4" style="animation-delay: 0.45s">
                        <a
                            v-if="button1Href"
                            :href="button1Href"
                            class="inline-flex items-center justify-center gap-2 rounded-full bg-white px-5 sm:px-6 lg:px-8 py-2.5 sm:py-3.5 lg:py-4 text-sm sm:text-base font-bold text-brand-fixed shadow-lg shadow-black/20 transition hover:bg-slate-100 hover:shadow-xl hover:scale-[1.02] active:scale-95 cursor-pointer"
                        >
                            <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            {{ hero.button1.text }}
                        </a>

                        <a
                            v-if="button2Href"
                            :href="button2Href"
                            class="inline-flex items-center justify-center gap-2 rounded-full border-2 border-white/60 bg-transparent px-5 sm:px-6 lg:px-8 py-2.5 sm:py-3.5 lg:py-4 text-sm sm:text-base font-bold text-white backdrop-blur-sm transition hover:bg-white/10 hover:border-white hover:scale-[1.02] active:scale-95 cursor-pointer"
                        >
                            {{ hero.button2.text }}
                            <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- STATISTICS STRIP -->
        <section ref="statsEl" class="relative -mt-6 min-[360px]:-mt-8 sm:-mt-12 lg:-mt-14 z-10 px-3 min-[400px]:px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-[1200px]">
                <div v-reveal class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-3 lg:gap-5">
                    <div
                        v-for="(stat, i) in stats"
                        :key="stat.label"
                        class="group relative overflow-hidden rounded-2xl bg-white dark:bg-slate-800 p-3 min-[400px]:p-3.5 sm:p-5 lg:p-6 shadow-xl shadow-slate-300/40 dark:shadow-black/40 ring-1 ring-slate-100 dark:ring-slate-700 transition duration-300 hover:-translate-y-1.5"
                    >
                        <div class="absolute inset-x-0 top-0 h-1 bg-accent"></div>
                        <div class="flex items-center gap-2 min-[400px]:gap-2.5 sm:gap-3 lg:gap-4">
                            <div class="flex h-9 w-9 min-[400px]:h-10 min-[400px]:w-10 sm:h-12 sm:w-12 lg:h-14 lg:w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-soft transition duration-300 group-hover:bg-brand group-hover:rotate-6">
                                <svg class="h-4 w-4 min-[400px]:h-5 min-[400px]:w-5 sm:h-6 sm:w-6 text-[#0055B6] dark:text-[#60A5FA] transition group-hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPaths[stat.icon]" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-lg min-[400px]:text-xl sm:text-2xl lg:text-3xl font-extrabold text-[#0B376B] dark:text-white leading-none tabular-nums">
                                    {{ counts[i] }}{{ stat.suffix }}
                                </p>
                                <p class="mt-1 text-[9px] min-[400px]:text-[10px] sm:text-xs lg:text-sm text-slate-600 dark:text-slate-200 font-medium leading-tight">
                                    {{ stat.label }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ABOUT PREVIEW -->
        <section class="relative overflow-hidden py-12 sm:py-16 md:py-20 lg:py-24 xl:py-28 px-4 sm:px-6 lg:px-8 bg-white dark:bg-slate-950 transition-colors duration-300">
            <div class="blob blob-a bg-brand-soft"></div>

            <div class="relative mx-auto max-w-[1200px]">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 sm:gap-10 lg:gap-14 xl:gap-16 items-center">

                    <div v-reveal="{ type: 'left' }" class="relative order-2 lg:order-1 h-full">
                        <div class="relative h-full p-3 sm:p-4 lg:p-6">
                            <div class="pointer-events-none absolute inset-0 rounded-[2rem] border-2 border-accent"></div>

                            <div class="relative h-full min-h-[13rem] min-[400px]:min-h-[14rem] sm:min-h-[18rem] lg:min-h-[22rem] xl:min-h-[26rem] overflow-hidden rounded-[2rem] shadow-2xl shadow-[#0055B6]/20">
                                <img
                                    v-if="aboutImage"
                                    :src="aboutImage"
                                    :alt="about?.title || 'About school'"
                                    class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 hover:scale-105"
                                />
                                <img
                                    v-else
                                    src="https://images.unsplash.com/photo-1562774053-701939374585?w=900&q=80"
                                    alt="School campus"
                                    class="absolute inset-0 h-full w-full object-cover transition-transform duration-700 hover:scale-105"
                                />
                            </div>
                        </div>

                        <div class="float-slow absolute bottom-0 right-0 sm:right-2 rounded-2xl bg-brand-dark px-3 sm:px-5 py-2.5 sm:py-4 text-white shadow-xl ring-1 ring-white/10">
                            <p class="text-lg sm:text-2xl lg:text-3xl font-extrabold leading-none text-accent">{{ props.stats?.yearsOfExcellence ?? 0 }}+</p>
                            <p class="mt-1 text-[9px] sm:text-xs font-medium text-white/90">Years of Excellence</p>
                        </div>
                    </div>

                    <div class="order-1 lg:order-2">
                        <p v-reveal class="inline-flex items-center gap-2 rounded-full bg-brand-soft px-3 sm:px-4 py-1.5 text-[11px] sm:text-sm font-bold text-[#0055B6] dark:text-[#60A5FA]">
                            <span class="h-2 w-2 rounded-full bg-accent"></span>
                            About Our School
                        </p>

                        <h2 v-reveal="100" class="mt-3 sm:mt-4 text-xl min-[400px]:text-2xl sm:text-4xl lg:text-5xl font-extrabold text-[#0B376B] dark:text-white leading-tight">
                            {{ about?.title || 'Building a Better Future' }}
                            <span v-if="about?.subtitle" class="block text-[#0055B6] dark:text-[#60A5FA]">
                                {{ about.subtitle }}
                            </span>
                        </h2>

                        <p v-reveal="200" class="mt-4 sm:mt-5 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed whitespace-pre-line">
                            {{ about?.description || school?.short_description || 'Childhome Academy is committed to providing a safe, supportive and inspiring learning environment where every student can grow, learn and achieve their dreams.' }}
                        </p>

                        <div v-reveal="300">
                            <Link
                                href="/about"
                                class="btn-shine mt-5 sm:mt-8 inline-flex items-center gap-2 rounded-full bg-brand px-5 sm:px-7 py-2.5 sm:py-3.5 text-sm font-bold text-white shadow-lg shadow-[#0055B6]/25 transition hover:brightness-110 hover:shadow-xl active:scale-95 cursor-pointer"
                            >
                                Read More
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </Link>
                        </div>

                        <div
                            v-if="!about?.mission"
                            v-reveal="400"
                            class="mt-5 sm:mt-8 rounded-2xl bg-tint-2 border-l-4 border-accent p-4 sm:p-5"
                        >
                            <p class="text-sm sm:text-base italic text-[#0B376B] dark:text-white leading-relaxed">
                                "Education is the most powerful weapon which you can use to change the world."
                            </p>
                            <p class="mt-2 text-xs text-slate-600 dark:text-slate-300 font-semibold">— Nelson Mandela</p>
                        </div>
                    </div>
                </div>

                <div v-if="about?.mission || about?.vision || about?.core_values" class="mt-12 sm:mt-16 lg:mt-20 grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-5 lg:gap-6">
                    <div v-if="about?.mission" v-reveal="0" class="mvv-card group rounded-3xl bg-brand-dark p-5 sm:p-6 lg:p-7 text-white ring-1 ring-white/10">
                        <span class="flex h-11 w-11 sm:h-12 sm:w-12 items-center justify-center rounded-2xl bg-accent text-brand-dark transition group-hover:rotate-12">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        </span>
                        <h3 class="mt-3 sm:mt-4 text-base sm:text-lg font-bold">Our Mission</h3>
                        <p class="mt-2 text-sm text-white/90 leading-relaxed line-clamp-5">{{ about.mission }}</p>
                    </div>

                    <div v-if="about?.vision" v-reveal="120" class="mvv-card group rounded-3xl bg-[#EBF4FF] dark:bg-slate-800 p-5 sm:p-6 lg:p-7 ring-1 ring-[#0055B6]/10 dark:ring-slate-700">
                        <span class="flex h-11 w-11 sm:h-12 sm:w-12 items-center justify-center rounded-2xl bg-brand text-white transition group-hover:rotate-12">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                        </span>
                        <h3 class="mt-3 sm:mt-4 text-base sm:text-lg font-bold text-[#0B376B] dark:text-white">Our Vision</h3>
                        <p class="mt-2 text-sm text-slate-700 dark:text-slate-200 leading-relaxed line-clamp-5">{{ about.vision }}</p>
                    </div>

                    <div v-if="about?.core_values" v-reveal="240" class="mvv-card group rounded-3xl bg-white dark:bg-slate-800 p-5 sm:p-6 lg:p-7 ring-1 ring-slate-200 dark:ring-slate-700">
                        <span class="flex h-11 w-11 sm:h-12 sm:w-12 items-center justify-center rounded-2xl bg-accent text-brand-dark transition group-hover:rotate-12">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                        </span>
                        <h3 class="mt-3 sm:mt-4 text-base sm:text-lg font-bold text-[#0B376B] dark:text-white">Our Values</h3>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-5">{{ about.core_values }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- TEACHERS & STAFF -->
        <section class="px-4 sm:px-6 lg:px-8 py-12 sm:py-16 md:py-20 lg:py-24 bg-[#EBF4FF] dark:bg-slate-900 transition-colors duration-300">
            <div class="mx-auto max-w-[1200px]">

                <div v-reveal class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 sm:gap-5 mb-8 sm:mb-10 lg:mb-12">
                    <div>
                        <p class="inline-flex items-center gap-2 rounded-full bg-white dark:bg-slate-800 px-3 sm:px-4 py-1.5 text-[11px] sm:text-sm font-bold text-[#0055B6] dark:text-[#60A5FA] shadow-sm">
                            <span class="h-2 w-2 rounded-full bg-accent"></span>
                            Meet Our Team
                        </p>
                        <h2 class="mt-3 sm:mt-4 text-xl min-[400px]:text-2xl sm:text-4xl lg:text-5xl font-extrabold text-[#0B376B] dark:text-white leading-tight">
                            Our Teachers &amp; Staff
                        </h2>
                        <p class="mt-1.5 sm:mt-2 text-sm sm:text-base text-slate-700 dark:text-slate-200">
                            Experienced and dedicated team members.
                        </p>
                    </div>

                    <Link
                        v-if="teachers.length > 0"
                        href="/teachers"
                        class="shrink-0 inline-flex items-center gap-2 rounded-full bg-brand px-5 sm:px-6 py-2.5 sm:py-3 text-sm font-bold text-white shadow-lg shadow-[#0055B6]/20 transition hover:brightness-110 active:scale-95 self-start sm:self-auto cursor-pointer"
                    >
                        View All Teachers
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </Link>
                </div>

                <!-- TEACHER GRID -->
                <div
                    v-if="teachers.length > 0"
                    class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 items-stretch gap-2.5 sm:gap-4 lg:gap-6"
                >
                    <div
                        v-for="(teacher, i) in teachers"
                        :key="teacher.id"
                        v-reveal="{ delay: (i % 4) * 90, type: 'zoom' }"
                        class="teacher-card group relative flex h-full flex-col overflow-hidden rounded-3xl bg-white dark:bg-slate-800 border border-slate-200/70 dark:border-slate-700 shadow-md shadow-slate-200/70 dark:shadow-black/30 transition duration-500 hover:-translate-y-2 hover:shadow-2xl hover:shadow-[#0055B6]/20 dark:hover:shadow-black/60"
                    >
                        <div class="absolute inset-x-0 top-0 z-20 h-1 origin-left scale-x-0 bg-gradient-to-r from-[#0055B6] via-[#1883DB] to-[#F5B82E] transition-transform duration-500 group-hover:scale-x-100"></div>

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

                            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/40 to-transparent opacity-70 transition-opacity duration-300 group-hover:opacity-100"></div>

                            <span
                                v-if="teacher.department"
                                class="absolute left-2.5 top-2.5 max-w-[85%] truncate rounded-full bg-white/95 dark:bg-slate-900/90 px-2.5 py-1 text-[9px] sm:text-[10px] font-bold uppercase tracking-wide text-[#0055B6] dark:text-[#60A5FA] shadow-md ring-1 ring-black/5 dark:ring-white/10 backdrop-blur-sm"
                            >
                                {{ teacher.department }}
                            </span>
                        </div>

                        <div class="relative flex flex-1 flex-col p-3 text-center">
                            <h3 class="truncate text-sm font-black leading-5 text-[#0B376B] dark:text-white group-hover:text-[#0055B6] dark:group-hover:text-[#60A5FA] transition-colors">
                                {{ teacher.name }}
                            </h3>
                            <p class="mt-0.5 truncate text-xs font-bold leading-4 text-[#B45309] dark:text-[#F5B82E]">
                                {{ teacher.designation || 'Teacher' }}
                            </p>

                            <p class="mt-0.5 h-4 truncate text-[11px] leading-4 text-slate-600 dark:text-slate-300">
                                {{ teacher.qualification || '\u00A0' }}
                            </p>
                            <p class="h-4 truncate text-[10px] leading-4 text-slate-500 dark:text-slate-400">
                                {{ teacher.department ? `${teacher.department} Department` : '\u00A0' }}
                            </p>

                            <div class="mt-auto flex h-8 items-center justify-center gap-2 pt-0 mt-3">
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

                                <a
                                    v-if="whatsappLink(teacher.phone)"
                                    :href="whatsappLink(teacher.phone)"
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

                <div v-else v-reveal class="rounded-3xl bg-white dark:bg-slate-800 ring-1 ring-slate-100 dark:ring-slate-700 shadow-sm p-8 sm:p-12 lg:p-16 text-center">
                    <div class="mx-auto flex h-14 sm:h-16 w-14 sm:w-16 items-center justify-center rounded-2xl bg-brand-soft mb-4">
                        <svg class="h-7 sm:h-8 w-7 sm:w-8 text-[#0055B6] dark:text-[#60A5FA]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" :d="iconPaths.teachers" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-slate-800 dark:text-white">No teachers to display yet</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        Teachers will appear here once added from the admin panel.
                    </p>
                </div>

            </div>
        </section>

        <!-- WHY CHOOSE US -->
        <section class="strength relative overflow-hidden px-4 sm:px-6 lg:px-8 py-16 sm:py-20 md:py-28 lg:py-32 xl:py-36">

            <svg class="wave wave-top" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,0 H1440 V38 C1200,86 960,8 720,44 C480,80 240,14 0,56 Z" />
            </svg>
            <svg class="wave wave-bottom" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,80 H1440 V42 C1200,0 960,72 720,36 C480,0 240,66 0,24 Z" />
            </svg>

            <div class="strength-dots"></div>
            <div class="strength-ring strength-ring-1"></div>
            <div class="strength-ring strength-ring-2"></div>

            <div class="relative mx-auto max-w-[1200px]">
                <div v-reveal class="mb-8 sm:mb-12 lg:mb-16 max-w-2xl">
                    <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 sm:px-4 py-1.5 text-[11px] sm:text-sm font-bold text-accent ring-1 ring-white/15">
                        <span class="h-2 w-2 rounded-full bg-accent"></span>
                        Our Strengths
                    </p>
                    <h2 class="mt-3 sm:mt-4 text-xl min-[400px]:text-2xl sm:text-4xl lg:text-5xl font-extrabold text-white leading-tight">
                        Why Choose {{ school?.name || 'Our School' }}?
                    </h2>
                    <p class="mt-3 sm:mt-4 text-sm sm:text-base text-white/85 leading-relaxed max-w-xl">
                        Everything we do is built to help every child feel safe, curious and confident.
                    </p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 sm:gap-10 lg:gap-12 items-center">

                    <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-3.5 sm:gap-4 lg:gap-5">
                        <div
                            v-for="(r, i) in reasons"
                            :key="r.title"
                            v-reveal="{ delay: i * 110 }"
                            class="reason-card group relative overflow-hidden rounded-2xl sm:rounded-3xl p-5 sm:p-6 lg:p-7"
                        >
                            <div class="flex h-12 sm:h-14 w-12 sm:w-14 items-center justify-center rounded-2xl bg-accent text-brand-dark shadow-lg shadow-black/20 transition duration-300 group-hover:scale-110 group-hover:rotate-6">
                                <svg class="h-6 sm:h-7 w-6 sm:w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="r.icon" />
                                </svg>
                            </div>
                            <h3 class="mt-4 sm:mt-5 text-sm sm:text-base lg:text-lg font-bold text-white leading-snug">{{ r.title }}</h3>
                            <p class="mt-1.5 sm:mt-2 text-xs sm:text-sm text-white/85 leading-relaxed">{{ r.text }}</p>
                            <span class="reason-line"></span>
                        </div>
                    </div>

                    <div v-reveal="{ type: 'right' }" class="lg:col-span-2">
                        <div class="relative mx-auto max-w-md lg:max-w-none">
                            <div class="absolute -right-3 sm:-right-4 -bottom-3 sm:-bottom-4 h-full w-full rounded-[2rem] border-2 border-accent"></div>
                            <div class="absolute -left-4 sm:-left-5 -top-4 sm:-top-5 h-16 sm:h-20 w-16 sm:w-20 rounded-full bg-accent opacity-90"></div>

                            <div class="relative overflow-hidden rounded-[2rem] shadow-2xl shadow-black/40 ring-1 ring-white/20">
                                <img
                                    src="https://images.unsplash.com/photo-1509062522246-3755977927d7?w=800&q=80"
                                    alt="Students"
                                    class="w-full h-60 sm:h-72 lg:h-96 xl:h-[26rem] object-cover transition-transform duration-700 hover:scale-105"
                                />
                            </div>

                            <div class="float-slow absolute -bottom-5 sm:-bottom-6 left-3 sm:left-4 lg:-left-6 flex items-center gap-2.5 sm:gap-3 rounded-2xl bg-white px-3.5 sm:px-4 py-2.5 sm:py-3 shadow-xl dark:bg-slate-800">
                                <span class="flex h-9 sm:h-11 w-9 sm:w-11 items-center justify-center rounded-xl bg-accent text-brand-dark">
                                    <svg class="h-5 sm:h-6 w-5 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPaths.teachers" /></svg>
                                </span>
                                <div>
                                    <p class="text-base sm:text-lg font-extrabold leading-none text-[#0B376B] dark:text-white">{{ props.stats?.students ?? 0 }}+</p>
                                    <p class="mt-1 text-[10px] sm:text-xs font-medium text-slate-600 dark:text-slate-300">Happy students</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- NOTICES + EVENTS (paginated) -->
        <section class="px-4 sm:px-6 lg:px-8 py-12 sm:py-16 md:py-20 lg:py-24 bg-white dark:bg-slate-950 transition-colors duration-300">
            <div class="mx-auto max-w-[1200px]">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 sm:gap-6 lg:gap-8">

                    <!-- ✅ Latest Notices -->
                    <div id="latest-notices-card" v-reveal="{ type: 'left' }" class="rounded-2xl sm:rounded-3xl bg-white dark:bg-slate-900 ring-1 ring-slate-100 dark:ring-slate-800 shadow-xl shadow-slate-200/60 dark:shadow-black/40 overflow-hidden flex flex-col">
                        <div class="flex items-center justify-between px-4 sm:px-5 lg:px-7 py-4 sm:py-5 gradient-brand">
                            <h3 class="flex items-center gap-2.5 sm:gap-3 text-base sm:text-lg lg:text-xl font-bold text-white">
                                <span class="flex h-9 sm:h-10 w-9 sm:w-10 items-center justify-center rounded-xl bg-white/15">
                                    <svg class="h-4 sm:h-5 w-4 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                    </svg>
                                </span>
                                Latest Notices
                            </h3>
                            <p v-if="noticesMeta.total" class="text-[10px] sm:text-xs font-semibold text-white/85 whitespace-nowrap">
                                {{ noticesMeta.from }}–{{ noticesMeta.to }} / {{ noticesMeta.total }}
                            </p>
                        </div>

                        <div class="divide-y divide-slate-100 dark:divide-slate-800 flex-1">
                            <template v-if="noticeList.length > 0">
                                <button
                                    v-for="notice in noticeList"
                                    :key="notice.id"
                                    type="button"
                                    class="group relative flex w-full items-start gap-3 sm:gap-4 px-4 sm:px-5 lg:px-7 py-3.5 sm:py-4 text-left transition hover:bg-tint cursor-pointer focus:outline-none focus-visible:bg-tint"
                                    @click="openNoticeModal(notice)"
                                >
                                    <span class="absolute left-0 top-0 h-full w-1 origin-top scale-y-0 bg-accent transition-transform duration-300 group-hover:scale-y-100"></span>
                                    <div class="flex h-10 sm:h-11 w-10 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-[#0055B6] dark:text-[#60A5FA] transition group-hover:scale-105">
                                        <svg class="h-4 sm:h-5 w-4 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-xs sm:text-sm font-bold text-[#0B376B] dark:text-white transition group-hover:text-[#0055B6] dark:group-hover:text-[#60A5FA]">
                                            {{ notice.title }}
                                        </p>
                                        <p v-if="notice.description" class="mt-0.5 line-clamp-1 text-[11px] sm:text-xs text-slate-600 dark:text-slate-300">
                                            {{ notice.description }}
                                        </p>
                                    </div>
                                    <p class="whitespace-nowrap pt-1 text-[10px] sm:text-xs font-medium text-slate-500 dark:text-slate-400">
                                        {{ formatDate(notice.notice_date) }}
                                    </p>
                                </button>
                            </template>

                            <template v-else>
                                <div v-for="n in 3" :key="n" class="flex items-start gap-3 px-4 sm:px-5 lg:px-7 py-3.5 sm:py-4">
                                    <div class="h-10 sm:h-11 w-10 sm:w-11 shrink-0 rounded-xl skeleton"></div>
                                    <div class="flex-1 space-y-2 pt-1">
                                        <div class="h-3 w-3/4 rounded skeleton"></div>
                                        <div class="h-2 w-1/2 rounded skeleton"></div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Pagination -->
                        <nav
                            v-if="noticesMeta.last_page > 1"
                            class="flex items-center justify-center gap-1 px-3 sm:px-5 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40"
                            aria-label="Notices pagination"
                        >
                            <button
                                type="button"
                                :disabled="!noticesMeta.prev_page_url"
                                class="inline-flex items-center justify-center h-8 w-8 rounded-full border-2 transition"
                                :class="noticesMeta.prev_page_url
                                    ? 'border-[#0055B6] text-[#0055B6] dark:border-[#60A5FA] dark:text-[#60A5FA] hover:bg-[#0055B6] hover:text-white cursor-pointer'
                                    : 'border-slate-200 dark:border-slate-700 text-slate-400 cursor-not-allowed opacity-60'"
                                aria-label="Previous notice page"
                                @click="goToNoticesPage(noticesMeta.current_page - 1)"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>

                            <template v-for="(p, idx) in noticesPaginationWindow" :key="idx">
                                <span v-if="p === '...'" class="inline-flex items-center justify-center h-8 px-1.5 text-xs font-bold text-slate-400 dark:text-slate-500 select-none">…</span>
                                <button
                                    v-else
                                    type="button"
                                    class="inline-flex items-center justify-center h-8 min-w-[32px] px-2 rounded-full text-xs font-bold border-2 transition cursor-pointer"
                                    :class="p === noticesMeta.current_page
                                        ? 'bg-[#0055B6] border-[#0055B6] text-white dark:bg-[#60A5FA] dark:border-[#60A5FA] dark:text-slate-900'
                                        : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:border-[#0055B6] dark:hover:border-[#60A5FA] hover:text-[#0055B6] dark:hover:text-[#60A5FA]'"
                                    :aria-current="p === noticesMeta.current_page ? 'page' : undefined"
                                    @click="goToNoticesPage(p)"
                                >
                                    {{ p }}
                                </button>
                            </template>

                            <button
                                type="button"
                                :disabled="!noticesMeta.next_page_url"
                                class="inline-flex items-center justify-center h-8 w-8 rounded-full border-2 transition"
                                :class="noticesMeta.next_page_url
                                    ? 'border-[#0055B6] text-[#0055B6] dark:border-[#60A5FA] dark:text-[#60A5FA] hover:bg-[#0055B6] hover:text-white cursor-pointer'
                                    : 'border-slate-200 dark:border-slate-700 text-slate-400 cursor-not-allowed opacity-60'"
                                aria-label="Next notice page"
                                @click="goToNoticesPage(noticesMeta.current_page + 1)"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </nav>
                    </div>

                    <!-- ✅ Upcoming Events -->
                    <div id="upcoming-events-card" v-reveal="{ type: 'right' }" class="rounded-2xl sm:rounded-3xl bg-white dark:bg-slate-900 ring-1 ring-slate-100 dark:ring-slate-800 shadow-xl shadow-slate-200/60 dark:shadow-black/40 overflow-hidden flex flex-col">
                        <div class="flex items-center justify-between px-4 sm:px-5 lg:px-7 py-4 sm:py-5 bg-accent">
                            <h3 class="flex items-center gap-2.5 sm:gap-3 text-base sm:text-lg lg:text-xl font-bold text-[#1f2937]">
                                <span class="flex h-9 sm:h-10 w-9 sm:w-10 items-center justify-center rounded-xl bg-white/50">
                                    <svg class="h-4 sm:h-5 w-4 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </span>
                                Upcoming Events
                            </h3>
                            <p v-if="eventsMeta.total" class="text-[10px] sm:text-xs font-semibold text-[#1f2937]/80 whitespace-nowrap">
                                {{ eventsMeta.from }}–{{ eventsMeta.to }} / {{ eventsMeta.total }}
                            </p>
                        </div>

                        <div class="divide-y divide-slate-100 dark:divide-slate-800 flex-1">
                            <template v-if="eventList.length > 0">
                                <div
                                    v-for="event in eventList"
                                    :key="event.id"
                                    class="group flex items-center gap-3 sm:gap-4 px-4 sm:px-5 lg:px-7 py-3.5 sm:py-4 transition hover:bg-tint"
                                >
                                    <button
                                        type="button"
                                        class="relative h-14 w-14 min-[400px]:h-16 min-[400px]:w-16 sm:h-20 sm:w-20 shrink-0 overflow-hidden rounded-2xl shadow-md ring-1 ring-black/5 transition duration-300 group-hover:scale-105 group-hover:-rotate-3 focus:outline-none focus-visible:ring-4 focus-visible:ring-[#F5B82E] cursor-pointer"
                                        :aria-label="`View details for ${event.title}`"
                                        @click="openEventModal(event)"
                                    >
                                        <template v-if="eventCover(event)">
                                            <img
                                                :src="eventCover(event)"
                                                :alt="event.title"
                                                loading="lazy"
                                                class="h-full w-full object-cover"
                                            />
                                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/0 to-black/0"></div>

                                            <span
                                                v-if="eventImageCount(event) > 1"
                                                class="absolute right-1 top-1 sm:right-1.5 sm:top-1.5 inline-flex items-center gap-0.5 sm:gap-1 rounded-full bg-black/65 px-1 sm:px-1.5 py-0.5 text-[8px] sm:text-[10px] font-bold text-white backdrop-blur-sm"
                                            >
                                                <svg class="h-2 w-2 sm:h-2.5 sm:w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                                {{ eventImageCount(event) }}
                                            </span>

                                            <div class="absolute left-0.5 top-0.5 sm:left-1.5 sm:top-1.5 flex flex-col items-center justify-center rounded-md bg-black/60 px-0.5 sm:px-1.5 py-0.5 text-white shadow-md backdrop-blur-sm">
                                                <span class="text-[8px] sm:text-[10px] font-extrabold leading-none">
                                                    {{ eventDay(event.event_date) }}
                                                </span>
                                                <span class="text-[6px] sm:text-[8px] font-bold uppercase tracking-wider text-accent leading-none mt-0.5">
                                                    {{ eventMonth(event.event_date) }}
                                                </span>
                                            </div>
                                        </template>

                                        <div
                                            v-else
                                            class="flex h-full w-full flex-col items-center justify-center gradient-brand text-white"
                                        >
                                            <span class="text-sm sm:text-xl font-extrabold leading-none">
                                                {{ eventDay(event.event_date) }}
                                            </span>
                                            <span class="mt-0.5 sm:mt-1 text-[8px] sm:text-[10px] font-bold uppercase tracking-wider text-accent">
                                                {{ eventMonth(event.event_date) }}
                                            </span>
                                        </div>
                                    </button>

                                    <button
                                        type="button"
                                        class="min-w-0 flex-1 text-left cursor-pointer focus:outline-none"
                                        @click="openEventModal(event)"
                                    >
                                        <p class="truncate text-xs sm:text-sm font-bold text-[#0B376B] dark:text-white transition group-hover:text-[#0055B6] dark:group-hover:text-[#60A5FA]">
                                            {{ event.title }}
                                        </p>
                                        <p v-if="event.location" class="mt-0.5 line-clamp-1 text-[11px] sm:text-xs text-slate-600 dark:text-slate-300">
                                            {{ event.location }}
                                            <span v-if="event.event_time">, {{ event.event_time }}</span>
                                        </p>
                                    </button>

                                    <svg class="hidden sm:block h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500 transition group-hover:translate-x-0.5 group-hover:text-[#0055B6] dark:group-hover:text-[#60A5FA]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                            </template>

                            <template v-else>
                                <div v-for="n in 3" :key="n" class="flex items-center gap-3 px-4 sm:px-5 lg:px-7 py-3.5 sm:py-4">
                                    <div class="h-14 sm:h-20 w-14 sm:w-20 shrink-0 rounded-2xl skeleton"></div>
                                    <div class="flex-1 space-y-2">
                                        <div class="h-3 w-3/4 rounded skeleton"></div>
                                        <div class="h-2 w-1/2 rounded skeleton"></div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Pagination -->
                        <nav
                            v-if="eventsMeta.last_page > 1"
                            class="flex items-center justify-center gap-1 px-3 sm:px-5 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40"
                            aria-label="Events pagination"
                        >
                            <button
                                type="button"
                                :disabled="!eventsMeta.prev_page_url"
                                class="inline-flex items-center justify-center h-8 w-8 rounded-full border-2 transition"
                                :class="eventsMeta.prev_page_url
                                    ? 'border-[#0055B6] text-[#0055B6] dark:border-[#60A5FA] dark:text-[#60A5FA] hover:bg-[#0055B6] hover:text-white cursor-pointer'
                                    : 'border-slate-200 dark:border-slate-700 text-slate-400 cursor-not-allowed opacity-60'"
                                aria-label="Previous event page"
                                @click="goToEventsPage(eventsMeta.current_page - 1)"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>

                            <template v-for="(p, idx) in eventsPaginationWindow" :key="idx">
                                <span v-if="p === '...'" class="inline-flex items-center justify-center h-8 px-1.5 text-xs font-bold text-slate-400 dark:text-slate-500 select-none">…</span>
                                <button
                                    v-else
                                    type="button"
                                    class="inline-flex items-center justify-center h-8 min-w-[32px] px-2 rounded-full text-xs font-bold border-2 transition cursor-pointer"
                                    :class="p === eventsMeta.current_page
                                        ? 'bg-[#0055B6] border-[#0055B6] text-white dark:bg-[#60A5FA] dark:border-[#60A5FA] dark:text-slate-900'
                                        : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:border-[#0055B6] dark:hover:border-[#60A5FA] hover:text-[#0055B6] dark:hover:text-[#60A5FA]'"
                                    :aria-current="p === eventsMeta.current_page ? 'page' : undefined"
                                    @click="goToEventsPage(p)"
                                >
                                    {{ p }}
                                </button>
                            </template>

                            <button
                                type="button"
                                :disabled="!eventsMeta.next_page_url"
                                class="inline-flex items-center justify-center h-8 w-8 rounded-full border-2 transition"
                                :class="eventsMeta.next_page_url
                                    ? 'border-[#0055B6] text-[#0055B6] dark:border-[#60A5FA] dark:text-[#60A5FA] hover:bg-[#0055B6] hover:text-white cursor-pointer'
                                    : 'border-slate-200 dark:border-slate-700 text-slate-400 cursor-not-allowed opacity-60'"
                                aria-label="Next event page"
                                @click="goToEventsPage(eventsMeta.current_page + 1)"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </nav>
                    </div>

                </div>
            </div>
        </section>

        <!-- GALLERY (paginated) -->
        <section id="gallery-card" class="px-4 sm:px-6 lg:px-8 pb-12 sm:pb-16 md:pb-20 lg:pb-24 bg-white dark:bg-slate-950 transition-colors duration-300">
            <div class="mx-auto max-w-[1200px]">
                <div v-reveal class="mb-6 sm:mb-8 lg:mb-10 flex flex-wrap items-end justify-between gap-3 sm:gap-4">
                    <div>
                        <p class="inline-flex items-center gap-2 rounded-full bg-brand-soft px-3 sm:px-4 py-1.5 text-[11px] sm:text-sm font-bold text-[#0055B6] dark:text-[#60A5FA]">
                            <span class="h-2 w-2 rounded-full bg-accent"></span>
                            Gallery
                        </p>
                        <h2 class="mt-3 sm:mt-4 text-xl min-[400px]:text-2xl sm:text-4xl lg:text-5xl font-extrabold text-[#0B376B] dark:text-white leading-tight">
                            Life at Our School
                        </h2>
                    </div>
                    <p v-if="galleryMeta.total" class="text-xs sm:text-sm font-semibold text-slate-500 dark:text-slate-400">
                        Showing {{ galleryMeta.from }}–{{ galleryMeta.to }} of {{ galleryMeta.total }} photos
                    </p>
                </div>

                <div
                    v-if="galleryImages.length > 0"
                    class="grid grid-cols-2 sm:grid-cols-4 grid-flow-dense auto-rows-[90px] min-[400px]:auto-rows-[100px] sm:auto-rows-[150px] lg:auto-rows-[190px] gap-2 sm:gap-3 lg:gap-4"
                >
                    <button
                        v-for="(img, i) in galleryImages"
                        :key="img.id"
                        type="button"
                        v-reveal="{ delay: i * 80, type: 'zoom' }"
                        :class="['group relative overflow-hidden rounded-2xl sm:rounded-3xl focus:outline-none focus-visible:ring-4 focus-visible:ring-[#F5B82E] cursor-pointer', img.layout]"
                        :aria-label="`Open gallery photo: ${img.title}`"
                        @click="openLightbox(i)"
                    >
                        <img
                            :src="img.src"
                            :alt="img.title"
                            loading="lazy"
                            class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-110"
                        />

                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent pt-8 pb-2 sm:pb-3 px-2 sm:px-3 text-left">
                            <p class="text-[10px] min-[400px]:text-xs sm:text-sm font-bold text-white truncate leading-tight drop-shadow-md">
                                {{ img.title }}
                            </p>
                        </div>

                        <div class="absolute inset-0 bg-gradient-to-t from-[#0B376B]/60 via-transparent to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100 pointer-events-none"></div>

                        <span class="absolute top-2 sm:top-3 right-2 sm:right-3 flex h-7 sm:h-10 w-7 sm:w-10 translate-y-3 items-center justify-center rounded-full bg-accent text-[#1f2937] opacity-0 shadow-lg transition duration-300 group-hover:translate-y-0 group-hover:opacity-100 pointer-events-none">
                            <svg class="h-3.5 sm:h-5 w-3.5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-4.35-4.35M11 8v6m-3-3h6m5 0a8 8 0 11-16 0 8 8 0 0116 0z" /></svg>
                        </span>
                    </button>
                </div>

                <!-- ✅ Backend Pagination -->
                <nav
                    v-if="galleryMeta.last_page > 1"
                    class="mt-8 sm:mt-10 flex flex-wrap items-center justify-center gap-1.5 sm:gap-2"
                    aria-label="Gallery pagination"
                >
                    <!-- Prev -->
                    <button
                        type="button"
                        :disabled="!galleryMeta.prev_page_url"
                        class="inline-flex items-center gap-1 rounded-full px-3 sm:px-4 py-2 text-xs sm:text-sm font-bold border-2 transition"
                        :class="galleryMeta.prev_page_url
                            ? 'border-[#0055B6] text-[#0055B6] dark:border-[#60A5FA] dark:text-[#60A5FA] hover:bg-[#0055B6] hover:text-white cursor-pointer'
                            : 'border-slate-200 dark:border-slate-700 text-slate-400 cursor-not-allowed opacity-60'"
                        @click="goToGalleryPage(galleryMeta.current_page - 1)"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                        </svg>
                        <span class="hidden xs:inline">Prev</span>
                    </button>

                    <!-- Page numbers -->
                    <template v-for="(p, idx) in galleryPaginationWindow" :key="idx">
                        <span
                            v-if="p === '...'"
                            class="min-w-[36px] sm:min-w-[40px] inline-flex items-center justify-center px-2.5 sm:px-3 py-2 text-xs sm:text-sm font-bold text-slate-400 dark:text-slate-500 select-none"
                        >…</span>
                        <button
                            v-else
                            type="button"
                            class="min-w-[36px] sm:min-w-[40px] inline-flex items-center justify-center rounded-full px-2.5 sm:px-3 py-2 text-xs sm:text-sm font-bold border-2 transition cursor-pointer"
                            :class="p === galleryMeta.current_page
                                ? 'bg-[#0055B6] border-[#0055B6] text-white dark:bg-[#60A5FA] dark:border-[#60A5FA] dark:text-slate-900'
                                : 'border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:border-[#0055B6] dark:hover:border-[#60A5FA] hover:text-[#0055B6] dark:hover:text-[#60A5FA]'"
                            :aria-current="p === galleryMeta.current_page ? 'page' : undefined"
                            @click="goToGalleryPage(p)"
                        >
                            {{ p }}
                        </button>
                    </template>

                    <!-- Next -->
                    <button
                        type="button"
                        :disabled="!galleryMeta.next_page_url"
                        class="inline-flex items-center gap-1 rounded-full px-3 sm:px-4 py-2 text-xs sm:text-sm font-bold border-2 transition"
                        :class="galleryMeta.next_page_url
                            ? 'border-[#0055B6] text-[#0055B6] dark:border-[#60A5FA] dark:text-[#60A5FA] hover:bg-[#0055B6] hover:text-white cursor-pointer'
                            : 'border-slate-200 dark:border-slate-700 text-slate-400 cursor-not-allowed opacity-60'"
                        @click="goToGalleryPage(galleryMeta.current_page + 1)"
                    >
                        <span class="hidden xs:inline">Next</span>
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </nav>

                <div v-else-if="galleryImages.length === 0" class="rounded-3xl bg-tint-2 dark:bg-slate-800 ring-1 ring-slate-100 dark:ring-slate-700 p-10 sm:p-14 text-center">
                    <div class="mx-auto flex h-14 sm:h-16 w-14 sm:w-16 items-center justify-center rounded-2xl bg-brand-soft mb-4">
                        <svg class="h-7 sm:h-8 w-7 sm:w-8 text-[#0055B6] dark:text-[#60A5FA]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-[#0B376B] dark:text-white">No gallery images yet</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        Images will appear here once added from the admin panel.
                    </p>
                </div>
            </div>
        </section>

        <!-- Gallery Lightbox -->
        <Transition name="fade">
            <div
                v-if="lightbox !== null && galleryImages.length > 0"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4"
                role="dialog"
                aria-modal="true"
                @click="onGalleryBackdropClick"
            >
                <button type="button" class="absolute right-3 sm:right-4 top-3 sm:top-4 flex h-10 sm:h-11 w-10 sm:w-11 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 hover:scale-110 cursor-pointer" aria-label="Close" @click.stop="closeLightbox">
                    <svg class="h-5 sm:h-6 w-5 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
                <button type="button" class="absolute left-2 sm:left-3 lg:left-6 flex h-10 sm:h-11 w-10 sm:w-11 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 hover:scale-110 cursor-pointer" aria-label="Previous photo" @click.stop="stepLightbox(-1)">
                    <svg class="h-5 sm:h-6 w-5 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                </button>
                <div class="flex flex-col items-center gap-3 max-w-full" @click.stop>
                    <img :src="galleryImages[lightbox]?.src" :alt="galleryImages[lightbox]?.title" class="max-h-[80vh] max-w-full rounded-2xl object-contain shadow-2xl" />
                    <p v-if="galleryImages[lightbox]?.title" class="text-sm sm:text-base font-bold text-white text-center px-4 max-w-2xl truncate">
                        {{ galleryImages[lightbox].title }}
                    </p>
                </div>
                <button type="button" class="absolute right-2 sm:right-3 lg:right-6 flex h-10 sm:h-11 w-10 sm:w-11 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 hover:scale-110 cursor-pointer" aria-label="Next photo" @click.stop="stepLightbox(1)">
                    <svg class="h-5 sm:h-6 w-5 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>
        </Transition>

        <!-- Notice Modal -->
        <Transition name="fade">
            <div
                v-if="noticeModal"
                class="fixed inset-0 z-[105] flex items-end sm:items-center justify-center bg-black/70 backdrop-blur-sm p-0 sm:p-4"
                role="dialog"
                aria-modal="true"
                @click="onNoticeBackdropClick"
            >
                <div
                    class="relative w-full sm:max-w-2xl max-h-[92vh] sm:max-h-[88vh] overflow-hidden rounded-t-3xl sm:rounded-3xl bg-white dark:bg-slate-900 shadow-2xl ring-1 ring-slate-200 dark:ring-slate-700 flex flex-col"
                    @click.stop
                >
                    <!-- Header -->
                    <div class="relative gradient-brand px-4 sm:px-6 lg:px-8 pt-5 sm:pt-6 pb-5 sm:pb-6 text-white shrink-0">
                        <button
                            type="button"
                            class="absolute right-3 sm:right-4 top-3 sm:top-4 flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25 hover:scale-110 cursor-pointer"
                            aria-label="Close notice"
                            @click="closeNoticeModal"
                        >
                            <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] sm:text-xs font-bold uppercase tracking-wide ring-1 ring-white/20">
                            <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
                            Notice
                        </span>

                        <h3 class="mt-2.5 sm:mt-3 pr-10 text-base sm:text-xl lg:text-2xl font-extrabold leading-snug">
                            {{ noticeModal.title }}
                        </h3>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] sm:text-xs text-white/85">
                            <span v-if="noticeModal.notice_date" class="inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                {{ formatDate(noticeModal.notice_date) }}
                            </span>
                            <span v-if="noticeModal.created_at" class="inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Published {{ formatDate(noticeModal.created_at) }}
                            </span>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 py-5 sm:py-6">
                        <div
                            v-if="noticeModal.description"
                            class="prose prose-sm sm:prose-base max-w-none text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line break-words"
                        >
                            {{ noticeModal.description }}
                        </div>
                        <p v-else class="text-sm text-slate-500 dark:text-slate-400 italic">
                            No description available for this notice.
                        </p>
                    </div>

                    <!-- Footer -->
                    <div class="shrink-0 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 px-4 sm:px-6 lg:px-8 py-3 sm:py-4 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-full bg-brand px-4 sm:px-6 py-2 sm:py-2.5 text-xs sm:text-sm font-bold text-white shadow-md shadow-[#0055B6]/20 transition hover:brightness-110 active:scale-95 cursor-pointer"
                            @click="closeNoticeModal"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Event Modal -->
        <Transition name="fade">
            <div
                v-if="eventModal"
                class="fixed inset-0 z-[105] flex items-end sm:items-center justify-center bg-black/70 backdrop-blur-sm p-0 sm:p-4"
                role="dialog"
                aria-modal="true"
                @click="onEventModalBackdropClick"
            >
                <div
                    class="relative w-full sm:max-w-2xl max-h-[92vh] sm:max-h-[88vh] overflow-hidden rounded-t-3xl sm:rounded-3xl bg-white dark:bg-slate-900 shadow-2xl ring-1 ring-slate-200 dark:ring-slate-700 flex flex-col"
                    @click.stop
                >
                    <!-- Header -->
                    <div class="relative bg-accent px-4 sm:px-6 lg:px-8 pt-5 sm:pt-6 pb-5 sm:pb-6 text-[#1f2937] shrink-0">
                        <button
                            type="button"
                            class="absolute right-3 sm:right-4 top-3 sm:top-4 flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-full bg-white/60 text-[#1f2937] transition hover:bg-white/90 hover:scale-110 cursor-pointer"
                            aria-label="Close event"
                            @click="closeEventModal"
                        >
                            <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        <span class="inline-flex items-center gap-2 rounded-full bg-white/60 px-3 py-1 text-[10px] sm:text-xs font-bold uppercase tracking-wide ring-1 ring-black/10">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#0055B6]"></span>
                            Event
                        </span>

                        <h3 class="mt-2.5 sm:mt-3 pr-10 text-base sm:text-xl lg:text-2xl font-extrabold leading-snug">
                            {{ eventModal.title }}
                        </h3>

                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] sm:text-xs text-[#1f2937]/80">
                            <span v-if="eventModal.event_date" class="inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                {{ formatDate(eventModal.event_date) }}
                            </span>
                            <span v-if="eventModal.event_time" class="inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {{ eventModal.event_time }}
                            </span>
                            <span v-if="eventModal.location" class="inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                {{ eventModal.location }}
                            </span>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 py-5 sm:py-6">
                        <div
                            v-if="eventModal.description"
                            class="prose prose-sm sm:prose-base max-w-none text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line break-words"
                        >
                            {{ eventModal.description }}
                        </div>
                        <p v-else class="text-sm text-slate-500 dark:text-slate-400 italic">
                            No description available for this event.
                        </p>

                        <!-- Event Images Gallery -->
                        <div v-if="eventImages(eventModal).length > 0" class="mt-5">
                            <p class="mb-2 text-xs sm:text-sm font-bold text-[#0B376B] dark:text-white">
                                Photos ({{ eventImages(eventModal).length }})
                            </p>
                            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                                <button
                                    v-for="(img, idx) in eventImages(eventModal)"
                                    :key="idx"
                                    type="button"
                                    class="relative aspect-square overflow-hidden rounded-xl ring-1 ring-black/5 group cursor-pointer focus:outline-none focus-visible:ring-4 focus-visible:ring-[#F5B82E]"
                                    :aria-label="`Open photo ${idx + 1}`"
                                    @click="openEventLightbox(eventModal, idx)"
                                >
                                    <img
                                        :src="img"
                                        :alt="eventModal.title"
                                        loading="lazy"
                                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-110"
                                    />
                                    <span class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/30 transition">
                                        <svg class="h-5 w-5 text-white opacity-0 group-hover:opacity-100 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-4.35-4.35M11 8v6m-3-3h6m5 0a8 8 0 11-16 0 8 8 0 0116 0z" />
                                        </svg>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="shrink-0 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/60 px-4 sm:px-6 lg:px-8 py-3 sm:py-4 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-full bg-brand px-4 sm:px-6 py-2 sm:py-2.5 text-xs sm:text-sm font-bold text-white shadow-md shadow-[#0055B6]/20 transition hover:brightness-110 active:scale-95 cursor-pointer"
                            @click="closeEventModal"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Event Lightbox -->
        <Transition name="fade">
            <div
                v-if="eventLightbox"
                class="fixed inset-0 z-[110] flex flex-col items-center justify-center bg-black/95 p-3 sm:p-6"
                role="dialog"
                aria-modal="true"
                @click="onEventBackdropClick"
            >
                <div class="relative flex w-full flex-1 items-center justify-center" @click.stop>
                    <button
                        v-if="eventLightbox.images.length > 1"
                        type="button"
                        class="absolute left-1 sm:left-4 top-1/2 -translate-y-1/2 z-20 flex h-10 sm:h-11 w-10 sm:w-11 items-center justify-center rounded-full bg-black/50 text-white backdrop-blur-sm transition hover:bg-black/70 hover:scale-110 cursor-pointer"
                        aria-label="Previous"
                        @click.stop="stepEventLightbox(-1)"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                    </button>

                    <div class="relative inline-block max-w-[92vw]">
                        <img
                            :key="eventLightbox.index"
                            :src="eventLightbox.images[eventLightbox.index]"
                            :alt="eventLightbox.title"
                            class="max-h-[68vh] sm:max-h-[72vh] max-w-[92vw] rounded-2xl object-contain shadow-2xl"
                        />

                        <button
                            type="button"
                            class="absolute -top-3 -right-3 sm:-top-4 sm:-right-4 z-30 flex h-9 w-9 sm:h-11 sm:w-11 items-center justify-center rounded-full bg-white text-slate-800 shadow-xl ring-1 ring-black/10 transition hover:bg-red-500 hover:text-white hover:scale-110 cursor-pointer"
                            aria-label="Close"
                            @click.stop="closeEventLightbox"
                        >
                            <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <button
                        v-if="eventLightbox.images.length > 1"
                        type="button"
                        class="absolute right-1 sm:right-4 top-1/2 -translate-y-1/2 z-20 flex h-10 sm:h-11 w-10 sm:w-11 items-center justify-center rounded-full bg-black/50 text-white backdrop-blur-sm transition hover:bg-black/70 hover:scale-110 cursor-pointer"
                        aria-label="Next"
                        @click.stop="stepEventLightbox(1)"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
                    </button>
                </div>

                <div class="relative mt-3 sm:mt-4 max-w-[92vw] text-center" @click.stop>
                    <p class="truncate text-sm sm:text-base font-bold text-white">{{ eventLightbox.title }}</p>
                    <p class="text-[11px] sm:text-xs text-white/80 mt-0.5">
                        {{ eventLightbox.index + 1 }} / {{ eventLightbox.images.length }}
                    </p>
                </div>

                <div
                    v-if="eventLightbox.images.length > 1"
                    class="mt-3 sm:mt-4 flex max-w-[92vw] gap-2 overflow-x-auto pb-1"
                    @click.stop
                >
                    <button
                        v-for="(thumb, ti) in eventLightbox.images"
                        :key="ti"
                        type="button"
                        class="h-12 w-12 min-[400px]:h-14 min-[400px]:w-14 sm:h-16 sm:w-16 shrink-0 overflow-hidden rounded-xl ring-2 transition cursor-pointer"
                        :class="ti === eventLightbox.index ? 'ring-[#F5B82E] opacity-100' : 'ring-white/20 opacity-60 hover:opacity-100'"
                        :aria-label="`Go to photo ${ti + 1}`"
                        @click.stop="setEventLightbox(ti)"
                    >
                        <img :src="thumb" alt="" class="h-full w-full object-cover" />
                    </button>
                </div>
            </div>
        </Transition>

        <!-- CALL TO ACTION -->
        <section class="px-4 sm:px-6 lg:px-8 pb-12 sm:pb-16 md:pb-20 lg:pb-24 bg-white dark:bg-slate-950">
            <div v-reveal="{ type: 'zoom' }" class="relative mx-auto max-w-[1200px] overflow-hidden rounded-2xl sm:rounded-[2rem] gradient-brand px-5 sm:px-8 lg:px-12 py-10 sm:py-12 lg:py-16 text-center">
                <div class="blob blob-b"></div>
                <div class="relative">
                    <h2 class="text-lg min-[400px]:text-xl sm:text-3xl lg:text-4xl font-extrabold text-white leading-tight">
                        Want your child to learn with us?
                    </h2>
                    <p class="mx-auto mt-2.5 sm:mt-3 max-w-xl text-sm sm:text-base text-white/90">
                        Visit our campus, meet our teachers and see how we help every child grow.
                    </p>
                    <div class="mt-6 sm:mt-8 flex flex-col sm:flex-row items-center justify-center gap-2.5 sm:gap-3">
                        <Link href="/contact" class="btn-shine inline-flex items-center justify-center rounded-full bg-accent px-6 sm:px-8 py-3 sm:py-3.5 text-sm sm:text-base font-bold text-[#1f2937] shadow-lg transition hover:brightness-105 active:scale-95 w-full sm:w-auto cursor-pointer">
                            Contact Us
                        </Link>
                        <Link href="/about" class="inline-flex items-center justify-center rounded-full border-2 border-white/60 px-6 sm:px-8 py-3 sm:py-3.5 text-sm sm:text-base font-bold text-white transition hover:bg-white/10 hover:border-white active:scale-95 w-full sm:w-auto cursor-pointer">
                            About Our School
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- ✅ FOOTER COMPONENT -->
        <PublicFooter />

    </div>
</template>

<style scoped>
.school-page {
    --c-brand:   var(--brand);
    --c-heading: var(--brand-dark);
    --c-tint:    var(--brand-tint);
    --c-tint-2:  var(--brand-tint);
    --c-soft:    color-mix(in srgb, var(--brand) 10%, transparent);
    --c-page:    #ffffff;
}
:global(.dark) .school-page {
    --c-brand:   var(--brand-light);
    --c-heading: #f1f5f9;
    --c-tint:    #0f172a;
    --c-tint-2:  #1e293b;
    --c-soft:    color-mix(in srgb, var(--brand-light) 15%, transparent);
    --c-page:    #020617;
}

.bg-brand        { background-color: var(--brand); }
.bg-brand-dark   { background-color: var(--brand-dark); }
.bg-accent       { background-color: var(--accent); }
.bg-tint         { background-color: var(--c-tint); }
.bg-tint-2       { background-color: var(--c-tint-2); }
.bg-brand-soft   { background-color: var(--c-soft); }

.text-brand      { color: var(--c-brand); }
.text-brand-fixed{ color: var(--brand); }
.text-brand-dark { color: var(--brand-dark); }
.text-navy       { color: var(--c-heading); }
.text-accent     { color: var(--accent); }

.border-accent   { border-color: var(--accent); }
.border-brand    { border-color: var(--c-brand); }

.hover\:bg-brand:hover      { background-color: var(--brand); }
.hover\:bg-accent:hover     { background-color: var(--accent); }
.hover\:bg-tint:hover       { background-color: color-mix(in srgb, var(--c-tint-2) 65%, transparent); }
.hover\:text-accent:hover   { color: var(--accent); }
.hover\:text-white:hover    { color: #fff; }
.group:hover .group-hover\:text-brand { color: var(--c-brand); }
.group:hover .group-hover\:bg-brand   { background-color: var(--brand); }
.group:hover .group-hover\:text-white { color: #fff; }

.group:hover .group-hover\:ring-brand\/30 { --tw-ring-color: color-mix(in srgb, var(--brand) 30%, transparent); }

.gradient-brand {
    background: linear-gradient(135deg, var(--brand-dark) 0%, var(--brand) 55%, var(--brand-mid) 100%);
}
.hero-overlay {
    background: linear-gradient(to right, color-mix(in srgb, var(--brand-dark) 95%, transparent), color-mix(in srgb, var(--brand) 60%, transparent), transparent);
}

.teacher-card {
    transform: translateZ(0);
    will-change: transform, box-shadow;
}
.teacher-card::after {
    content: '';
    position: absolute;
    inset-inline: 1.25rem;
    bottom: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, color-mix(in srgb, var(--brand) 25%, transparent), transparent);
    transition: opacity 0.4s ease;
}
.teacher-card:hover::after { opacity: 0; }

.teacher-action-btn {
    transform: translateZ(0);
}
.teacher-action-btn:hover {
    transform: translateY(-2px) scale(1.08);
    box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.35);
}

.strength {
    --s1: var(--brand-dark);
    --s2: color-mix(in srgb, var(--brand-dark) 60%, #000);
    background:
        radial-gradient(circle at 12% 18%, color-mix(in srgb, var(--brand-mid) 50%, transparent) 0, transparent 42%),
        radial-gradient(circle at 88% 85%, color-mix(in srgb, var(--accent) 22%, transparent) 0, transparent 38%),
        linear-gradient(160deg, var(--s1) 0%, var(--s2) 100%);
}
:global(.dark) .strength {
    --s1: color-mix(in srgb, var(--brand-dark) 65%, #000);
    --s2: #020617;
}

.wave {
    position: absolute;
    left: 0;
    width: 100%;
    height: 40px;
    display: block;
    pointer-events: none;
}
.wave-top    { top: -1px; }
.wave-bottom { bottom: -1px; }
.wave-top path    { fill: var(--c-tint); }
.wave-bottom path { fill: var(--c-page); }
@media (min-width: 640px) { .wave { height: 56px; } }
@media (min-width: 1024px) { .wave { height: 80px; } }

.strength-dots {
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1.2px, transparent 1.2px);
    background-size: 22px 22px;
    -webkit-mask-image: radial-gradient(ellipse at center, #000 25%, transparent 72%);
            mask-image: radial-gradient(ellipse at center, #000 25%, transparent 72%);
    pointer-events: none;
}
@media (min-width: 768px) {
    .strength-dots { background-size: 26px 26px; }
}

.strength-ring {
    position: absolute;
    border-radius: 9999px;
    border: 1.5px dashed rgba(255, 255, 255, 0.2);
    pointer-events: none;
    animation: spin 60s linear infinite;
}
.strength-ring-1 { top: -6rem; right: -6rem; width: 18rem; height: 18rem; }
.strength-ring-2 { bottom: -5rem; left: -5rem; width: 14rem; height: 14rem; animation-direction: reverse; animation-duration: 80s; }
@media (min-width: 640px) {
    .strength-ring-1 { top: -7rem; right: -7rem; width: 24rem; height: 24rem; }
    .strength-ring-2 { bottom: -6rem; left: -6rem; width: 18rem; height: 18rem; }
}
@media (min-width: 1024px) {
    .strength-ring-1 { top: -9rem; right: -9rem; width: 30rem; height: 30rem; }
    .strength-ring-2 { bottom: -7rem; left: -7rem; width: 22rem; height: 22rem; }
}
@keyframes spin { to { transform: rotate(360deg); } }

.reason-card {
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.13), rgba(255, 255, 255, 0.04));
    border: 1px solid rgba(255, 255, 255, 0.14);
    -webkit-backdrop-filter: blur(14px);
            backdrop-filter: blur(14px);
    box-shadow: 0 18px 40px -22px rgba(0, 0, 0, 0.6);
    transition: transform 0.3s ease, border-color 0.3s ease, background 0.3s ease;
}
.reason-card:hover {
    transform: translateY(-6px);
    border-color: color-mix(in srgb, var(--accent) 65%, transparent);
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0.06));
}
.reason-line {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 3px;
    width: 0;
    background: var(--accent);
    transition: width 0.45s ease;
}
.reason-card:hover .reason-line { width: 100%; }

.reveal {
    opacity: 0;
    transform: translateY(28px);
    transition: opacity 0.8s cubic-bezier(0.22, 1, 0.36, 1), transform 0.8s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.reveal-left  { transform: translateX(-40px); }
.reveal-right { transform: translateX(40px); }
.reveal-zoom  { transform: scale(0.92); }
.reveal.is-visible { opacity: 1; transform: none; }

@media (max-width: 1023px) {
    .reveal-left, .reveal-right { transform: translateY(28px); }
}

.anim-up {
    opacity: 0;
    animation: fadeUp 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(28px); }
    to   { opacity: 1; transform: translateY(0); }
}

.float-slow { animation: floatY 5s ease-in-out infinite; }
@keyframes floatY {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-10px); }
}

.blob {
    position: absolute;
    border-radius: 9999px;
    filter: blur(60px);
    pointer-events: none;
}
.blob-a { top: 4rem; right: -6rem; width: 22rem; height: 22rem; animation: drift 14s ease-in-out infinite; }
.blob-b { top: -4rem; left: -4rem; width: 20rem; height: 20rem; background: color-mix(in srgb, var(--brand-mid) 45%, transparent); animation: drift 16s ease-in-out infinite; }
@keyframes drift {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50%      { transform: translate(30px, -24px) scale(1.12); }
}

.btn-shine { position: relative; overflow: hidden; }
.btn-shine::after {
    content: '';
    position: absolute;
    top: 0; left: -80%;
    width: 50%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.45), transparent);
    transform: skewX(-20deg);
    transition: left 0.7s ease;
}
.btn-shine:hover::after { left: 130%; }

.skeleton {
    background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.6s infinite;
}
:global(.dark) .skeleton {
    background: linear-gradient(90deg, #1e293b 25%, #334155 50%, #1e293b 75%);
    background-size: 200% 100%;
}
@keyframes shimmer {
    from { background-position: 200% 0; }
    to   { background-position: -200% 0; }
}

.mvv-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
.mvv-card:hover { transform: translateY(-6px); box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.25); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (prefers-reduced-motion: reduce) {
    .reveal, .anim-up { opacity: 1 !important; transform: none !important; transition: none !important; animation: none !important; }
    .float-slow, .blob, .skeleton, .strength-ring { animation: none !important; }
}
</style>
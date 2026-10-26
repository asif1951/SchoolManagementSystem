<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import PublicNavbar from '@/Components/PublicNavbar.vue';
import PublicFooter from '@/Components/PublicFooter.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';

const page = usePage();

const showingNavigationDropdown = ref(false);

const user = computed(() => page.props.auth?.user);

// ✅ Admin check
const isAdmin = computed(() => user.value?.role === 'admin');

// ✅ User check (student/parent)
const isUser = computed(() => user.value?.role === 'user');

// ============================================================
// ✅ THEME — dark/light toggle
// ============================================================
const { theme, toggleTheme, initTheme } = useTheme();

onMounted(() => {
    initTheme();
});

// ============================================================
// ✅ USER DROPDOWN (navbar) — nijer click-outside logic
// ============================================================
const userDropdownOpen = ref(false);
const userDropdownRef = ref(null);

const toggleUserDropdown = () => {
    userDropdownOpen.value = !userDropdownOpen.value;
};

const closeUserDropdown = () => {
    userDropdownOpen.value = false;
};

const handleClickOutside = (event) => {
    if (userDropdownRef.value && !userDropdownRef.value.contains(event.target)) {
        userDropdownOpen.value = false;
    }
};

const handleEscape = (event) => {
    if (event.key === 'Escape') {
        userDropdownOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleEscape);
});

// ============================================================
// ✅ SCHOOL DATA — backend theke ase (page.props.school)
// ============================================================
const school = computed(() => page.props.school ?? {});

const schoolName = computed(() => school.value?.name || 'School');

const schoolLogo = computed(() => {
    const logo = school.value?.logo;
    if (!logo) return null;
    if (logo.startsWith('http')) return logo;
    return `/storage/${logo.replace(/^\/+/, '')}`;
});

// ============================================================
// NAVIGATION
// ============================================================
const navigation = [
    { name: 'Dashboard', icon: 'dashboard', route: '/dashboard' },
    { name: 'Add Students', icon: 'students', route: '/students' },
    { name: 'Student Management', icon: 'student-management', route: '/studentmanagement' },
    { name: 'Student Result', icon: 'student-result', route: '/student-results' },
    {
        name: 'Classes',
        icon: 'classes',
        route: '/classes',
        children: [
            { name: 'Classes Management', icon: 'classes', route: '/classesmanagement' },
            { name: 'Create Class', icon: 'create', route: '/classes/create' },
        ]
    },
    { name: 'Users', icon: 'users', route: '/users' },
    { name: 'Attendance', icon: 'attendance', route: '/attendance' },
    { name: 'Gallery', icon: 'gallery', route: '/gallery' },
    { name: 'Result Input', icon: 'results', route: '/results' },
    {
        name: 'Fees',
        icon: 'fees',
        route: '/fees',
        children: [
            { name: 'Collect Fees ', icon: 'fees', route: '/fees' },
            { name: 'Create Fee', icon: 'create-fee', route: '/fees/create' },
        ]
    },
    {
        name: 'Website Management',
        icon: 'website',
        route: '/website-management',
        children: [
            { name: 'Website Settings', icon: 'website-settings', route: '/website-management/settings' },
            { name: 'Home Page', icon: 'home-page', route: '/website-management/home-page' },
            { name: 'About School', icon: 'about-school', route: '/website-management/about-school' },
            { name: 'Teachers & Staff', icon: 'teachers-staff', route: '/website-management/teachers-staff' },
            { name: 'Notices', icon: 'notices', route: '/website-management/notices' },
            { name: 'Events', icon: 'events', route: '/website-management/events' },
            { name: 'Contact Information', icon: 'contact-info', route: '/website-management/contact-info' },
            { name: 'Messages', icon: 'messages', route: '/website-management/messages' },
        ]
    },
];

// ============================================================
// ✅ ROLE-BASED FILTER
// - admin   → সব menu
// - teacher → কিছু hide
// - user    → কোনো sidebar menu নেই (sidebar পুরো hide)
// ============================================================
const filteredNavigation = computed(() => {
    if (isAdmin.value) {
        return navigation;
    }

    // ✅ user → empty menu
    if (isUser.value) {
        return [];
    }

    const hiddenForTeacher = ['Dashboard', 'Users', 'Website Management'];

    return navigation.filter(item => !hiddenForTeacher.includes(item.name));
});

// ============================================================
// ✅ EXPANDED MENUS — sessionStorage e save hoy
// ============================================================
const EXPANDED_KEY = 'sidebar:expandedMenus';
const SCROLL_KEY = 'sidebar:scrollTop';

const loadExpanded = () => {
    try {
        const raw = sessionStorage.getItem(EXPANDED_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch (e) {
        return {};
    }
};

const expandedMenus = ref(loadExpanded());

watch(expandedMenus, (value) => {
    try {
        sessionStorage.setItem(EXPANDED_KEY, JSON.stringify(value));
    } catch (e) {
        // ignore storage errors
    }
}, { deep: true });

const toggleMenu = (menuName) => {
    expandedMenus.value[menuName] = !expandedMenus.value[menuName];
};

const isMenuExpanded = (menuName) => {
    return expandedMenus.value[menuName] || false;
};

// ✅ Sidebar scroll position preserve
const navScrollRef = ref(null);

const saveScroll = () => {
    try {
        if (navScrollRef.value) {
            sessionStorage.setItem(SCROLL_KEY, String(navScrollRef.value.scrollTop));
        }
    } catch (e) {
        // ignore storage errors
    }
};

onMounted(() => {
    try {
        const saved = sessionStorage.getItem(SCROLL_KEY);
        if (saved && navScrollRef.value) {
            navScrollRef.value.scrollTop = parseInt(saved, 10) || 0;
        }
    } catch (e) {
        // ignore storage errors
    }
});

// ============================================================
// ✅ ACTIVE MENU
// ============================================================
const currentRoute = computed(() => {
    const url = page.url || window.location.pathname;
    return url;
});

const activeMenu = ref('Dashboard');

const normalizePath = (path) => {
    const clean = (path || '').split('?')[0].split('#')[0].replace(/\/+$/, '');
    return clean || '/';
};

const routeMatches = (path, route) => {
    if (!route || route === '/') return false;
    return path === route || path.startsWith(route + '/');
};

const updateActiveMenu = () => {
    const currentPath = normalizePath(currentRoute.value);

    const candidates = [];
    for (const item of navigation) {
        if (item.children) {
            for (const child of item.children) {
                candidates.push({ name: child.name, route: child.route, parent: item.name });
            }
        }
        candidates.push({ name: item.name, route: item.route, parent: null });
    }

    candidates.sort((a, b) => b.route.length - a.route.length);

    const match = candidates.find(c => routeMatches(currentPath, c.route));

    if (match) {
        activeMenu.value = match.name;
        if (match.parent) {
            expandedMenus.value[match.parent] = true;
        }
    }
};

watch(currentRoute, () => {
    updateActiveMenu();
    closeUserDropdown();
}, { immediate: true });

const isParentActive = (item) => {
    return !!(item.children && item.children.some(child => activeMenu.value === child.name));
};

const isLeafActive = (item) => {
    return activeMenu.value === item.name && !item.children;
};

const selectMenu = (item) => {
    if (item.children) {
        toggleMenu(item.name);
        return;
    }

    activeMenu.value = item.name;
    showingNavigationDropdown.value = false;

    if (item.route) {
        router.visit(item.route);
    }
};

const selectChildMenu = (childItem) => {
    activeMenu.value = childItem.name;
    showingNavigationDropdown.value = false;

    if (childItem.route) {
        router.visit(childItem.route);
    }
};

const closeMobileMenu = () => {
    showingNavigationDropdown.value = false;
};
</script>

<template>
    <!-- ========================================================= -->
    <!-- ✅ USER LAYOUT — PublicNavbar + slot + PublicFooter       -->
    <!-- ========================================================= -->
    <div v-if="isUser" class="min-h-screen flex flex-col bg-slate-50 dark:bg-slate-950 transition-colors duration-300">
        <PublicNavbar />

        <main class="flex-1 w-full">
            <div class="mx-auto w-full max-w-[1600px]">
                <slot />
            </div>
        </main>

        <PublicFooter />
    </div>

    <!-- ========================================================= -->
    <!-- ✅ ADMIN / TEACHER LAYOUT — আগের মতোই                     -->
    <!-- ========================================================= -->
    <div v-else class="min-h-screen bg-slate-50 dark:bg-slate-950 transition-colors duration-300">

        <!-- MOBILE OVERLAY -->
        <div
            v-if="showingNavigationDropdown"
            class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden"
            @click="closeMobileMenu"
        ></div>

        <!-- SIDEBAR -->
        <aside
            class="
                fixed inset-y-0 left-0 z-50
                flex w-[280px] flex-col
                bg-gradient-to-b from-slate-950 via-slate-950 to-[#0a1224]
                text-white
                border-r border-white/5
                shadow-2xl
                transition-transform duration-300 ease-in-out
                lg:translate-x-0
            "
            :class="
                showingNavigationDropdown
                    ? 'translate-x-0'
                    : '-translate-x-full'
            "
        >

            <!-- SIDEBAR HEADER -->
            <div class="relative flex h-20 shrink-0 items-center justify-between border-b border-white/10 px-5 sm:px-6">
                <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-blue-400/60 to-transparent"></div>

                <div class="flex items-center gap-3 min-w-0">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white ring-1 ring-white/20 shadow-lg shadow-blue-600/30 overflow-hidden">
                        <img v-if="schoolLogo" :src="schoolLogo" :alt="schoolName" class="h-full w-full object-contain p-1" />
                        <ApplicationLogo v-else class="h-7 w-auto fill-current text-blue-600" />
                    </div>

                    <div class="min-w-0">
                        <h1 class="truncate text-base font-bold tracking-tight">
                            {{ schoolName }}
                        </h1>
                        <p class="mt-0.5 truncate text-[11px] font-medium uppercase tracking-wider text-slate-500">
                            Management System
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="rounded-lg p-2 text-slate-400 transition hover:bg-white/10 hover:text-white lg:hidden"
                    @click="closeMobileMenu"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- SIDEBAR NAVIGATION -->
            <div
                ref="navScrollRef"
                @scroll.passive="saveScroll"
                class="sidebar-scroll flex-1 overflow-y-auto px-3 py-5 sm:px-4 sm:py-6"
            >
                <div class="mb-4 flex items-center justify-between px-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">
                        Main Menu
                    </p>

                    <span class="rounded-md border border-blue-500/20 bg-blue-500/10 px-2 py-1 text-[9px] font-semibold text-blue-400 capitalize">
                        {{ isAdmin ? 'ADMIN' : (user?.role ?? 'USER') }}
                    </span>
                </div>

                <nav class="space-y-1">
                    <template v-for="item in filteredNavigation" :key="item.name">

                        <!-- Parent Menu Item -->
                        <button
                            type="button"
                            @click="selectMenu(item)"
                            class="group relative flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm font-medium transition-all duration-200"
                            :class="
                                isLeafActive(item)
                                    ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-900/30'
                                    : isParentActive(item)
                                        ? 'bg-white/[0.07] text-white'
                                        : 'text-slate-400 hover:bg-white/5 hover:text-white'
                            "
                        >
                            <span
                                class="absolute -left-3 top-1/2 h-6 w-1 -translate-y-1/2 rounded-r-full bg-blue-400 transition-all duration-300 sm:-left-4"
                                :class="(isLeafActive(item) || isParentActive(item)) ? 'opacity-100' : 'opacity-0'"
                            ></span>

                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors duration-200"
                                    :class="
                                        isLeafActive(item)
                                            ? 'bg-white/20 text-white'
                                            : isParentActive(item)
                                                ? 'bg-blue-500/20 text-blue-300'
                                                : 'bg-white/5 text-slate-400 group-hover:bg-white/10 group-hover:text-white'
                                    "
                                >
                                    <svg v-if="item.icon === 'dashboard'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'students'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'student-management'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'student-result'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'users'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m7-8a4 4 0 100-8 4 4 0 000 8zm9 8v-2a4 4 0 00-3-3.87m-1-8a4 4 0 010 7.75" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'classes'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 014 17V5a2 2 0 012-2h14v14H6.5A2.5 2.5 0 004 19.5z" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'attendance'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'gallery'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'exams'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a3 3 0 006 0M9 5h6m-6 8h6m-6 4h4" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'results'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 19V9m7 10V5m7 14v-7M3 19h18" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'fees'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4 1.343 4 3-1.79 3-4 3m0-12V5m0 14v-2M5 12h14" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'reports'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 19V5a2 2 0 012-2h12a2 2 0 012 2v14M8 17v-5m4 5V8m4 9v-7" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'website'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'create'" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4" />
                                    </svg>
                                </span>

                                <span class="truncate">
                                    {{ item.name }}
                                </span>
                            </div>

                            <svg
                                v-if="item.children"
                                class="h-4 w-4 shrink-0 transition-transform duration-300"
                                :class="[
                                    isMenuExpanded(item.name) ? 'rotate-180' : '',
                                    isParentActive(item) ? 'text-blue-300' : 'text-slate-500 group-hover:text-slate-300'
                                ]"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Child Menu Items -->
                        <div
                            v-if="item.children"
                            class="grid transition-[grid-template-rows,opacity] duration-300 ease-in-out"
                            :class="isMenuExpanded(item.name) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
                        >
                            <div class="overflow-hidden">
                                <div class="ml-[1.35rem] mt-1 space-y-0.5 border-l border-white/10 pb-1 pl-3">
                                    <button
                                        v-for="child in item.children"
                                        :key="child.name"
                                        type="button"
                                        @click="selectChildMenu(child)"
                                        class="group/child relative flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition-all duration-200"
                                        :class="
                                            activeMenu === child.name
                                                ? 'bg-blue-500/15 font-medium text-blue-300'
                                                : 'text-slate-400 hover:bg-white/5 hover:text-white'
                                        "
                                    >
                                        <span
                                            class="absolute -left-[1.04rem] top-1/2 h-1.5 w-1.5 -translate-y-1/2 rounded-full ring-2 ring-slate-950 transition-colors duration-200"
                                            :class="activeMenu === child.name ? 'bg-blue-400' : 'bg-slate-600 group-hover/child:bg-slate-400'"
                                        ></span>

                                        <svg v-if="child.icon === 'classes'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5A2.5 2.5 0 014 17V5a2 2 0 012-2h14v14H6.5A2.5 2.5 0 004 19.5z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'create'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'fees'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4 1.343 4 3-1.79 3-4 3m0-12V5m0 14v-2M5 12h14" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'create-fee'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'website-settings'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'home-page'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'about-school'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'teachers-staff'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-5a4 4 0 11-8 0 4 4 0 018 0zm-6 5a4 4 0 014-4" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'notices'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'events'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'gallery'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'contact-info'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                        </svg>
                                        <svg v-else-if="child.icon === 'messages'" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                        </svg>

                                        <span class="truncate text-[13px]">
                                            {{ child.name }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                    </template>
                </nav>
            </div>

            <!-- SIDEBAR USER -->
            <div class="border-t border-white/10 p-3 sm:p-4">
                <div class="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-3 transition-colors duration-200 hover:bg-white/[0.08]">
                    <div class="relative shrink-0">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white ring-2 ring-white/10">
                            {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-400 ring-2 ring-slate-950"></span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">
                            {{ user?.name ?? 'User' }}
                        </p>
                        <p class="truncate text-xs text-slate-500">
                            {{ user?.email ?? 'user@example.com' }}
                        </p>
                    </div>
                </div>
            </div>

        </aside>

        <!-- MAIN CONTENT -->
        <div class="lg:pl-[280px]">

            <!-- TOP NAVBAR -->
            <nav class="sticky top-0 z-30 border-b border-slate-200 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 shadow-sm backdrop-blur transition-colors duration-300">
                <div class="flex h-16 items-center justify-between px-4 sm:h-18 sm:px-6 lg:h-20 lg:px-8">

                    <!-- LEFT -->
                    <div class="flex min-w-0 items-center gap-3 sm:gap-4">
                        <button
                            type="button"
                            class="rounded-xl p-2.5 text-slate-500 dark:text-slate-400 transition hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-800 dark:hover:text-slate-100 lg:hidden"
                            @click="showingNavigationDropdown = !showingNavigationDropdown"
                        >
                            <svg v-if="!showingNavigationDropdown" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        <div class="flex items-center gap-2 lg:hidden">
                            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-white dark:bg-slate-800 sm:h-10 sm:w-10 overflow-hidden shadow-sm">
                                <img
                                    v-if="schoolLogo"
                                    :src="schoolLogo"
                                    :alt="schoolName"
                                    class="h-full w-full object-contain p-0.5"
                                />
                                <ApplicationLogo v-else class="h-5 w-auto fill-current text-blue-600 sm:h-6" />
                            </div>
                            <span class="hidden font-bold text-slate-800 dark:text-slate-100 sm:block truncate max-w-[150px]">
                                {{ schoolName }}
                            </span>
                        </div>

                        <div class="hidden min-w-0 lg:block">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 dark:text-slate-500">
                                Administration
                            </p>
                            <h2 class="mt-0.5 truncate text-lg font-bold text-slate-800 dark:text-slate-100">
                                {{ activeMenu }}
                            </h2>
                        </div>

                        <div class="hidden min-w-0 md:block lg:hidden">
                            <h2 class="truncate text-base font-bold text-slate-800 dark:text-slate-100">
                                {{ activeMenu }}
                            </h2>
                        </div>
                    </div>

                    <!-- RIGHT -->
                    <div class="flex shrink-0 items-center gap-1 sm:gap-2 lg:gap-4">

                        <!-- THEME TOGGLE -->
                        <button
                            type="button"
                            @click="toggleTheme"
                            :title="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                            :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                            class="relative inline-flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-amber-300 transition-all duration-300 hover:border-blue-400 dark:hover:border-amber-400 hover:bg-slate-50 dark:hover:bg-slate-700 active:scale-95"
                        >
                            <svg v-if="theme === 'dark'" class="h-4 w-4 sm:h-5 sm:w-5 transition-transform duration-300 hover:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <svg v-else class="h-4 w-4 sm:h-5 sm:w-5 transition-transform duration-300 hover:-rotate-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                            </svg>
                        </button>

                        <button type="button" class="relative rounded-xl p-2 text-slate-500 dark:text-slate-400 transition hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-800 dark:hover:text-slate-100 sm:p-2.5">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            <span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white dark:ring-slate-900 sm:right-2 sm:top-2"></span>
                        </button>

                        <div class="hidden h-8 w-px bg-slate-200 dark:bg-slate-700 sm:block"></div>

                        <!-- USER DROPDOWN -->
                        <div ref="userDropdownRef" class="relative">
                            <button
                                type="button"
                                @click.stop="toggleUserDropdown"
                                class="flex items-center gap-2 rounded-xl px-1.5 py-1 transition hover:bg-slate-50 dark:hover:bg-slate-800 focus:outline-none sm:gap-3 sm:px-2"
                            >
                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white shadow-md sm:h-10 sm:w-10">
                                    {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                                </div>

                                <div class="hidden text-left sm:block">
                                    <p class="max-w-[120px] truncate text-sm font-semibold text-slate-700 dark:text-slate-200 lg:max-w-[150px]">
                                        {{ user?.name ?? 'User' }}
                                    </p>
                                    <p class="text-xs capitalize text-slate-400 dark:text-slate-500">
                                        {{ user?.role ?? 'Administrator' }}
                                    </p>
                                </div>

                                <svg class="hidden h-4 w-4 text-slate-400 dark:text-slate-500 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <Transition
                                enter-active-class="transition ease-out duration-200"
                                enter-from-class="opacity-0 scale-95"
                                enter-to-class="opacity-100 scale-100"
                                leave-active-class="transition ease-in duration-75"
                                leave-from-class="opacity-100 scale-100"
                                leave-to-class="opacity-0 scale-95"
                            >
                                <div
                                    v-if="userDropdownOpen"
                                    class="absolute right-0 z-50 mt-2 w-56 origin-top-right overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 py-1 shadow-xl ring-1 ring-black ring-opacity-5 dark:ring-white/5"
                                    @click="closeUserDropdown"
                                >
                                    <div class="border-b border-slate-100 dark:border-slate-800 px-4 py-4">
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-100">
                                            {{ user?.name ?? 'User' }}
                                        </p>
                                        <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">
                                            {{ user?.email ?? '' }}
                                        </p>
                                    </div>

                                    <Link
                                        :href="route('profile.edit')"
                                        class="block w-full px-4 py-2 text-start text-sm font-medium leading-5 text-slate-700 dark:text-slate-200 transition duration-150 ease-in-out hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none"
                                    >
                                        Profile
                                    </Link>

                                    <Link
                                        :href="route('logout')"
                                        method="post"
                                        as="button"
                                        class="block w-full px-4 py-2 text-start text-sm font-medium leading-5 text-slate-700 dark:text-slate-200 transition duration-150 ease-in-out hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none"
                                    >
                                        Log Out
                                    </Link>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>

                <!-- MOBILE MENU -->
                <div v-if="showingNavigationDropdown" class="border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-lg lg:hidden">
                    <div class="max-h-[calc(100vh-4rem)] overflow-y-auto px-3 py-4 sm:px-5">
                        <div class="space-y-1">
                            <template v-for="item in filteredNavigation" :key="item.name">
                                <button
                                    type="button"
                                    @click="selectMenu(item)"
                                    class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-left text-sm font-medium transition"
                                    :class="
                                        activeMenu === item.name || (item.children && item.children.some(child => activeMenu === child.name))
                                            ? 'bg-blue-600 text-white'
                                            : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'
                                    "
                                >
                                    <span>{{ item.name }}</span>

                                    <svg
                                        v-if="item.children"
                                        class="h-4 w-4 transition-transform duration-200"
                                        :class="isMenuExpanded(item.name) ? 'rotate-180' : ''"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>

                                <div
                                    v-if="item.children && isMenuExpanded(item.name)"
                                    class="ml-4 space-y-0.5 border-l-2 border-slate-200 dark:border-slate-700 pl-3"
                                >
                                    <button
                                        v-for="child in item.children"
                                        :key="child.name"
                                        type="button"
                                        @click="selectChildMenu(child)"
                                        class="flex w-full items-center gap-2 rounded-lg px-4 py-2.5 text-left text-sm transition"
                                        :class="
                                            activeMenu === child.name
                                                ? 'bg-blue-600/10 text-blue-600 font-medium'
                                                : 'text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'
                                        "
                                    >
                                        <span>{{ child.name }}</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <div class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-4">
                            <div class="mb-3 px-3">
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-100">
                                    {{ user?.name ?? 'User' }}
                                </p>
                                <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ user?.email ?? '' }}
                                </p>
                            </div>

                            <ResponsiveNavLink :href="route('profile.edit')" @click="closeMobileMenu">
                                Profile
                            </ResponsiveNavLink>

                            <ResponsiveNavLink :href="route('logout')" method="post" as="button" @click="closeMobileMenu">
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- PAGE HEADER -->
            <header v-if="$slots.header" class="border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 transition-colors duration-300">
                <div class="mx-auto max-w-[1600px] px-4 py-5 sm:px-6 sm:py-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- PAGE CONTENT -->
            <main class="min-h-[calc(100vh-4rem)] bg-slate-50 dark:bg-slate-950 sm:min-h-[calc(100vh-4.5rem)] lg:min-h-[calc(100vh-5rem)] transition-colors duration-300">
                <div class="mx-auto w-full max-w-[1600px]">
                    <slot />
                </div>
            </main>

            <!-- FOOTER -->
            <footer class="border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-4 sm:px-6 lg:px-8 transition-colors duration-300">
                <div class="flex flex-col items-center justify-between gap-2 text-center sm:flex-row sm:text-left">
                    <p class="text-xs text-slate-400 dark:text-slate-500">
                        © {{ new Date().getFullYear() }} {{ schoolName }}
                    </p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">
                        Admin Dashboard
                    </p>
                </div>
            </footer>

        </div>
    </div>
</template>

<style scoped>
.sidebar-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.25) transparent;
}

.sidebar-scroll::-webkit-scrollbar {
    width: 6px;
}

.sidebar-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.sidebar-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.2);
    border-radius: 9999px;
}

.sidebar-scroll::-webkit-scrollbar-thumb:hover {
    background: rgba(148, 163, 184, 0.4);
}
</style>
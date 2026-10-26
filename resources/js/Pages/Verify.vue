<script setup>
import { Head } from '@inertiajs/vue3'

const props = defineProps({
    student: Object,
})

const getStatusBadge = (status) => {
    return status === 'active'
        ? 'bg-green-100 text-green-800 border-green-300'
        : 'bg-red-100 text-red-800 border-red-300'
}
</script>

<template>
    <Head :title="`Student Verification - ${student.student_name}`">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div class="min-h-screen bg-gradient-to-br from-emerald-50 via-green-50 to-teal-50 flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden ring-1 ring-emerald-900/10">

            <!-- Header -->
            <div class="relative bg-gradient-to-br from-emerald-700 via-green-800 to-emerald-950 px-6 pt-6 pb-16 text-center">
                <div class="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-amber-300 via-yellow-400 to-amber-300"></div>
                <div class="w-14 h-14 mx-auto rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/20 mb-3">
                    <svg class="w-7 h-7 text-amber-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
                <h1 class="text-white font-bold text-lg tracking-wider uppercase">Verified Student</h1>
                <p class="text-amber-200/80 text-xs tracking-[0.2em] uppercase mt-1">ChildHome Academy</p>
            </div>

            <!-- Photo -->
            <div class="flex justify-center -mt-14 relative z-10">
                <div class="w-28 h-28 rounded-full bg-gradient-to-br from-amber-200 via-yellow-300 to-amber-400 p-[3px] shadow-xl">
                    <div class="w-full h-full rounded-full bg-white p-[3px]">
                        <div class="w-full h-full rounded-full overflow-hidden bg-emerald-50">
                            <img
                                v-if="student.student_photo"
                                :src="'/storage/' + student.student_photo"
                                :alt="student.student_name"
                                class="w-full h-full object-cover"
                            />
                            <div v-else class="w-full h-full flex items-center justify-center text-emerald-700 font-bold text-4xl">
                                {{ student.student_name?.charAt(0)?.toUpperCase() }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Name -->
            <div class="text-center px-6 mt-4">
                <h2 class="text-xl font-bold text-emerald-950 uppercase tracking-wide">
                    {{ student.student_name }}
                </h2>
                <p class="text-sm text-emerald-700 font-semibold mt-1">
                    {{ student.class }} &middot; {{ student.section || 'N/A' }}
                </p>
                <span :class="['inline-block mt-2 px-3 py-1 rounded-full text-xs font-bold border', getStatusBadge(student.status)]">
                    {{ student.status?.toUpperCase() }}
                </span>
            </div>

            <!-- Info Grid -->
            <div class="px-6 py-6 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-100">
                        <p class="text-[9px] font-bold text-emerald-600 uppercase tracking-wider">Student ID</p>
                        <p class="text-xs font-bold text-emerald-950 mt-0.5">{{ student.student_id }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-blue-50 border border-blue-100">
                        <p class="text-[9px] font-bold text-blue-600 uppercase tracking-wider">Academic Year</p>
                        <p class="text-xs font-bold text-blue-950 mt-0.5">{{ student.academic_year }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-purple-50 border border-purple-100">
                        <p class="text-[9px] font-bold text-purple-600 uppercase tracking-wider">Date of Birth</p>
                        <p class="text-xs font-bold text-purple-950 mt-0.5">{{ student.date_of_birth || 'N/A' }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-red-50 border border-red-100">
                        <p class="text-[9px] font-bold text-red-600 uppercase tracking-wider">Blood Group</p>
                        <p class="text-xs font-bold text-red-950 mt-0.5">{{ student.blood_group || 'N/A' }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-orange-50 border border-orange-100">
                        <p class="text-[9px] font-bold text-orange-600 uppercase tracking-wider">Gender</p>
                        <p class="text-xs font-bold text-orange-950 mt-0.5 capitalize">{{ student.gender || 'N/A' }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-teal-50 border border-teal-100">
                        <p class="text-[9px] font-bold text-teal-600 uppercase tracking-wider">Admission</p>
                        <p class="text-xs font-bold text-teal-950 mt-0.5">{{ student.admission_date || 'N/A' }}</p>
                    </div>
                </div>

                <!-- Father Info -->
                <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Father's Name</p>
                    <p class="text-xs font-bold text-slate-800 mt-0.5">{{ student.father_name || 'N/A' }}</p>
                    <p class="text-xs text-slate-600 mt-1">📞 {{ student.father_phone || 'N/A' }}</p>
                </div>

                <!-- Verified Badge -->
                <div class="flex items-center justify-center gap-2 pt-3 border-t border-slate-200">
                    <div class="w-5 h-5 rounded-full bg-emerald-500 flex items-center justify-center">
                        <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <p class="text-xs font-semibold text-emerald-700">Verified by ChildHome Academy</p>
                </div>
            </div>

            <!-- Footer stripe -->
            <div class="h-[4px] bg-gradient-to-r from-emerald-800 via-green-800 to-emerald-950"></div>
            <div class="h-[2px] bg-gradient-to-r from-amber-300 via-yellow-400 to-amber-300"></div>
        </div>
    </div>
</template>
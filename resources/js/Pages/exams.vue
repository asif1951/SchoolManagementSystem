<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Exams" />

    <AuthenticatedLayout>
        <div class="flex min-h-[calc(100vh-5rem)] items-center justify-center">
            <h1 class="text-2xl font-bold text-slate-800">
                Welcome
            </h1>
        </div>
    </AuthenticatedLayout>
</template>
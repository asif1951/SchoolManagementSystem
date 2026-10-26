import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // ✅ Enable class-based dark mode — user toggle korle <html class="dark">
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // ✅ Custom screens
            screens: {
                'xs': '375px',        // small mobile — extra breakpoint
                ...defaultTheme.screens,   // sm, md, lg, xl, 2xl
            },
        },
    },

    plugins: [forms],
};
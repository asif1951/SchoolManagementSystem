import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * One accent for the whole system: a deep violet. Every decorative hue (blue, indigo, purple, sky …) is mapped to it,
 * so the older pages that were written with those names take the same colour without being edited one by one.
 * Status colours stay what they mean: green = done, amber = attention, red = problem.
 */
const brand = {
    50: '#f6f4fb',
    100: '#ece8f7',
    200: '#dcd4f0',
    300: '#c0b2e2',
    400: '#9f89d0',
    500: '#7d62b9',
    600: '#6340a0',
    700: '#50308a',   // the main violet: buttons, links, active items
    800: '#3f2670',
    900: '#2e1b55',   // the sidebar
    950: '#1c1033',
};

/** @type {import('tailwindcss').Config} */
export default {
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
            screens: {
                xs: '375px',
                ...defaultTheme.screens,
            },
            colors: {
                brand,
                blue: brand,
                indigo: brand,
                violet: brand,
                purple: brand,
                fuchsia: brand,
                sky: brand,
                cyan: brand,
            },
        },
    },

    plugins: [forms],
};

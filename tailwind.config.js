import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

// CFBTEL brand: navy #0E2238 and cyan #33C5F3. "indigo" is remapped to the brand scale so every existing
// indigo-* class in the app picks up the new theme; "gray" uses the cooler slate scale to sit with the navy.
const brand = {
    50: '#ECF9FE',
    100: '#D3F1FC',
    200: '#A8E3F9',
    300: '#70D2F6',
    400: '#33C5F3',
    500: '#12A8DE',
    600: '#0A84BA',
    700: '#086A96',
    800: '#0B5175',
    900: '#0F3A55',
    950: '#0E2238',
};

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    safelist: [
        'w-20',
        'h-20',
        'fill-current',
        'text-gray-500',
    ],

    theme: {
        extend: {
            colors: {
                brand,
                indigo: brand,
                gray: colors.slate,
            },
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};

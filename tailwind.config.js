/**
 * Tailwind configuration for PlotBird Analytics.
 *
 * The CSS is compiled ahead of time and committed as assets/css/tailwind.css.
 * Nothing is built on the server: shared hostings have no Node, and the Play
 * CDN would have shipped a 400 KB JavaScript compiler to every visitor.
 *
 * Rebuild after changing templates or classes:
 *   npm install && npm run build:css
 *
 * @type {import('tailwindcss').Config}
 */
module.exports = {
    darkMode: 'class',
    content: [
        './templates/**/*.php',
        './install/views/**/*.php',
        './assets/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            colors: {
                brand: {
                    50: '#f0f9ff',
                    100: '#e0f2fe',
                    200: '#bae6fd',
                    300: '#7dd3fc',
                    400: '#38bdf8',
                    500: '#0ea5e9',
                    600: '#0284c7',
                    700: '#0369a1',
                    800: '#075985',
                    900: '#0c4a6e',
                },
            },
        },
    },
    plugins: [],
};

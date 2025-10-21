/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./resources/**/*.blade.php",
        "./resources/**/*.vue",
        "./resources/**/*.js",
        "./resources/**/*.css",
    ],
    theme: {
        extend: {
            maxWidth: {
                // Теперь max-w-7xl будет 100% ширины
                '7xl': '100%',
            },
            spacing: {
                // Можно уменьшить стандартные отступы
                '6': '1rem',   // вместо 1.5rem
                '8': '1.25rem', // вместо 2rem
            },
            backgroundColor: {
                primary: '#068c39',
                secondary: '#f5ad3b'
            },
            colors: {
                primary: '#068c39',
                secondary: '#f5ad3b'
            }
        },
    },
    plugins: [],
}

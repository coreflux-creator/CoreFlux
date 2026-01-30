/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        'cf-navy': '#002c70',
        'cf-navy-dark': '#001d4d',
        'cf-accent': '#0066cc',
        'cf-accent-hover': '#0052a3',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}

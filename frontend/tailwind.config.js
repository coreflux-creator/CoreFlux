/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        // CoreFlux Brand Colors
        'cf-navy': '#0A2540',        // Core Navy - Primary brand color
        'cf-navy-dark': '#061829',   // Darker navy for hover states
        'cf-flux': '#007FFF',        // Flux Blue - Accent color
        'cf-flux-hover': '#0066CC',  // Flux Blue hover state
        'cf-soft': '#F5F7FA',        // Soft Gray - Background
        'cf-dark': '#3A3F45',        // Dark Gray - Body text
      },
      fontFamily: {
        'sans': ['Montserrat', 'Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#dc2626', // Red
          hover: '#b91c1c',
        },
        secondary: {
          DEFAULT: '#272262', // Dark Blue/Purple
          hover: '#1e1a4d',
        },
      }
    },
  },
  plugins: [],
}
